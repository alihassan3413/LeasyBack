<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Data\StripeSetupIntentResult;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Storing a payment method as security for one order, and recording the
 * customer's authorization to charge it while they are not present.
 *
 * The one rule this class exists to enforce: a mandate is only ever marked
 * saved after the server has read the SetupIntent back from Stripe. The
 * browser's `stripe.confirmSetup()` result is a claim by an untrusted party
 * about an object it does not own, and treating it as fact would let anyone
 * mark any order's mandate saved by posting an id.
 */
class PaymentMethodService
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PaymentAuthorizer $authorizer,
        private readonly PaymentIdentityGuard $identityGuard,
    ) {}

    /**
     * Open (or re-open) a SetupIntent for this order and return its client
     * secret.
     *
     * An unconsumed SetupIntent is reused rather than replaced: a customer who
     * reloads the payment step should land back on the same intent, not
     * accumulate one per refresh.
     */
    public function startSetup(LeasybackOrder $order, User $user): StripeSetupIntentResult
    {
        $mandate = $this->mandateFor($order);
        $customerId = $this->ensureStripeCustomer($user);

        if ($mandate->setup_intent_id !== null) {
            $existing = $this->tryRetrieveSetupIntent($mandate->setup_intent_id);

            // Reusable only while it is still awaiting the customer. A
            // succeeded intent cannot be confirmed twice, and a canceled one
            // cannot be confirmed at all.
            if ($existing !== null && ! $existing->hasSucceeded() && $existing->clientSecret !== null) {
                return $existing;
            }
        }

        $intent = $this->stripe->createSetupIntent($customerId, [
            'order_id' => $order->id,
            'auftragsnummer' => (string) $order->auftragsnummer,
            'user_id' => (string) $user->id,
        ]);

        $mandate->forceFill([
            'stripe_customer_id' => $customerId,
            'setup_intent_id' => $intent->id,
            'updated_by_user_id' => $user->id,
        ])->save();

        return $intent;
    }

    /**
     * Verify a SetupIntent server-side and, only if every check passes, store
     * the payment method together with the off-session authorization.
     *
     * The two are written in one transaction on purpose: a verified card with
     * no recorded mandate is a card this application has no permission to use,
     * and a mandate with no card is meaningless. Neither may exist alone.
     *
     * @param  array{authorization_text: string, ip: ?string, user_agent: ?string}  $consent
     *
     * @throws HttpResponseException 422 on any failed check
     */
    public function confirmSetup(
        LeasybackOrder $order,
        User $user,
        string $setupIntentId,
        array $consent,
    ): OrderPaymentMethod {
        $intent = $this->tryRetrieveSetupIntent($setupIntentId)
            ?? $this->fail('Die Zahlungsmethode konnte nicht überprüft werden. Bitte versuchen Sie es erneut.');

        $this->assertIntentBelongsToOrder($order, $intent);

        if (! $intent->hasSucceeded()) {
            $this->fail('Die Zahlungsmethode wurde noch nicht bestätigt. Bitte schließen Sie den Vorgang ab.');
        }

        if ($intent->paymentMethodId === null) {
            $this->fail('Es wurde keine Zahlungsmethode hinterlegt. Bitte versuchen Sie es erneut.');
        }

        $card = $this->stripe->retrievePaymentMethod($intent->paymentMethodId);

        return DB::transaction(function () use ($order, $user, $intent, $card, $consent) {
            $mandate = OrderPaymentMethod::where('order_id', $order->id)->lockForUpdate()->firstOrFail();

            $mandate->forceFill([
                'stripe_customer_id' => $intent->customerId,
                'setup_intent_id' => $intent->id,
                'payment_method_id' => $card->id,
                'pm_brand' => $card->brand,
                'pm_last4' => $card->last4,
                'pm_exp_month' => $card->expMonth,
                'pm_exp_year' => $card->expYear,
                'status' => OrderPaymentMethod::STATUS_SAVED,
                'verified_at' => now(),
                'offsession_authorized_at' => now(),
                'authorization_version' => (string) config('payments.authorization_version'),
                // The hash pins the exact wording that was agreed to, so a
                // later change to the text cannot retroactively rewrite what a
                // given customer consented to.
                'authorization_text_hash' => hash('sha256', $consent['authorization_text']),
                'authorized_ip' => $consent['ip'],
                'authorized_user_agent' => $consent['user_agent'],
                'last_error' => null,
                'updated_by_user_id' => $user->id,
            ])->save();

            return $mandate->fresh();
        });
    }

    /**
     * Apply a verified SetupIntent that arrived by webhook.
     *
     * Same verification, no session. The expected identity comes from the
     * persisted order either way, so this and confirmSetup() agree by
     * construction rather than by two developers remembering the same rules.
     */
    public function applyVerifiedSetupIntent(
        LeasybackOrder $order,
        StripeSetupIntentResult $intent,
    ): ?OrderPaymentMethod {
        if (! $intent->hasSucceeded() || $intent->paymentMethodId === null) {
            return null;
        }

        if (! $this->identityGuard->permits($order, $intent->customerId, $intent->metadata, 'setup_intent.succeeded')) {
            return null;
        }

        $card = $this->stripe->retrievePaymentMethod($intent->paymentMethodId);

        return DB::transaction(function () use ($order, $intent, $card) {
            $mandate = OrderPaymentMethod::where('order_id', $order->id)->lockForUpdate()->first();

            if ($mandate === null) {
                return null;
            }

            $mandate->forceFill([
                'stripe_customer_id' => $intent->customerId,
                'setup_intent_id' => $intent->id,
                'payment_method_id' => $card->id,
                'pm_brand' => $card->brand,
                'pm_last4' => $card->last4,
                'pm_exp_month' => $card->expMonth,
                'pm_exp_year' => $card->expYear,
                'status' => OrderPaymentMethod::STATUS_SAVED,
                'verified_at' => $mandate->verified_at ?? now(),
                'last_error' => null,
            ])->save();

            return $mandate->fresh();
        });
    }

    /**
     * The mandate row for an order, created on demand.
     *
     * Orders predating this feature have no row, and one is created lazily
     * rather than backfilled — a mandate for an order nobody is paying for
     * would be a row that only ever says "awaiting_method" forever.
     */
    public function mandateFor(LeasybackOrder $order): OrderPaymentMethod
    {
        return OrderPaymentMethod::firstOrCreate(
            ['order_id' => $order->id],
            [
                'auftragsnummer' => $order->auftragsnummer,
                'status' => OrderPaymentMethod::STATUS_AWAITING_METHOD,
            ],
        );
    }

    /**
     * The customer's Stripe Customer id, created once and reused.
     *
     * Reused across every order this person books: a second Customer would
     * orphan the card they already saved and bill them as a stranger.
     */
    public function ensureStripeCustomer(User $user): string
    {
        if (! empty($user->stripe_customer_id)) {
            return $user->stripe_customer_id;
        }

        $customerId = $this->stripe->createCustomer($user->email, $user->name, [
            'user_id' => (string) $user->id,
        ]);

        $user->forceFill(['stripe_customer_id' => $customerId])->save();

        return $customerId;
    }

    /**
     * Every check the confirmation step makes about *whose* SetupIntent this
     * is, expressed against values derived from the order rather than from the
     * request — so an attacker who knows another customer's SetupIntent id
     * still cannot attach it to an order they own.
     */
    private function assertIntentBelongsToOrder(LeasybackOrder $order, StripeSetupIntentResult $intent): void
    {
        $reason = $this->identityGuard->refusalReason($order, $intent->customerId, $intent->metadata);

        if ($reason !== null) {
            $this->fail('Diese Zahlungsmethode gehört nicht zu diesem Auftrag.');
        }
    }

    private function tryRetrieveSetupIntent(string $setupIntentId): ?StripeSetupIntentResult
    {
        try {
            return $this->stripe->retrieveSetupIntent($setupIntentId);
        } catch (StripeGatewayException) {
            return null;
        }
    }

    /**
     * Matches the fail() idiom used by OfferService and RepairOfferService, so
     * HandlesServiceValidationErrors turns it into the same flash-and-back
     * response every other customer write produces.
     */
    private function fail(string $message): never
    {
        throw new HttpResponseException(
            response()->json(['error' => $message], 422),
        );
    }
}
