<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\LeasybackOrder;
use App\Models\User;
use App\Modules\UserProfile\Payment\Actions\CloseCaseAfterFee;
use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Notifications\NotificationPayload;
use App\Services\Mail\OrderMailer;
use App\Services\Notifier;
use App\Support\OrderStatusLabel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only writer of `order_payments.status`, and the only emitter of
 * payment-driven customer mail.
 *
 * Keyed on the logical payment rather than a Stripe intent because two
 * legitimate outcomes have no Stripe object at all — a repair that costs
 * nothing, and an amount owed with no usable mandate — and both still have to
 * notify and satisfy (or refuse) the pickup gate.
 */
class PaymentService
{
    public function __construct(
        private readonly OrderMailer $orderMailer,
        private readonly Notifier $notifier,
        private readonly PaymentAuthorizer $authorizer,
        private readonly VehicleScopeService $vehicleScope,
    ) {}

    /**
     * Move a payment to `$to`, recording `$intent` alongside it when the change
     * came from Stripe.
     *
     * @param  array<string, mixed>  $intentAttributes  Columns to write on the intent row.
     */
    public function transition(
        OrderPayment $payment,
        PaymentStatus $to,
        ?OrderPaymentIntent $intent = null,
        array $intentAttributes = [],
    ): OrderPayment {
        $notify = null;

        $fresh = DB::transaction(function () use ($payment, $to, $intent, $intentAttributes, &$notify) {
            /** @var OrderPayment $locked */
            $locked = OrderPayment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            // The intent row is written even when the payment itself refuses
            // the move: a late failure for a superseded attempt is a true fact
            // about that attempt, and losing it would erase the trail.
            if ($intent !== null && $intentAttributes !== []) {
                $intent->forceFill($intentAttributes)->save();
            }

            if (! $this->mayMoveTo($locked->status, $to)) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $to,
                'paid_at' => $to === PaymentStatus::Paid ? ($locked->paid_at ?? now()) : $locked->paid_at,
            ]);

            // `notified_status` is stamped in the same locked write that moves
            // `status`, so the charge job's synchronous result and the webhook
            // that follows it cannot both claim the notification.
            if ($locked->notified_status !== $to->value) {
                $locked->notified_status = $to->value;
                $notify = $to;
            }

            $locked->save();

            return $locked->fresh();
        });

        if ($notify !== null) {
            $this->notifyStatusChange($fresh, $notify);
        }

        return $fresh;
    }

    /**
     * Apply an observed Stripe intent state to whatever local payment it
     * belongs to. The webhook's entry point; resolution is by intent id only.
     */
    public function applyIntentState(StripePaymentIntentResult $observed, ?PaymentStatus $forcedStatus = null): ?OrderPayment
    {
        $intent = OrderPaymentIntent::where('payment_intent_id', $observed->id)->first();

        if ($intent === null) {
            Log::info('Ignoring a Stripe event for an unknown payment intent.', ['payment_intent_id' => $observed->id]);

            return null;
        }

        $payment = $intent->payment;

        if ($payment === null) {
            return null;
        }

        return $this->transition(
            $payment,
            $forcedStatus ?? self::statusForStripeIntent($observed->status),
            $intent,
            [
                'status' => $observed->status,
                'payment_method_id' => $observed->paymentMethodId ?? $intent->payment_method_id,
                'failure_code' => $observed->failureCode,
                'last_error' => $observed->failureMessage,
                'settled_at' => $observed->hasSucceeded() ? ($intent->settled_at ?? now()) : $intent->settled_at,
            ],
        );
    }

    /**
     * Stripe's intent vocabulary mapped onto ours.
     *
     * `requires_payment_method` means the charge was attempted and declined,
     * which is a failure — not, as the name suggests, an intent that has not
     * started.
     */
    public static function statusForStripeIntent(string $stripeStatus): PaymentStatus
    {
        return match ($stripeStatus) {
            OrderPaymentIntent::STRIPE_SUCCEEDED => PaymentStatus::Paid,
            OrderPaymentIntent::STRIPE_REQUIRES_ACTION => PaymentStatus::RequiresAction,
            OrderPaymentIntent::STRIPE_PROCESSING => PaymentStatus::Processing,
            OrderPaymentIntent::STRIPE_CANCELED => PaymentStatus::Cancelled,
            OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }

    /**
     * A terminal payment never moves again. This is what makes a redelivered
     * or out-of-order webhook safe: a stale `payment_failed` arriving after a
     * `succeeded` cannot un-pay a settled charge.
     */
    private function mayMoveTo(PaymentStatus $from, PaymentStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return ! $from->isTerminal();
    }

    private function notifyStatusChange(OrderPayment $payment, PaymentStatus $to): void
    {
        if ($payment->purpose === PaymentPurpose::CancellationFee) {
            if ($to === PaymentStatus::Paid) {
                app(CloseCaseAfterFee::class)($payment);
            }

            return;
        }

        if ($payment->purpose !== PaymentPurpose::Repair) {
            return;
        }

        $order = LeasybackOrder::find($payment->order_id);

        if ($order === null) {
            return;
        }

        if ($to->satisfiesReleaseGate()) {
            if ($to === PaymentStatus::Paid) {
                $this->orderMailer->repairPaymentReceived(
                    $order,
                    $order->vehicle,
                    LexwareInvoice::where('order_id', $order->id)->value('voucher_number'),
                );

                $this->notifyPickupReleased($order, $payment);

                return;
            }

            $this->orderMailer->vehicleReadyForPickup($order, $order->vehicle);

            return;
        }

        if ($to->needsCustomerAction()) {
            $this->notifyAdminsOfOutstandingPayment($order, $payment);
        }
    }

    /**
     * Admins only. The customer is shown the outstanding charge in the portal
     * — a banner and a pay action on the order — so this is the internal
     * signal rather than the customer's. A customer mail linking to that page
     * is worth adding and is deliberately not part of this increment.
     */
    private function notifyAdminsOfOutstandingPayment(LeasybackOrder $order, OrderPayment $payment): void
    {
        $this->notifier->sendNow(
            $this->activeAdmins(),
            NotificationPayload::make(
                NotificationType::PaymentActionRequired,
                'Reparaturzahlung offen',
                sprintf(
                    '%s: %s € — %s',
                    $order->auftragsnummer,
                    $payment->amountDecimal(),
                    $payment->status->label(),
                ),
                '/admin/orders/'.$order->id,
                [
                    'auftragsnummer' => $order->auftragsnummer,
                    'payment_status' => $payment->status->value,
                    'order_status' => OrderStatusLabel::for($order->order_status),
                ],
            ),
        );
    }

    /**
     * The moment the pickup gate opens on a charge that was actually held.
     *
     * Both sides are told, because both were waiting on the same event and
     * neither is looking at a page that knows it happened: the customer's mail
     * goes out either way, but the portal itself kept saying "Zahlung
     * erforderlich" until something reloaded it, and Admin's `confirm_pickup`
     * task stayed shut behind `repair_payment_blocks` with nothing announcing
     * that it had opened. Both notifications broadcast over Reverb, which is
     * what lets those two pages refresh themselves instead of being reloaded
     * by hand.
     *
     * `Paid` only, deliberately. A repair that comes to 0,00 € settles inside
     * the very `delivered` transition that opens it, and TransitionOrderStatus
     * announces that status change in the same breath — a second "abholbereit"
     * one line below the first is the same fact twice.
     */
    private function notifyPickupReleased(LeasybackOrder $order, OrderPayment $payment): void
    {
        $vehicle = $order->vehicle;

        if ($vehicle === null) {
            return;
        }

        $meta = [
            'auftragsnummer' => $order->auftragsnummer,
            'order_id' => $order->id,
            'vehicle_id' => $vehicle->vehicle_id,
            'payment_status' => $payment->status->value,
        ];

        $this->notifier->send(
            $this->vehicleScope->resolveOwnerUsers($vehicle),
            NotificationPayload::make(
                NotificationType::VehicleReadyForPickup,
                'Fahrzeug abholbereit',
                sprintf('%s: Die Reparaturkosten sind bezahlt. Ihr Fahrzeug kann abgeholt werden.', $vehicle->license_plate),
                '/dashboard',
                $meta,
            ),
        );

        $this->notifier->send(
            $this->activeAdmins(),
            NotificationPayload::make(
                NotificationType::VehicleReadyForPickup,
                'Zahlungseingang bestätigt',
                sprintf(
                    '%s (%s): Die Reparaturkosten sind bezahlt — die Abholung kann bestätigt werden.',
                    $order->auftragsnummer,
                    $vehicle->license_plate,
                ),
                '/admin/orders/'.$order->id,
                $meta,
            ),
        );
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function activeAdmins(): EloquentCollection
    {
        return User::where('user_type', UserType::Admin->value)
            ->where('is_active', true)
            ->get();
    }

    /**
     * One order's obligation of a given kind, if it has been opened.
     *
     * At most one can exist — `UNIQUE (order_id, purpose)` — so this needs no
     * ordering to be deterministic.
     */
    public function paymentFor(string $orderId, PaymentPurpose $purpose): ?OrderPayment
    {
        return OrderPayment::where('order_id', $orderId)
            ->where('purpose', $purpose->value)
            ->first();
    }

    /**
     * The repair payment for an order, if one has been opened.
     */
    public function repairPaymentFor(string $orderId): ?OrderPayment
    {
        return $this->paymentFor($orderId, PaymentPurpose::Repair);
    }

    public function authorizer(): PaymentAuthorizer
    {
        return $this->authorizer;
    }
}
