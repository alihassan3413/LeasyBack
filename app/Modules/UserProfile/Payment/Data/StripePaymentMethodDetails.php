<?php

namespace App\Modules\UserProfile\Payment\Data;

/**
 * The display half of a saved card: enough to render "Visa ···· 4242, gültig
 * bis 12/2028" and nothing more.
 *
 * Deliberately not a full PaymentMethod. Nothing in this application needs the
 * rest, and a shape that cannot carry a PAN cannot leak one.
 */
final readonly class StripePaymentMethodDetails
{
    public function __construct(
        public string $id,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?int $expMonth = null,
        public ?int $expYear = null,
    ) {}
}
