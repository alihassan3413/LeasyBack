<?php

namespace App\Modules\UserProfile\Payment\Actions;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Jobs\ChargeCancellationFee;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Services\PaymentService;
use App\Notifications\NotificationPayload;
use App\Services\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * A customer cancelling their own B2C order, and the €200 fee that follows.
 *
 * Deliberately *not* wired into TransitionOrderStatus. Admin cancels through
 * `admin.orders.status`, which reaches the same status by the same action — so
 * a fee levied there would fire on every internal cancellation, including ones
 * made on the customer's behalf. The fee belongs to the gesture, not to the
 * status, and this action is the only place that gesture exists.
 *
 * The order of the three steps is the whole design:
 *
 *   1. cancel the order — its own committed transaction;
 *   2. record the obligation — its own committed transaction;
 *   3. attempt collection — queued, so it runs after both have committed.
 *
 * Nothing Stripe does can reach back past step 1. A customer whose card has
 * expired, whose bank is down, or who never stored a card at all still ends up
 * with a cancelled order; what varies is only whether the fee is collected now
 * or chased later.
 */
class CancelOrderByCustomer
{
    public function __construct(
        private readonly TransitionOrderStatus $transitionOrderStatus,
        private readonly PaymentService $payments,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Cancel `$order` on the customer's behalf and open the fee.
     *
     * Idempotent in both halves: re-cancelling an already-cancelled order is a
     * no-op at the status layer, and the fee is found rather than created a
     * second time — so a double-submitted form cannot cost anybody €400.
     */
    public function __invoke(LeasybackOrder $order, User $actor, ?string $callerIp = null): OrderPayment
    {
        $wasOpen = ! in_array($order->order_status, OrderStatus::closedValues(), true);

        if ($wasOpen) {
            $this->transitionOrderStatus->__invoke(
                $order,
                OrderStatus::Cancelled->value,
                'user',
                (string) ($actor->name ?: $actor->email),
                $actor->id,
                $callerIp,
            );
        }

        [$fee, $isNew] = $this->openFee($order, $actor);

        if (! $isNew) {
            return $fee;
        }

        $this->notifyAdmins($order, $fee);

        /*
         * Only ever dispatched for a fee this call created, and only when a
         * usable mandate exists. Without one there is nothing to charge and no
         * PaymentIntent to fabricate: the obligation is simply outstanding.
         */
        if ($this->hasUsableMandate($order)) {
            ChargeCancellationFee::dispatch($fee->id);

            return $fee;
        }

        return $this->payments->transition($fee, PaymentStatus::RequiresManualCollection);
    }

    /**
     * Find or create the single cancellation-fee obligation for this order.
     *
     * `UNIQUE (order_id, purpose)` is what actually guarantees there is one, so
     * the race is handled by letting the second insert fail and re-reading —
     * a check-then-insert would leave a window two concurrent submissions could
     * both pass through.
     *
     * @return array{0: OrderPayment, 1: bool} The fee, and whether this call created it.
     */
    private function openFee(LeasybackOrder $order, User $actor): array
    {
        $existing = $this->payments->paymentFor($order->id, PaymentPurpose::CancellationFee);

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            return [OrderPayment::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'purpose' => PaymentPurpose::CancellationFee,
                'amount_cents' => (int) config('payments.cancellation_fee_cents'),
                'currency' => (string) config('services.stripe.currency', 'eur'),
                'created_by_user_id' => $actor->id,
            ]), true];
        } catch (UniqueConstraintViolationException $e) {
            // The index fired, so a concurrent request created it first and the
            // row exists by definition.
            return [
                $this->payments->paymentFor($order->id, PaymentPurpose::CancellationFee)
                    ?? throw $e,
                false,
            ];
        }
    }

    /**
     * Whether the fee can be taken without the customer present.
     *
     * Read straight off the mandate rather than from anything the request
     * carried: consent to off-session charging is evidence we recorded, not a
     * claim a caller gets to make.
     */
    private function hasUsableMandate(LeasybackOrder $order): bool
    {
        return OrderPaymentMethod::where('order_id', $order->id)->first()?->isChargeableOffSession() === true;
    }

    /**
     * Admin hears about it either way. A cancellation is not a status change
     * they can be expected to notice on a dashboard, and an uncollected fee is
     * work for a person.
     */
    private function notifyAdmins(LeasybackOrder $order, OrderPayment $fee): void
    {
        try {
            $admins = User::where('user_type', UserType::Admin->value)
                ->where('is_active', true)
                ->get();

            $this->notifier->sendNow(
                $admins,
                NotificationPayload::make(
                    NotificationType::OrderStatusChanged,
                    'Auftrag vom Kunden storniert',
                    sprintf('%s — Stornogebühr %s €', $order->auftragsnummer, $fee->amountDecimal()),
                    '/admin/orders/'.$order->id,
                    [
                        'auftragsnummer' => $order->auftragsnummer,
                        'status' => OrderStatus::Cancelled->value,
                        'cancellation_fee' => $fee->amountDecimal(),
                    ],
                ),
            );
        } catch (\Throwable $e) {
            // Best-effort, exactly as TransitionOrderStatus treats its own
            // notifications: the cancellation has committed, and a failed
            // notification must not present as a failed cancellation.
            Log::warning('Could not notify admins of a customer cancellation.', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
