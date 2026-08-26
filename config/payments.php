<?php

return [

    /*
    |--------------------------------------------------------------------------
    | B2C Payment Policy
    |--------------------------------------------------------------------------
    |
    | Product rules for the B2C payment flow, kept out of the code so none of
    | them is a literal at a call site. The Stripe credentials themselves live
    | in config/services.php under 'stripe', alongside the other third-party
    | integrations.
    |
    */

    /**
     * The flat cancellation fee, in minor units (cents), charged when a
     * customer cancels their own order.
     *
     * Minor units rather than a decimal string because this value only ever
     * travels to Stripe, which takes integers. Amounts that also have to be
     * displayed or summed — repair totals — stay decimal strings and are
     * converted at one boundary; see the offer domain and OfferPricingPolicy.
     */
    'cancellation_fee_cents' => (int) env('CANCELLATION_FEE_CENTS', 20000),

    /**
     * How many times a charge may be confirmed automatically, off-session,
     * before it will only advance through the manual flow or an explicit
     * Admin retry.
     *
     * Deliberately low. A declined card that is re-attempted automatically on
     * a loop attracts card-network penalties, and the customer has a payment
     * link either way — so the second attempt is worth much less than the
     * first, and the tenth is worth nothing at all.
     */
    'max_auto_confirmations' => (int) env('PAYMENT_MAX_AUTO_CONFIRMATIONS', 1),

    /**
     * Version stamp for the off-session authorization wording shown at the
     * payment-method step, recorded on every mandate.
     *
     * Bump this whenever that German text changes. Without it, a stored
     * consent says only "they agreed to something"; with it, the exact text a
     * given customer agreed to stays recoverable — which is the question a
     * dispute or a live-mode review actually asks.
     */
    'authorization_version' => env('PAYMENT_AUTHORIZATION_VERSION', '2026-08-v1'),

    /**
     * Version stamp for the booking-time cancellation-fee acknowledgement.
     * Separate from the above on purpose: different moment, different text,
     * and the two are independently revisable.
     */
    'fee_acknowledgement_version' => env('PAYMENT_FEE_ACK_VERSION', '2026-08-v1'),

    /**
     * How long a Stripe intent may sit in a non-terminal state before
     * `payments:reconcile` re-retrieves it. Covers a webhook that was missed,
     * misconfigured, or never delivered — without which a dropped event
     * leaves an order silently unpayable and its vehicle uncollectable.
     */
    'reconcile_after_minutes' => (int) env('PAYMENT_RECONCILE_AFTER_MINUTES', 15),

];
