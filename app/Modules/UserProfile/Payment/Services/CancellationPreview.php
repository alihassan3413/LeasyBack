<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\FeeReason;
use App\Modules\UserProfile\Payment\Support\TuvAppointment;

/**
 * What cancelling this order right now would cost, decided by the same
 * precedence CancelOrderByCustomer applies.
 *
 * The confirmation dialog reads this rather than re-deriving the rule, which is
 * how it came to promise "no fee" to a customer whose accepted offer made one
 * due immediately.
 */
class CancellationPreview
{
    /**
     * @return array{fee_applies: bool, fee_amount_cents: int, fee_reason: ?string, message: string}
     */
    public function for(LeasybackOrder $order): array
    {
        return self::describe(
            LeasybackOffer::where('order_id', $order->id)->whereIn('offer_status', ['selected', 'closed'])->exists(),
            $order->request_payload,
        );
    }

    /**
     * The same answer from values a query-builder row already carries, so the
     * dashboard payload does not have to hydrate a model per order.
     *
     * @return array{fee_applies: bool, fee_amount_cents: int, fee_reason: ?string, message: string}
     */
    public static function describe(bool $hasAcceptedOffer, mixed $requestPayload): array
    {
        $reason = match (true) {
            $hasAcceptedOffer => FeeReason::RepairCancelledAfterAcceptance,
            TuvAppointment::isLateCancellation(TuvAppointment::fromPayload($requestPayload)) => FeeReason::TuvLateCancellation,
            default => null,
        };

        $amount = (int) config('payments.cancellation_fee_cents');

        return [
            'fee_applies' => $reason !== null,
            'fee_amount_cents' => $reason === null ? 0 : $amount,
            'fee_reason' => $reason?->value,
            'message' => self::message($reason, $amount),
        ];
    }

    /**
     * The single precedence, shared with CancelOrderByCustomer::reasonFor().
     */
    public static function reasonFor(LeasybackOrder $order): ?FeeReason
    {
        $accepted = LeasybackOffer::where('order_id', $order->id)
            ->whereIn('offer_status', ['selected', 'closed'])
            ->exists();

        if ($accepted) {
            return FeeReason::RepairCancelledAfterAcceptance;
        }

        if (TuvAppointment::isLateCancellation(TuvAppointment::for($order))) {
            return FeeReason::TuvLateCancellation;
        }

        return null;
    }

    private static function message(?FeeReason $reason, int $amountCents): string
    {
        $amount = number_format($amountCents / 100, 2, ',', '.').' €';

        return match ($reason) {
            FeeReason::RepairCancelledAfterAcceptance => sprintf(
                'Sie haben das Reparaturangebot bereits angenommen. Wenn Sie den Vorgang jetzt abbrechen, fällt eine Gebühr von %s an.',
                $amount,
            ),
            FeeReason::TuvLateCancellation => sprintf(
                'Ihr Termin liegt weniger als 48 Stunden in der Zukunft. Bei einer Stornierung fällt eine Gebühr von %s an.',
                $amount,
            ),
            default => 'Für diese Stornierung fallen keine Gebühren an.',
        };
    }
}
