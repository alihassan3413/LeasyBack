<?php

namespace App\Modules\UserProfile\Payment\Actions;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\FeeReason;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Services\B2cFeeService;
use App\Modules\UserProfile\Payment\Services\CancellationPreview;
use App\Modules\UserProfile\Payment\Support\TuvAppointment;
use App\Notifications\NotificationPayload;
use App\Services\Notifier;
use Illuminate\Support\Facades\Log;

/**
 * A customer stopping their own B2C case.
 *
 * Two things are decided here and nothing else: which reason applies, and
 * whether the case can close now. A case that owes an unpaid fee stays open so
 * the customer can still settle it — CloseCaseAfterFee completes it once the
 * money arrives, from whichever observer sees it first.
 */
class CancelOrderByCustomer
{
    public function __construct(
        private readonly TransitionOrderStatus $transitionOrderStatus,
        private readonly B2cFeeService $fees,
        private readonly Notifier $notifier,
    ) {}

    public function __invoke(LeasybackOrder $order, User $actor, ?string $callerIp = null): ?OrderPayment
    {
        $reason = $this->reasonFor($order);

        if ($reason === null) {
            $this->cancel($order, $actor, $callerIp);

            return null;
        }

        $fee = $this->fees->trigger($order, $reason, $this->contextFor($order, $reason));

        // Nothing to collect after all — B2B, or a fee that came back null.
        if ($fee === null) {
            $this->cancel($order, $actor, $callerIp);

            return null;
        }

        // A settled fee has already closed the case through CloseCaseAfterFee.
        // An outstanding one leaves it open on purpose: `cancelled` is terminal,
        // and a terminal order can never become `completed` when the customer
        // pays. The obligation itself is what says the process has stopped.
        if (! $this->fees->isSettled($order)) {
            $this->notifyAdmins($order, $fee);
        }

        return $fee;
    }

    /**
     * Which trigger an explicit stop falls under, or null when it is free.
     *
     * An accepted offer outranks the notice period: the customer is calling off
     * a repair that LeasyBack has already committed to, which the specification
     * charges for immediately whatever the appointment says.
     */
    private function reasonFor(LeasybackOrder $order): ?FeeReason
    {
        return CancellationPreview::reasonFor($order);
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFor(LeasybackOrder $order, FeeReason $reason): array
    {
        if ($reason === FeeReason::RepairCancelledAfterAcceptance) {
            return [
                'offer_id' => $this->acceptedOffer($order)?->offer_id,
                'cancelled_at' => now()->toIso8601String(),
                'order_status' => $order->order_status,
            ];
        }

        return [
            'appointment_at' => TuvAppointment::for($order)?->toIso8601String(),
            'cancelled_at' => now()->toIso8601String(),
        ];
    }

    private function acceptedOffer(LeasybackOrder $order): ?LeasybackOffer
    {
        return LeasybackOffer::where('order_id', $order->id)
            ->whereIn('offer_status', ['selected', 'closed'])
            ->first();
    }

    private function cancel(LeasybackOrder $order, User $actor, ?string $callerIp): void
    {
        if (in_array($order->order_status, OrderStatus::closedValues(), true)) {
            return;
        }

        $this->transitionOrderStatus->__invoke(
            $order,
            OrderStatus::Cancelled->value,
            'user',
            (string) ($actor->name ?: $actor->email),
            $actor->id,
            $callerIp,
        );
    }

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
                    'Auftrag vom Kunden abgebrochen',
                    sprintf('%s — Gebühr %s € offen', $order->auftragsnummer, $fee->amountDecimal()),
                    '/admin/orders/'.$order->id,
                    [
                        'auftragsnummer' => $order->auftragsnummer,
                        'status' => $order->fresh()?->order_status,
                        'cancellation_fee' => $fee->amountDecimal(),
                        'fee_status' => $fee->fresh()?->status->value,
                    ],
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Could not notify admins of a customer cancellation.', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
