<?php

namespace Tests\Support;

use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Data\StripePaymentMethodDetails;
use App\Modules\UserProfile\Payment\Data\StripeSetupIntentResult;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;

/**
 * An in-memory Stripe.
 *
 * The payment suite must never reach the network: a test that talks to Stripe
 * fails when Stripe is slow and passes when a test card happens to behave,
 * which makes it worse than no test. This holds the objects it is told to hold
 * and records what it was asked to do, so assertions can be made about the
 * *requests* — which intent was confirmed, with which idempotency key — and not
 * only about the results.
 */
class FakeStripeGateway implements StripeGateway
{
    /** @var array<string, StripeSetupIntentResult> */
    public array $setupIntents = [];

    /** @var array<string, StripePaymentIntentResult> */
    public array $paymentIntents = [];

    /** @var array<string, StripePaymentMethodDetails> */
    public array $paymentMethods = [];

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** Thrown by the next gateway call, then cleared. */
    public ?StripeGatewayException $nextFailure = null;

    /**
     * Results returned by createPaymentIntent(), consumed in order.
     *
     * A confirmed create otherwise always succeeds, which cannot express the
     * outcomes that matter most for an off-session charge: a decline, an
     * authentication challenge, or a payment still settling.
     *
     * @var list<StripePaymentIntentResult>
     */
    public array $createResults = [];

    /**
     * Results returned by confirmPaymentIntent(), consumed in order.
     *
     * Without this a confirmation can only ever succeed, which cannot express
     * the case that matters most here: confirming on-session moves an intent to
     * `requires_action` so the browser can run the challenge.
     *
     * @var list<StripePaymentIntentResult>
     */
    public array $confirmationResults = [];

    /** Verified webhook payload returned by constructWebhookEvent(). */
    public ?array $webhookEvent = null;

    public bool $webhookSignatureValid = true;

    private int $sequence = 0;

    public function createCustomer(string $email, ?string $name = null, array $metadata = []): string
    {
        $this->record('createCustomer', compact('email', 'name', 'metadata'));
        $this->maybeFail();

        return 'cus_fake'.(++$this->sequence);
    }

    public function createSetupIntent(string $customerId, array $metadata = []): StripeSetupIntentResult
    {
        $this->record('createSetupIntent', compact('customerId', 'metadata'));
        $this->maybeFail();

        $id = 'seti_fake'.(++$this->sequence);

        return $this->setupIntents[$id] = new StripeSetupIntentResult(
            id: $id,
            // Stripe's initial state. Only the browser's confirmSetup() moves
            // it to `succeeded`, which is why the server has to re-read it
            // rather than assume.
            status: 'requires_payment_method',
            clientSecret: $id.'_secret',
            customerId: $customerId,
            metadata: $metadata,
        );
    }

    public function retrieveSetupIntent(string $setupIntentId): StripeSetupIntentResult
    {
        $this->record('retrieveSetupIntent', compact('setupIntentId'));
        $this->maybeFail();

        return $this->setupIntents[$setupIntentId]
            ?? throw StripeGatewayException::apiError("No such setup intent: {$setupIntentId}");
    }

    public function retrievePaymentMethod(string $paymentMethodId): StripePaymentMethodDetails
    {
        $this->record('retrievePaymentMethod', compact('paymentMethodId'));
        $this->maybeFail();

        return $this->paymentMethods[$paymentMethodId] ?? new StripePaymentMethodDetails(
            id: $paymentMethodId,
            brand: 'visa',
            last4: '4242',
            expMonth: 12,
            expYear: (int) date('Y') + 2,
        );
    }

    public function createPaymentIntent(
        string $customerId,
        int $amountCents,
        string $currency,
        ?string $paymentMethodId,
        bool $confirm,
        bool $offSession,
        string $idempotencyKey,
        array $metadata = [],
    ): StripePaymentIntentResult {
        $this->record('createPaymentIntent', compact(
            'customerId', 'amountCents', 'currency', 'paymentMethodId',
            'confirm', 'offSession', 'idempotencyKey', 'metadata',
        ));
        $this->maybeFail();

        if ($this->createResults !== []) {
            $queued = array_shift($this->createResults);

            return $this->paymentIntents[$queued->id] = $queued;
        }

        $id = 'pi_fake'.(++$this->sequence);

        return $this->paymentIntents[$id] = new StripePaymentIntentResult(
            id: $id,
            status: $confirm
                ? OrderPaymentIntent::STRIPE_SUCCEEDED
                : OrderPaymentIntent::STRIPE_REQUIRES_CONFIRMATION,
            amount: $amountCents,
            currency: $currency,
            clientSecret: $id.'_secret',
            customerId: $customerId,
            paymentMethodId: $paymentMethodId,
            metadata: $metadata,
        );
    }

