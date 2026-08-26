<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Data\StripeSetupIntentResult;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use Illuminate\Support\Facades\Log;

/**
 * Handles verified Stripe events. Nothing here reads an authenticated user:
 * every expected identity is derived from the persisted order that the local
 * record resolves to, and checked by PaymentIdentityGuard.
 *
 * An event that resolves to nothing, or fails the guard, is ignored — the
 * caller still answers 200, because an error would put Stripe into a retry loop
 * over an event we will never accept.
 */
class StripeWebhookService
{
    public function __construct(
        private readonly PaymentMethodService $paymentMethods,
        private readonly PaymentIdentityGuard $identityGuard,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $object = (array) data_get($event, 'data.object', []);

        match ($type) {
            'setup_intent.succeeded' => $this->onSetupIntentSucceeded($object),
            'setup_intent.setup_failed' => $this->onSetupIntentFailed($object),
            'payment_method.detached' => $this->onPaymentMethodDetached($object),
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'payment_intent.requires_action',
            'payment_intent.processing',
            'payment_intent.canceled' => $this->onPaymentIntentEvent($type, $object),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function onPaymentIntentEvent(string $type, array $object): void
    {
        $paymentIntentId = (string) ($object['id'] ?? '');

        if ($paymentIntentId === '') {
            return;
        }

        $intent = OrderPaymentIntent::where('payment_intent_id', $paymentIntentId)->first();

        if ($intent === null) {
            Log::info('Ignoring a Stripe event for an unknown payment intent.', [
                'payment_intent_id' => $paymentIntentId,
                'type' => $type,
            ]);

            return;
        }

        $order = $intent->payment === null ? null : LeasybackOrder::find($intent->payment->order_id);

        if ($order === null) {
            return;
        }

        $permitted = $this->identityGuard->permits(
            $order,
            $this->idOf($object['customer'] ?? null),
            $this->metadataOf($object),
            $type,
        );

        if (! $permitted) {
            return;
        }

        $error = $object['last_payment_error'] ?? null;

        $observed = new StripePaymentIntentResult(
            id: $paymentIntentId,
            status: (string) ($object['status'] ?? ''),
            amount: (int) ($object['amount'] ?? 0),
            currency: (string) ($object['currency'] ?? 'eur'),
            customerId: $this->idOf($object['customer'] ?? null),
            paymentMethodId: $this->idOf($object['payment_method'] ?? null),
            failureCode: is_array($error) ? ($error['code'] ?? null) : null,
            failureMessage: is_array($error) ? ($error['message'] ?? null) : null,
            metadata: $this->metadataOf($object),
        );

        /*
         * `payment_intent.payment_failed` carries an object whose status is
         * `requires_payment_method` — Stripe's way of saying "re-confirm this
         * one" — so the event name, not the object status, is what identifies
         * a decline.
         */
        $forced = $type === 'payment_intent.payment_failed' ? PaymentStatus::Failed : null;

        $this->payments->applyIntentState($observed, $forced);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function onSetupIntentSucceeded(array $object): void
    {
        [$mandate, $order] = $this->resolveBySetupIntent($object);

        if ($mandate === null || $order === null) {
            return;
        }

        $paymentMethodId = $this->idOf($object['payment_method'] ?? null);

        if ($paymentMethodId === null) {
            return;
        }

        // Already applied — skip the extra retrievePaymentMethod call a replay
        // would otherwise make.
        if ($mandate->status === OrderPaymentMethod::STATUS_SAVED
            && $mandate->payment_method_id === $paymentMethodId
            && $mandate->verified_at !== null) {
            return;
        }

        $this->paymentMethods->applyVerifiedSetupIntent($order, new StripeSetupIntentResult(
            id: (string) $object['id'],
            status: (string) ($object['status'] ?? ''),
            customerId: $this->idOf($object['customer'] ?? null),
            paymentMethodId: $paymentMethodId,
            metadata: $this->metadataOf($object),
        ));
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function onSetupIntentFailed(array $object): void
    {
        [$mandate, $order] = $this->resolveBySetupIntent($object);

        if ($mandate === null || $order === null) {
            return;
        }

        // A failure must never undo a mandate that already succeeded — a
        // redelivered failure for a superseded attempt would otherwise strip a
        // working card.
        if ($mandate->status === OrderPaymentMethod::STATUS_SAVED) {
            return;
        }

        $mandate->forceFill([
            'status' => OrderPaymentMethod::STATUS_AWAITING_METHOD,
            'last_error' => (string) data_get($object, 'last_setup_error.message', 'Setup failed.'),
        ])->save();
    }

    /**
     * The only event resolved by payment_method_id: a detached PaymentMethod
     * carries no SetupIntent or PaymentIntent to look up. One saved card can
     * back several orders for the same customer, so this is a collection.
     *
     * @param  array<string, mixed>  $object
     */
    private function onPaymentMethodDetached(array $object): void
    {
        $paymentMethodId = (string) ($object['id'] ?? '');

        if ($paymentMethodId === '') {
            return;
        }

        $mandates = OrderPaymentMethod::where('payment_method_id', $paymentMethodId)->get();

        foreach ($mandates as $mandate) {
            $order = LeasybackOrder::find($mandate->order_id);

            if ($order === null) {
                continue;
            }

            $permitted = $this->identityGuard->permits(
                $order,
                $this->idOf($object['customer'] ?? null),
                $this->metadataOf($object),
                'payment_method.detached',
            );

            if (! $permitted) {
                continue;
            }

            $mandate->forceFill([
                'status' => OrderPaymentMethod::STATUS_AWAITING_METHOD,
                'payment_method_id' => null,
                'pm_brand' => null,
                'pm_last4' => null,
                'pm_exp_month' => null,
                'pm_exp_year' => null,
                'verified_at' => null,
                'offsession_authorized_at' => null,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{0: ?OrderPaymentMethod, 1: ?LeasybackOrder}
     */
    private function resolveBySetupIntent(array $object): array
    {
        $setupIntentId = (string) ($object['id'] ?? '');

        if ($setupIntentId === '') {
            return [null, null];
        }

        $mandate = OrderPaymentMethod::where('setup_intent_id', $setupIntentId)->first();

        if ($mandate === null) {
            Log::info('Ignoring a Stripe event for an unknown setup intent.', [
                'setup_intent_id' => $setupIntentId,
            ]);

            return [null, null];
        }

        return [$mandate, LeasybackOrder::find($mandate->order_id)];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, string>
     */
    private function metadataOf(array $object): array
    {
        return array_map(
            static fn ($value) => (string) $value,
            (array) ($object['metadata'] ?? []),
        );
    }

    private function idOf(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return is_array($value) && isset($value['id']) ? (string) $value['id'] : null;
    }
}
