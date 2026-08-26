<?php

namespace App\Modules\UserProfile\Payment\Data;

/**
 * What the browser needs to finish one repair payment attempt.
 *
 * `mode` exists because the two recoveries are genuinely different gestures,
 * not two labels for one form. An intent at `requires_action` already has a
 * card attached and needs only the customer's 3DS challenge completed against
 * that exact intent — showing a card field there would invite them to enter a
 * second card for a charge that is already authorized. An intent at
 * `requires_payment_method` was declined and needs a different card.
 *
 * The client secret is never persisted: it is read from Stripe when it is
 * handed out and lives no longer than the request.
 */
final readonly class RepairCheckoutSession
{
    /** Complete an outstanding 3DS challenge on this intent. No card field. */
    public const MODE_AUTHENTICATE = 'authenticate';

    /** Collect a card and confirm. */
    public const MODE_COLLECT = 'collect';

    public function __construct(
        public string $mode,
        public string $clientSecret,
        public string $paymentIntentId,
        public int $amountCents,
        public string $currency,
    ) {}

    /**
     * @return array{mode: string, client_secret: string, payment_intent_id: string, amount_cents: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'client_secret' => $this->clientSecret,
            'payment_intent_id' => $this->paymentIntentId,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
        ];
    }
}
