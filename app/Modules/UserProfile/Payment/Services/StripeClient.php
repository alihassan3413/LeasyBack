<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Data\StripePaymentLinkResult;
use App\Modules\UserProfile\Payment\Data\StripePaymentMethodDetails;
use App\Modules\UserProfile\Payment\Data\StripeSetupIntentResult;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use Carbon\CarbonImmutable;
use Stripe\Exception\CardException;
use Stripe\Exception\ExceptionInterface as StripeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;
use Stripe\StripeClient as StripeSdkClient;
use Stripe\StripeObject;
use Stripe\Webhook;

/**
 * The only class in this application that talks to Stripe.
 *
 * Constructed from `services.stripe.secret` with no fallback: an unconfigured
 * environment fails loudly at construction rather than silently sending live
 * requests somewhere unexpected, matching how the DEKRA and TÜV SÜD
 * integrations treat their own secrets.
 */
class StripeClient implements StripeGateway
{
    private readonly StripeSdkClient $stripe;

    public function __construct(?StripeSdkClient $stripe = null)
    {
        if ($stripe !== null) {
            $this->stripe = $stripe;

            return;
        }

        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw StripeGatewayException::apiError(
                'STRIPE_SECRET is not configured — refusing to construct a Stripe client.',
            );
        }

        $this->stripe = new StripeSdkClient($secret);
    }

    public function createCustomer(string $email, ?string $name = null, array $metadata = []): string
    {
        return $this->guard(function () use ($email, $name, $metadata) {
            $customer = $this->stripe->customers->create(array_filter([
                'email' => $email,
                'name' => $name,
                'metadata' => $metadata,
            ]));

            return $customer->id;
        });
    }

    public function createSetupIntent(string $customerId, array $metadata = []): StripeSetupIntentResult
    {
        return $this->guard(function () use ($customerId, $metadata) {
            /*
             * No `confirm: true` and therefore no `mandate_data`: the browser
             * performs the confirmation via stripe.confirmSetup(), and Stripe
             * only accepts mandate_data on a confirmation request. The
             * customer's authorization is recorded on our side instead — see
             * OrderPaymentMethod's mandate columns — which is the evidence a
             * dispute actually asks for.
             *
             * `usage: off_session` is what makes the stored method reusable
             * when the customer is not present.
             */
            $intent = $this->stripe->setupIntents->create([
                'customer' => $customerId,
                'usage' => 'off_session',
                'payment_method_types' => ['card'],
                'metadata' => $metadata,
            ]);

            return $this->toSetupIntentResult($intent);
        });
    }

    public function retrieveSetupIntent(string $setupIntentId): StripeSetupIntentResult
    {
        return $this->guard(
            fn () => $this->toSetupIntentResult($this->stripe->setupIntents->retrieve($setupIntentId)),
        );
    }

    public function retrievePaymentMethod(string $paymentMethodId): StripePaymentMethodDetails
    {
        return $this->guard(function () use ($paymentMethodId) {
            $method = $this->stripe->paymentMethods->retrieve($paymentMethodId);
            $card = $method->card ?? null;

            return new StripePaymentMethodDetails(
                id: $method->id,
                brand: $card->brand ?? null,
                last4: $card->last4 ?? null,
                expMonth: isset($card->exp_month) ? (int) $card->exp_month : null,
                expYear: isset($card->exp_year) ? (int) $card->exp_year : null,
            );
        });
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
        return $this->guard(function () use (
            $customerId, $amountCents, $currency, $paymentMethodId,
            $confirm, $offSession, $idempotencyKey, $metadata
        ) {
            $params = [
                'customer' => $customerId,
                'amount' => $amountCents,
                'currency' => $currency,
                'metadata' => $metadata,
            ];

            if ($paymentMethodId !== null) {
                $params['payment_method'] = $paymentMethodId;
            }

            if ($confirm) {
                $params['confirm'] = true;
                $params['off_session'] = $offSession;
            }

            if (! $confirm) {
                /*
                 * The on-session path: the customer is on the pay page and
                 * will confirm from the browser. Automatic methods without
                 * redirects keeps everything inside the page — a redirect
                 * flow would leave the portal mid-payment.
                 */
                $params['automatic_payment_methods'] = [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ];
                unset($params['payment_method']);
            }

            $intent = $this->stripe->paymentIntents->create($params, [
                'idempotency_key' => $idempotencyKey,
            ]);

            return $this->toPaymentIntentResult($intent);
        });
    }

    public function confirmPaymentIntent(
        string $paymentIntentId,
        ?string $paymentMethodId,
        bool $offSession,
        string $idempotencyKey,
    ): StripePaymentIntentResult {
        return $this->guard(function () use ($paymentIntentId, $paymentMethodId, $offSession, $idempotencyKey) {
            $params = ['off_session' => $offSession];

            if ($paymentMethodId !== null) {
                $params['payment_method'] = $paymentMethodId;
            }

            $intent = $this->stripe->paymentIntents->confirm($paymentIntentId, $params, [
                'idempotency_key' => $idempotencyKey,
            ]);

            return $this->toPaymentIntentResult($intent);
        });
    }

    public function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntentResult
    {
        return $this->guard(
            fn () => $this->toPaymentIntentResult($this->stripe->paymentIntents->retrieve($paymentIntentId)),
        );
    }

    public function cancelPaymentIntent(string $paymentIntentId): StripePaymentIntentResult
    {
        return $this->guard(
            fn () => $this->toPaymentIntentResult($this->stripe->paymentIntents->cancel($paymentIntentId)),
        );
    }

    public function createPaymentLink(
        int $amountCents,
        string $currency,
        string $productName,
        string $idempotencyKey,
        array $metadata = [],
    ): StripePaymentLinkResult {
        return $this->guard(function () use ($amountCents, $currency, $productName, $idempotencyKey, $metadata) {
            $price = $this->stripe->prices->create([
                'currency' => $currency,
                'unit_amount' => $amountCents,
                'product_data' => ['name' => $productName],
            ], ['idempotency_key' => $idempotencyKey.':price']);

            $link = $this->stripe->paymentLinks->create([
                'line_items' => [[
                    'price' => $price->id,
                    'quantity' => 1,
                    'adjustable_quantity' => ['enabled' => false],
                ]],
                'allow_promotion_codes' => false,
                'metadata' => $metadata,
                'payment_intent_data' => ['metadata' => $metadata],
                'restrictions' => ['completed_sessions' => ['limit' => 1]],
            ], ['idempotency_key' => $idempotencyKey.':link']);

            return new StripePaymentLinkResult(
                id: $link->id,
                url: (string) $link->url,
                active: (bool) $link->active,
            );
        });
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): array
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            throw StripeGatewayException::apiError('STRIPE_WEBHOOK_SECRET is not configured.');
        }

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $secret);
        } catch (SignatureVerificationException $e) {
            throw StripeGatewayException::apiError('Invalid Stripe webhook signature.', $e);
        } catch (\UnexpectedValueException $e) {
            throw StripeGatewayException::apiError('Malformed Stripe webhook payload.', $e);
        }

        return $event->toArray();
    }

    /**
     * Translate the SDK's exceptions into ours.
     *
     * A CardException is separated out because it is not a fault: a declined
     * card is an ordinary outcome that belongs in the payment record with its
     * reason, whereas anything else means we could not reach Stripe at all.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (CardException $e) {
            throw StripeGatewayException::cardError(
                $e->getError()?->message ?? $e->getMessage(),
                $e->getError()?->code ?? $e->getStripeCode(),
                $e,
            );
        } catch (StripeException $e) {
            throw StripeGatewayException::apiError($e->getMessage(), $e);
        }
    }

    /**
     * @param  array<string, mixed>|StripeObject|null  $metadata
     * @return array<string, string>
     */
    private function normalizeMetadata(mixed $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        $asArray = is_object($metadata) && method_exists($metadata, 'toArray')
            ? $metadata->toArray()
            : (array) $metadata;

        return array_map(static fn ($value) => (string) $value, $asArray);
    }

    private function toSetupIntentResult(SetupIntent $intent): StripeSetupIntentResult
    {
        return new StripeSetupIntentResult(
            id: $intent->id,
            status: (string) $intent->status,
            clientSecret: $intent->client_secret,
            // Expandable fields come back either as an id string or as an
            // object, depending on how the request was made.
            customerId: $this->idOf($intent->customer),
            paymentMethodId: $this->idOf($intent->payment_method),
            metadata: $this->normalizeMetadata($intent->metadata ?? null),
        );
    }

    private function toPaymentIntentResult(PaymentIntent $intent): StripePaymentIntentResult
    {
        $error = $intent->last_payment_error ?? null;

        return new StripePaymentIntentResult(
            id: $intent->id,
            status: (string) $intent->status,
            amount: (int) $intent->amount,
            currency: (string) $intent->currency,
            clientSecret: $intent->client_secret,
            customerId: $this->idOf($intent->customer),
            paymentMethodId: $this->idOf($intent->payment_method),
            failureCode: $error->code ?? null,
            failureMessage: $error->message ?? null,
            metadata: $this->normalizeMetadata($intent->metadata ?? null),
            createdAt: isset($intent->created) ? CarbonImmutable::createFromTimestamp($intent->created) : null,
        );
    }

    /**
     * Stripe returns an expandable field as either a bare id or the expanded
     * object. Both mean the same thing here.
     */
    private function idOf(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return is_object($value) && isset($value->id) ? (string) $value->id : null;
    }
}
