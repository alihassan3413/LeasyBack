<?php

namespace App\Modules\UserProfile\Payment\Data;

/**
 * A SetupIntent as this application needs to see it.
 *
 * A local shape rather than the SDK's own object so the gateway seam is
 * genuinely a seam: tests construct these directly, and nothing outside
 * StripeClient has to know what a `\Stripe\SetupIntent` looks like.
 *
 * Every field the verification step checks is here, because that check is the
 * only thing standing between "a browser claimed this succeeded" and a stored
 * mandate.
 */
final readonly class StripeSetupIntentResult
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $clientSecret = null,
        public ?string $customerId = null,
        public ?string $paymentMethodId = null,
        public array $metadata = [],
    ) {}

    public function hasSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function metadataValue(string $key): ?string
    {
        return $this->metadata[$key] ?? null;
    }
}
