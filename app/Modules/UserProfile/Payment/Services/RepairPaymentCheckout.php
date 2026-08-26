<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Data\RepairCheckoutSession;
use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Enums\PaymentInitiator;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The customer's way back into a repair charge that the automatic off-session
 * attempt could not finish — an authentication challenge, or a declined card.
 *
 * Deliberately not part of RepairPaymentService, which TransitionOrderStatus
 * constructs on every status change: this needs the Stripe gateway, and
 * StripeClient refuses to construct without a secret, so combining the two
 * would make every order transition depend on Stripe being configured.
 *
 * Nothing here writes `order_payments.status` — PaymentService remains the only
 * writer, reached through applyIntentState() exactly as the webhook reaches it.
 * This class decides only which Stripe intent the customer should act on, and
 * that decision has one rule: reuse the existing intent unless Stripe says it
 * cannot be reused.
 *
 * Client secrets are read from Stripe when they are handed out and are never
 * persisted and never logged.
 */
class RepairPaymentCheckout
{
    /**
     * Stripe's reason code for an off-session charge that the issuer would
     * only approve with the cardholder present.
     *
     * This is the distinction that makes `requires_payment_method` ambiguous:
     * an intent lands there both when the card was genuinely refused and when
     * it was fine but needed SCA, and only this code tells the two apart.
     */
    private const AUTHENTICATION_REQUIRED = 'authentication_required';

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PaymentService $payments,
        private readonly RepairPaymentService $repairPayments,
        private readonly PaymentMethodService $paymentMethods,
        private readonly PaymentAuthorizer $authorizer,
    ) {}

    /**
     * What the customer is being asked to pay, and whether they can act on it.
     *
     * @return array{exists: bool, status: ?string, label: ?string, amount_cents: int, amount: string, currency: string, payable: bool, settled: bool, card: ?array{brand: ?string, last4: ?string, exp_month: ?int, exp_year: ?int}}
     */
    public function state(LeasybackOrder $order): array
    {
        $payment = $this->payments->repairPaymentFor($order->id);
        $card = $this->cardOf($this->repairPayments->mandateFor($order));

        if ($payment === null) {
            return [
                'exists' => false,
                'status' => null,
                'label' => null,
                'amount_cents' => 0,
                'amount' => '0.00',
                'currency' => (string) config('services.stripe.currency', 'eur'),
                'payable' => false,
                'settled' => false,
                'card' => $card,
            ];
        }

        return [
            'exists' => true,
            'status' => $payment->status->value,
            'label' => $payment->status->label(),
            'amount_cents' => $payment->amount_cents,
            'amount' => $payment->amountDecimal(),
            'currency' => $payment->currency,
            'payable' => self::isPayable($payment),
            'settled' => $payment->status->satisfiesReleaseGate(),
            'card' => $card,
        ];
    }

    /**
     * Hand the browser a Stripe intent to finish, reusing the existing one
     * wherever Stripe permits it.
     *
     * The whole resolve-and-create span holds a row lock on the logical
     * payment. Without it, two clicks arriving together would each find no
     * usable intent, each compute the same next sequence, and each open a
     * replacement — one obligation, two live PaymentIntents. The deterministic
     * idempotency key derived from that sequence is the second line of
     * defence, not the first: it only helps if both requests agree on the
     * sequence, which is exactly what the lock guarantees.
     */
    public function prepare(LeasybackOrder $order): RepairCheckoutSession
    {
        $payment = $this->payments->repairPaymentFor($order->id);

        if ($payment === null || ! self::isPayable($payment)) {
            $this->refuse();
        }

        $outcome = DB::transaction(function () use ($order, $payment) {
            /** @var OrderPayment $locked */
            $locked = OrderPayment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            // Re-checked under the lock: the charge may have settled while
            // this request was queued behind another one.
            if (! self::isPayable($locked)) {
                return null;
            }

            $intent = $locked->currentIntent();

            return $intent === null
                ? $this->open($order, $locked)
                : $this->resolveExisting($order, $locked, $intent);
        });

        if ($outcome instanceof RepairCheckoutSession) {
            return $outcome;
        }

        /*
         * Stripe had already moved on. Applied out here rather than inside the
         * closure because PaymentService::transition() must commit even though
         * this request ends in a refusal — rolling it back would discard a
         * settlement we had just observed, and with it the pickup mail.
         */
        if ($outcome instanceof StripePaymentIntentResult) {
            $this->payments->applyIntentState($outcome);
        }

        $this->refuse();
    }

    /**
     * Re-read the current intent from Stripe and apply what it says.
     *
     * The browser reports the outcome of its own confirmation, and this checks
     * that report against Stripe — the same "verified, never trusted" rule the
     * SetupIntent step follows. The webhook remains the authority; this only
     * lets the portal agree sooner, and `notified_status` means whichever
     * observer arrives first is the one that notifies.
     *
     * @return array<string, mixed>
     */
    public function sync(LeasybackOrder $order): array
    {
        $payment = $this->payments->repairPaymentFor($order->id);
        $intent = $payment?->currentIntent();

        if ($intent !== null) {
            $this->payments->applyIntentState($this->retrieve($intent->payment_intent_id));
        }

        return $this->state($order);
    }

    /**
     * Whether the customer has something to do about this charge.
     *
     * A zero-amount payment is excluded even in theory: there is nothing to
     * collect, and `NotRequired` already satisfies the gate.
     */
    public static function isPayable(OrderPayment $payment): bool
    {
        return $payment->amount_cents > 0 && $payment->status->needsCustomerAction();
    }

    /**
     * Decide what to do with the intent this payment already has.
     *
     * Returns a session to hand the browser, a Stripe result meaning "this
     * settled without us", or null meaning there is nothing to pay.
     */
    private function resolveExisting(
        LeasybackOrder $order,
        OrderPayment $payment,
        OrderPaymentIntent $intent,
    ): RepairCheckoutSession|StripePaymentIntentResult|null {
        // Stripe is the authority on what state this intent is really in.
        // Trusting the local mirror would mean handing out a secret for an
        // intent that has already settled, and taking the money twice.
        $observed = $this->retrieve($intent->payment_intent_id);

        $resolved = $this->settleOrSupersede($order, $payment, $intent, $observed);

        if ($resolved !== false) {
            return $resolved;
        }

        /*
         * One server-side confirmation, at most, before anything is asked of
         * the customer — see paymentMethodForConfirmation(). Confirming
         * on-session is what turns an SCA refusal into a 3DS challenge the
         * browser can actually complete, and it is the difference between
         * asking someone to authenticate the card they already gave us and
         * asking them for a different one.
         */
        $paymentMethodId = $this->paymentMethodForConfirmation($order, $intent, $observed);

        if ($paymentMethodId !== null) {
            $observed = $this->confirmOnSession($intent, $paymentMethodId);

            $resolved = $this->settleOrSupersede($order, $payment, $intent, $observed);

            if ($resolved !== false) {
                return $resolved;
            }
        }

        return self::isReusableStatus($observed->status)
            ? $this->reuse($payment, $intent, $observed)
            : $this->open($order, $payment);
    }

    /**
     * Handle the two observations that end the reuse question outright, or
     * report false to carry on deciding.
     */
    private function settleOrSupersede(
        LeasybackOrder $order,
        OrderPayment $payment,
        OrderPaymentIntent $intent,
        StripePaymentIntentResult $observed,
    ): RepairCheckoutSession|StripePaymentIntentResult|false {
        if ($observed->hasSucceeded() || $observed->status === OrderPaymentIntent::STRIPE_PROCESSING) {
            return $observed;
        }

        if ($observed->status === OrderPaymentIntent::STRIPE_CANCELED) {
            /*
             * A cancelled intent is the one case that genuinely cannot be
             * reused, so it is recorded and superseded. It is deliberately not
             * pushed through applyIntentState(): that maps `canceled` onto
             * PaymentStatus::Cancelled, which is terminal, and would leave a
             * repair that is still owed permanently unpayable.
             */
            $intent->forceFill(['status' => $observed->status])->save();

            return $this->open($order, $payment);
        }

        return false;
    }

    /**
     * The card to confirm this intent with, or null if the customer has to
     * supply one.
     *
     * Two states reach here and neither is the "declined, ask for a new card"
     * case they are easily mistaken for:
     *
     * `requires_confirmation` means the intent was created with a card
     * attached and never confirmed. There is nothing wrong with that card, so
     * presenting a replacement form would be asking the customer to re-enter
     * details we already hold.
     *
     * `requires_payment_method` is where an off-session charge lands both when
     * the card was refused *and* when the issuer wanted the cardholder
     * present. Only `last_payment_error.code` separates them, and confirming
     * on-session fixes exactly one of them.
     */
    private function paymentMethodForConfirmation(
        LeasybackOrder $order,
        OrderPaymentIntent $intent,
        StripePaymentIntentResult $observed,
    ): ?string {
        if ($observed->status === OrderPaymentIntent::STRIPE_REQUIRES_CONFIRMATION) {
            return $observed->paymentMethodId ?? $intent->payment_method_id;
        }

        if ($observed->status !== OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD) {
            return null;
        }

        if ($observed->failureCode !== self::AUTHENTICATION_REQUIRED) {
            return null;
        }

        // Stripe detaches the payment method from the intent when an attempt
        // fails, so the card that needs authenticating is read from our own
        // snapshot of what was charged, falling back to the stored mandate.
        $mandate = $this->repairPayments->mandateFor($order);

        return $intent->payment_method_id
            ?? ($mandate?->status === OrderPaymentMethod::STATUS_SAVED ? $mandate->payment_method_id : null);
    }

    /**
     * Confirm an existing intent on-session, with the card already on file.
     */
    private function confirmOnSession(OrderPaymentIntent $intent, string $paymentMethodId): StripePaymentIntentResult
    {
        try {
            $result = $this->stripe->confirmPaymentIntent(
                paymentIntentId: $intent->payment_intent_id,
                paymentMethodId: $paymentMethodId,
                // On-session, so Stripe raises the challenge as
                // `requires_action` for the browser to complete rather than
                // refusing it the way it did when nobody was there.
                offSession: false,
                idempotencyKey: $intent->idempotencyKeyForNextConfirmation(),
            );
        } catch (StripeGatewayException $e) {
            // An ordinary outcome, not an incident: this card cannot be
            // authenticated, and the customer falls through to entering a
            // different one on the same intent.
            Log::info('On-session re-confirmation of a repair intent was refused.', [
                'payment_intent_id' => $intent->payment_intent_id,
                'stripe_code' => $e->stripeCode,
            ]);

            // Counted even though it threw: an attempt that Stripe refused is
            // still an attempt, and the next idempotency key must not repeat
            // the one it was made under.
            $intent->forceFill([
                'confirmation_count' => $intent->confirmation_count + 1,
                'last_initiator' => PaymentInitiator::Customer,
                'failure_code' => $e->stripeCode,
                'last_error' => $e->getMessage(),
            ])->save();

            return $this->retrieve($intent->payment_intent_id);
        }

        $intent->forceFill([
            'status' => $result->status,
            'confirmation_count' => $intent->confirmation_count + 1,
            'last_initiator' => PaymentInitiator::Customer,
            'failure_code' => $result->failureCode,
            'last_error' => $result->failureMessage,
        ])->save();

        return $result;
    }

    private function reuse(
        OrderPayment $payment,
        OrderPaymentIntent $intent,
        StripePaymentIntentResult $observed,
    ): RepairCheckoutSession {
        if ($observed->clientSecret === null) {
            $this->fail('Die Zahlung konnte nicht geladen werden. Bitte versuchen Sie es später erneut.');
        }

        $mode = $observed->status === OrderPaymentIntent::STRIPE_REQUIRES_ACTION
            ? RepairCheckoutSession::MODE_AUTHENTICATE
            : RepairCheckoutSession::MODE_COLLECT;

        /*
         * Counted only for a collect: that hands out a secret the customer
         * will confirm in the browser, which is a new confirmation this
         * application never sees. `authenticate` completes a confirmation that
         * already exists and was counted when it was made.
         *
         * `auto_confirmation_count` is untouched either way — that cap exists
         * to stop unattended retries, and a person clicking "pay" is not that.
         */
        $attributes = ['status' => $observed->status, 'last_initiator' => PaymentInitiator::Customer];

        if ($mode === RepairCheckoutSession::MODE_COLLECT) {
            $attributes['confirmation_count'] = $intent->confirmation_count + 1;
        }

        $intent->forceFill($attributes)->save();

        return new RepairCheckoutSession(
            mode: $mode,
            clientSecret: $observed->clientSecret,
            paymentIntentId: $intent->payment_intent_id,
            amountCents: $payment->amount_cents,
            currency: $payment->currency,
        );
    }

    /**
     * Open a fresh on-session intent, because no usable one exists.
     *
     * Two cases reach here: the previous intent was cancelled, and a charge
     * that was never sent to Stripe at all — `RequiresManualCollection`, where
     * no mandate was chargeable off-session. The customer paying by hand is a
     * real answer to both.
     */
    private function open(LeasybackOrder $order, OrderPayment $payment): RepairCheckoutSession
    {
        $owner = $this->authorizer->ownerOf($order);

        if ($owner === null) {
            $this->fail('Zu diesem Auftrag ist kein Kunde hinterlegt.');
        }

        $customerId = $this->repairPayments->mandateFor($order)?->stripe_customer_id
            ?: $this->paymentMethods->ensureStripeCustomer($owner);

        $sequence = $payment->intent_count + 1;
        $offer = $this->repairPayments->selectedOffer($order);

        // Raised before the call, for the reason the off-session job raises its
        // counters first: a crash mid-request must not leave this sequence
        // looking unused and let a later attempt reuse its idempotency key.
        $payment->forceFill(['intent_count' => $sequence])->save();

        try {
            $result = $this->stripe->createPaymentIntent(
                customerId: (string) $customerId,
                amountCents: $payment->amount_cents,
                currency: $payment->currency,
                // Both null and false because the customer is present: they
                // pick the card in the browser and confirm it there, which is
                // also what lets Stripe run authentication interactively
                // instead of handing back a refusal.
                paymentMethodId: null,
                confirm: false,
                offSession: false,
                idempotencyKey: sprintf('%s:repair:%d:1', $payment->id, $sequence),
                metadata: array_filter([
                    'order_id' => $order->id,
                    'offer_id' => $offer?->offer_id,
                    'auftragsnummer' => (string) $order->auftragsnummer,
                    'purpose' => 'repair',
                ]),
            );
        } catch (StripeGatewayException $e) {
            Log::warning('Could not open an on-session repair intent.', [
                'payment_id' => $payment->id,
                'auftragsnummer' => $order->auftragsnummer,
                'stripe_code' => $e->stripeCode,
            ]);

            $this->fail('Die Zahlung konnte nicht gestartet werden. Bitte versuchen Sie es erneut.');
        }

        if ($result->clientSecret === null) {
            $this->fail('Die Zahlung konnte nicht gestartet werden. Bitte versuchen Sie es erneut.');
        }

        OrderPaymentIntent::create([
            'payment_id' => $payment->id,
            'sequence' => $sequence,
            'payment_intent_id' => $result->id,
            'status' => $result->status,
            'confirmation_count' => 0,
            'last_initiator' => PaymentInitiator::Customer,
            'created_at_stripe' => $result->createdAt,
        ]);

        return new RepairCheckoutSession(
            mode: RepairCheckoutSession::MODE_COLLECT,
            clientSecret: $result->clientSecret,
            paymentIntentId: $result->id,
            amountCents: $payment->amount_cents,
            currency: $payment->currency,
        );
    }

    /**
     * A failure to read an intent is never allowed to fall through to opening a
     * new one — that is precisely how one obligation acquires two live intents
     * and the customer is charged twice.
     */
    private function retrieve(string $paymentIntentId): StripePaymentIntentResult
    {
        try {
            return $this->stripe->retrievePaymentIntent($paymentIntentId);
        } catch (StripeGatewayException $e) {
            Log::warning('Could not read a repair payment intent from Stripe.', [
                'payment_intent_id' => $paymentIntentId,
                'stripe_code' => $e->stripeCode,
            ]);

            $this->fail('Die Zahlung konnte nicht geladen werden. Bitte versuchen Sie es später erneut.');
        }
    }

    private static function isReusableStatus(string $status): bool
    {
        return in_array($status, [
            OrderPaymentIntent::STRIPE_REQUIRES_ACTION,
            OrderPaymentIntent::STRIPE_REQUIRES_CONFIRMATION,
            OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
        ], true);
    }

    /**
     * @return ?array{brand: ?string, last4: ?string, exp_month: ?int, exp_year: ?int}
     */
    private function cardOf(?OrderPaymentMethod $mandate): ?array
    {
        if ($mandate === null || $mandate->payment_method_id === null) {
            return null;
        }

        return [
            'brand' => $mandate->pm_brand,
            'last4' => $mandate->pm_last4,
            'exp_month' => $mandate->pm_exp_month === null ? null : (int) $mandate->pm_exp_month,
            'exp_year' => $mandate->pm_exp_year === null ? null : (int) $mandate->pm_exp_year,
        ];
    }

    private function refuse(): never
    {
        $this->fail('Für diesen Auftrag ist derzeit keine Zahlung offen.');
    }

    private function fail(string $message): never
    {
        throw new HttpResponseException(
            response()->json(['error' => $message], 422),
        );
    }
}
