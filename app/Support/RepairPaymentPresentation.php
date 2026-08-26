<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;

/**
 * How an order's repair charge should be *presented*, derived rather than
 * stored.
 *
 * `delivered` is reached before the money arrives, on purpose: reaching it is
 * what triggers the charge. Read literally that status says "ready for
 * pickup", which is false for as long as the charge is outstanding — the
 * completion gate refuses it and the customer was being shown a pay-now banner
 * and "your vehicle can be collected" at the same time.
 *
 * So the presented stage is a function of the order status *and* the repair
 * payment, and lives here rather than in either frontend: Admin and the
 * customer render different wording from the same computed stage, which is
 * what stops the two contradicting each other. Nothing here is persisted and
 * no status transition consults it.
 */
final class RepairPaymentPresentation
{
    /** Nothing to present — no charge exists, or this is not a B2C order. */
    public const NONE = 'none';

    /** Owed and not moving on its own. Pickup is blocked. */
    public const AWAITING = 'awaiting_payment';

    /** Confirmed at Stripe and settling. Pickup is still blocked. */
    public const PROCESSING = 'payment_processing';

    /** Money arrived. Pickup is released. */
    public const SETTLED = 'payment_settled';

    /** The repair came to 0,00 €. Nothing was ever owed; pickup is released. */
    public const NOT_REQUIRED = 'payment_not_required';

    /**
     * @return array<string>
     */
    public static function stages(): array
    {
        return [self::NONE, self::AWAITING, self::PROCESSING, self::SETTLED, self::NOT_REQUIRED];
    }

    /**
     * The stage to present for one order.
     *
     * @param  string|null  $paymentStatus  An `order_payments.status` value, or null when no charge has been opened.
     */
    public static function stageFor(?string $orderStatus, ?string $paymentStatus, bool $isB2b = false): string
    {
        // B2B settles through `b2b_order_billing` and its own completion gate.
        // Its timeline has no payment rung and must not grow one.
        if ($isB2b || $paymentStatus === null) {
            return self::NONE;
        }

        // A cancelled or discarded order presents as cancelled everywhere; an
        // outstanding charge on it is an accounting matter, not a step the
        // customer is being asked to take.
        if (in_array($orderStatus, [OrderStatus::Cancelled->value, OrderStatus::Discarded->value], true)) {
            return self::NONE;
        }

        return match (PaymentStatus::tryFrom($paymentStatus)) {
            PaymentStatus::Paid => self::SETTLED,
            PaymentStatus::NotRequired => self::NOT_REQUIRED,
            PaymentStatus::Processing => self::PROCESSING,
            /*
             * Everything else — Pending, RequiresAction, Failed,
             * RequiresManualCollection, Cancelled, and any state added later —
             * is outstanding. Deliberately the default rather than a list: the
             * question this answers is "does the gate still refuse pickup",
             * and PaymentStatus::satisfiesReleaseGate() names exactly two
             * states that do not. A new state must fail closed.
             */
            default => self::AWAITING,
        };
    }

    /**
     * Whether this stage means the vehicle is still held.
     *
     * Mirrors PaymentStatus::satisfiesReleaseGate() rather than restating it:
     * a presented stage that said "ready" while the gate refused completion is
     * the exact contradiction this class exists to remove.
     */
    public static function blocksPickup(string $stage): bool
    {
        return $stage === self::AWAITING || $stage === self::PROCESSING;
    }
}
