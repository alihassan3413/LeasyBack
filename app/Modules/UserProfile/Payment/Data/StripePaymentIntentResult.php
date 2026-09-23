<?php

namespace App\Modules\UserProfile\Payment\Data;

use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use Carbon\CarbonImmutable;

/**
 * A PaymentIntent as this application needs to see it.
 *
 * `status` carries Stripe's own vocabulary unmapped. Translating it into a
 * local enum here would lose the distinction the retry policy depends on —
 * `requires_payment_method` (declined, re-confirm this same intent) versus
 * `canceled` (unusable, open a new one) — which is exactly the pair that is
 * easy to conflate.
 */
final readonly class StripePaymentIntentResult
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $clientSecret = null,
        public ?string $customerId = null,
        public ?string $paymentMethodId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $metadata = [],
        public ?CarbonImmutable $createdAt = null,
    ) {}

    public function hasSucceeded(): bool
    {
        return $this->status === OrderPaymentIntent::STRIPE_SUCCEEDED;
    }

    /**
     * Whether the customer has to finish something themselves — a 3DS
     * challenge, or supplying a card after a decline.
     */
    public function needsCustomerAction(): bool
    {
        return in_array($this->status, [
            OrderPaymentIntent::STRIPE_REQUIRES_ACTION,
            OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
        ], true);
    }

    public function metadataValue(string $key): ?string
    {
        return $this->metadata[$key] ?? null;
    }
}
