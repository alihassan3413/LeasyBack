<?php

namespace App\Modules\UserProfile\Payment\Contracts;

use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Data\StripePaymentMethodDetails;
use App\Modules\UserProfile\Payment\Data\StripeSetupIntentResult;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;

/**
 * Every call this application makes to Stripe, and the only place it is
 * allowed to make one.
 *
 * The seam exists for two reasons. Tests bind a fake and never touch the
 * network — mandatory here, because the alternative is a suite that fails when
 * Stripe is slow and passes when a card happens to work. And the retry policy
 * lives above this line rather than below it: the gateway does what it is told
 * and reports what happened, while *whether* to open a new intent or re-confirm
 * an existing one is a decision with local consequences (a new row, a bumped
 * counter) that belongs with the state machine.
 *
 * Implementations translate Stripe's exceptions into StripeGatewayException so
 * no caller has to catch an SDK type.
 */
interface StripeGateway
{
    /**
     * Create a Stripe Customer for a person who does not have one yet.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws StripeGatewayException
     */
    public function createCustomer(string $email, ?string $name = null, array $metadata = []): string;

    /**
     * Open a SetupIntent for storing a card without charging it.
     *
     * `usage: off_session` is what makes the resulting payment method reusable
     * when the customer is not present, which is the entire point of the step.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws StripeGatewayException
     */
    public function createSetupIntent(string $customerId, array $metadata = []): StripeSetupIntentResult;

    /**
     * Read a SetupIntent back from Stripe.
     *
     * This is the call that makes the confirmation step trustworthy: the
     * browser's report of success is a hint, and this is the fact.
     *
     * @throws StripeGatewayException
     */
    public function retrieveSetupIntent(string $setupIntentId): StripeSetupIntentResult;

    /**
     * The display fields for a stored card.
     *
     * @throws StripeGatewayException
     */
    public function retrievePaymentMethod(string $paymentMethodId): StripePaymentMethodDetails;

    /**
     * Create a PaymentIntent, optionally confirming it in the same request.
     *
     * `$offSession` true is the unattended charge; false is the customer
     * sitting in front of the pay page. The distinction reaches Stripe because
     * it changes how authentication is handled — an off-session charge that
     * needs 3DS comes back as `requires_action` rather than prompting.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws StripeGatewayException
     */
    public function createPaymentIntent(
        string $customerId,
        int $amountCents,
        string $currency,
        ?string $paymentMethodId,
        bool $confirm,
        bool $offSession,
        string $idempotencyKey,
        array $metadata = [],
    ): StripePaymentIntentResult;

    /**
     * Confirm an intent that already exists.
     *
     * This is the retry path. A declined intent sits at
     * `requires_payment_method` precisely so it can be confirmed again, and a
     * 3DS challenge belongs to one specific intent — so a retry that created a
     * second intent would either double-charge or strand the challenge.
     *
     * @throws StripeGatewayException
     */
    public function confirmPaymentIntent(
        string $paymentIntentId,
        ?string $paymentMethodId,
        bool $offSession,
        string $idempotencyKey,
    ): StripePaymentIntentResult;

    /**
     * @throws StripeGatewayException
     */
    public function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntentResult;

    /**
     * Abandon an intent, e.g. because the order was cancelled mid-charge.
     *
     * @throws StripeGatewayException
     */
    public function cancelPaymentIntent(string $paymentIntentId): StripePaymentIntentResult;

    /**
     * Verify a webhook's signature and decode it.
     *
     * Returns the event as a plain array. The signature check is the *only*
     * authentication a webhook has — there is no session and no user behind it
     * — so an implementation that cannot verify must throw rather than return
     * an unverified payload.
     *
     * @return array<string, mixed>
     *
     * @throws StripeGatewayException
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): array;
}
