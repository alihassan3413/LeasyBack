<?php

namespace App\Modules\UserProfile\Payment\Enums;

/**
 * What an `order_payments` row is for.
 *
 * The two are independent obligations, not stages of one: an order can owe a
 * repair total, a cancellation fee, or (rarely, when a customer cancels after
 * their repair was already charged) both. They are separate rows with separate
 * Stripe intents so neither can settle or fail on behalf of the other.
 */
enum PaymentPurpose: string
{
    case Repair = 'repair';
    case CancellationFee = 'cancellation_fee';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Customer-facing German label, used in emails and the pay page.
     */
    public function label(): string
    {
        return match ($this) {
            self::Repair => 'Reparaturkosten',
            self::CancellationFee => 'Stornogebühr',
        };
    }

    /**
     * Whether an unpaid amount of this kind blocks the vehicle's release.
     *
     * Only the repair does. A cancellation fee is owed on an order that is
     * already terminal — there is no vehicle left to withhold, and holding one
     * hostage over an unpaid fee is not what the completion gate is for.
     */
    public function blocksVehicleRelease(): bool
    {
        return $this === self::Repair;
    }
}
