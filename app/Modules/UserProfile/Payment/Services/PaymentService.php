<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\LeasybackOrder;
use App\Models\User;
use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Notifications\NotificationPayload;
use App\Services\Mail\OrderMailer;
use App\Services\Notifier;
use App\Support\OrderStatusLabel;
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
        if ($payment->purpose !== PaymentPurpose::Repair) {
            return;
        }

        $order = LeasybackOrder::find($payment->order_id);

        if ($order === null) {
            return;
        }

        if ($to->satisfiesReleaseGate()) {
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
        $admins = User::where('user_type', UserType::Admin->value)
            ->where('is_active', true)
            ->get();

        $this->notifier->sendNow(
            $admins,
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
     * The repair payment for an order, if one has been opened.
     */
    public function repairPaymentFor(string $orderId): ?OrderPayment
    {
        return OrderPayment::where('order_id', $orderId)
            ->where('purpose', PaymentPurpose::Repair->value)
            ->first();
    }

    public function authorizer(): PaymentAuthorizer
    {
        return $this->authorizer;
    }
}
