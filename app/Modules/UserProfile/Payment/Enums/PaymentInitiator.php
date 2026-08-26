<?php

namespace App\Modules\UserProfile\Payment\Enums;

/**
 * Who triggered a confirmation of a PaymentIntent.
 *
 * Recorded because the automatic-retry cap only applies to `System`. A person
 * choosing to try again — the customer on the pay page, or Admin from the order
 * screen — is not the behaviour that attracts card-network penalties, so it is
 * not the behaviour the cap exists to limit.
 */
enum PaymentInitiator: string
{
    /** Unattended, off-session, dispatched by the application. */
    case System = 'system';

    /** The customer, present, on the pay page. */
    case Customer = 'customer';

    /** Admin, acting from the order detail screen. */
    case Admin = 'admin';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether a confirmation by this initiator counts toward
     * `order_payments.auto_confirmation_count`.
     */
    public function countsTowardAutoCap(): bool
    {
        return $this === self::System;
    }
}