    public function confirmPaymentIntent(
        string $paymentIntentId,
        ?string $paymentMethodId,
        bool $offSession,
        string $idempotencyKey,
    ): StripePaymentIntentResult {
        $this->record('confirmPaymentIntent', compact(
            'paymentIntentId', 'paymentMethodId', 'offSession', 'idempotencyKey',
        ));
        $this->maybeFail();

        $existing = $this->paymentIntents[$paymentIntentId]
            ?? throw StripeGatewayException::apiError("No such payment intent: {$paymentIntentId}");

        if ($this->confirmationResults !== []) {
            return $this->paymentIntents[$paymentIntentId] = array_shift($this->confirmationResults);
        }

        return $this->paymentIntents[$paymentIntentId] = new StripePaymentIntentResult(
            id: $existing->id,
            status: OrderPaymentIntent::STRIPE_SUCCEEDED,
            amount: $existing->amount,
            currency: $existing->currency,
            clientSecret: $existing->clientSecret,
            customerId: $existing->customerId,
            paymentMethodId: $paymentMethodId ?? $existing->paymentMethodId,
            metadata: $existing->metadata,
        );
    }

    public function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntentResult
    {
        $this->record('retrievePaymentIntent', compact('paymentIntentId'));
        $this->maybeFail();

        return $this->paymentIntents[$paymentIntentId]
            ?? throw StripeGatewayException::apiError("No such payment intent: {$paymentIntentId}");
    }

    public function cancelPaymentIntent(string $paymentIntentId): StripePaymentIntentResult
    {
        $this->record('cancelPaymentIntent', compact('paymentIntentId'));
        $this->maybeFail();

        $existing = $this->paymentIntents[$paymentIntentId]
            ?? throw StripeGatewayException::apiError("No such payment intent: {$paymentIntentId}");

        return $this->paymentIntents[$paymentIntentId] = new StripePaymentIntentResult(
            id: $existing->id,
            status: OrderPaymentIntent::STRIPE_CANCELED,
            amount: $existing->amount,
            currency: $existing->currency,
            customerId: $existing->customerId,
            paymentMethodId: $existing->paymentMethodId,
            metadata: $existing->metadata,
        );
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): array
    {
        $this->record('constructWebhookEvent', compact('signatureHeader'));

        if (! $this->webhookSignatureValid) {
            throw StripeGatewayException::apiError('Invalid Stripe webhook signature.');
        }

        return $this->webhookEvent ?? json_decode($payload, true) ?? [];
    }

    // ---- test helpers -----------------------------------------------------

    /**
     * Put a SetupIntent into the state the browser would have left it in.
     */
    public function givenSucceededSetupIntent(
        string $id,
        string $customerId,
        string $paymentMethodId,
        array $metadata = [],
    ): StripeSetupIntentResult {
        return $this->setupIntents[$id] = new StripeSetupIntentResult(
            id: $id,
            status: 'succeeded',
            clientSecret: $id.'_secret',
            customerId: $customerId,
            paymentMethodId: $paymentMethodId,
            metadata: $metadata,
        );
    }

    public function givenSetupIntent(StripeSetupIntentResult $intent): StripeSetupIntentResult
    {
        return $this->setupIntents[$intent->id] = $intent;
    }

    public function givenPaymentIntent(StripePaymentIntentResult $intent): StripePaymentIntentResult
    {
        return $this->paymentIntents[$intent->id] = $intent;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call) => $call['method'] === $method,
        ));
    }

    public function countCallsTo(string $method): int
    {
        return count($this->callsTo($method));
    }

    public function lastCallTo(string $method): ?array
    {
        $calls = $this->callsTo($method);

        return $calls === [] ? null : $calls[array_key_last($calls)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function record(string $method, array $arguments): void
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];
    }

    private function maybeFail(): void
    {
        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }
    }
}
