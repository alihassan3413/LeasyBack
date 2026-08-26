<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Mail\Orders\VehicleReadyForPickupMail;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Enums\PaymentInitiator;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Services\OrderPaymentCheckout;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Recovering a repair charge the automatic off-session attempt could not
 * finish.
 *
 * The invariant every test here is really defending is the same one: **one
 * repair obligation, one logical payment, and never two live PaymentIntents**.
 * Everything else — which gesture is shown, which counter moves — is in service
 * of that.
 */
class RepairPaymentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
        Mail::fake();
    }

    // ---- fixtures ----------------------------------------------------------

    /**
     * A delivered B2C order whose repair charge is outstanding, with one Stripe
     * intent in whatever state the test needs.
     *
     * @return array{0: LeasybackOrder, 1: User, 2: OrderPayment, 3: ?OrderPaymentIntent}
     */
    private function outstandingRepair(
        PaymentStatus $status = PaymentStatus::Failed,
        ?string $intentStatus = OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
        ?string $failureCode = 'card_declined',
        int $amountCents = 119000,
        string $intentId = 'pi_existing',
    ): array {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $customer->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Delivered->value,
        ]);

        LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'selected',
            'repair_cost_net' => '1190.00',
            'repair_cost_gross' => '1190.00',
            'depreciation_value_net' => '0.00',
            'depreciation_value_gross' => '0.00',
            'workshop_repair_quote_net' => '0.00',
            'workshop_repair_quote_gross' => '0.00',
            'missing_parts_cost_net' => '0.00',
            'missing_parts_cost_gross' => '0.00',
            'selected_at' => now(),
        ]);

        OrderPaymentMethod::factory()->saved()->create([
            'order_id' => $order->id,
            'stripe_customer_id' => 'cus_recovery',
            'payment_method_id' => 'pm_saved',
        ]);

        $payment = OrderPayment::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => PaymentPurpose::Repair,
            'amount_cents' => $amountCents,
            'currency' => 'eur',
        ]);

        $payment->forceFill(['status' => $status, 'notified_status' => $status->value])->save();

        $intent = null;

        if ($intentStatus !== null) {
            $payment->forceFill(['intent_count' => 1, 'auto_confirmation_count' => 1])->save();

            $intent = OrderPaymentIntent::create([
                'payment_id' => $payment->id,
                'sequence' => 1,
                'payment_intent_id' => $intentId,
                'payment_method_id' => 'pm_saved',
                'status' => $intentStatus,
                'confirmation_count' => 1,
                'last_initiator' => PaymentInitiator::System,
                'failure_code' => $failureCode,
            ]);

            $this->stripe->givenPaymentIntent($this->intentResult($intentId, $intentStatus, $failureCode, $amountCents));
        }

        return [$order, $customer, $payment->fresh(), $intent];
    }

    private function intentResult(
        string $id,
        string $status,
        ?string $failureCode = null,
        int $amount = 119000,
        ?string $paymentMethodId = null,
    ): StripePaymentIntentResult {
        return new StripePaymentIntentResult(
            id: $id,
            status: $status,
            amount: $amount,
            currency: 'eur',
            clientSecret: $id.'_secret',
            customerId: 'cus_recovery',
            paymentMethodId: $paymentMethodId,
            failureCode: $failureCode,
            metadata: [],
        );
    }

    private function intentsFor(OrderPayment $payment): Collection
    {
        return OrderPaymentIntent::where('payment_id', $payment->id)->orderBy('sequence')->get();
    }

    private function repairPayments(LeasybackOrder $order): Collection
    {
        return OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::Repair->value)
            ->get();
    }

    // ---- requires_action: 3DS on the existing intent ------------------------

    public function test_requires_action_reuses_the_existing_intent_for_authentication(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair(
            PaymentStatus::RequiresAction,
            OrderPaymentIntent::STRIPE_REQUIRES_ACTION,
            null,
        );

        $response = $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertOk();

        $response->assertJsonPath('mode', 'authenticate');
        $response->assertJsonPath('payment_intent_id', 'pi_existing');

        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'), 'A 3DS recovery must never open a second intent.');
        $this->assertCount(1, $this->intentsFor($payment));
    }

    public function test_authenticating_then_syncing_settles_the_payment_and_sends_one_pickup_mail(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair(
            PaymentStatus::RequiresAction,
            OrderPaymentIntent::STRIPE_REQUIRES_ACTION,
            null,
        );

        $this->actingAs($customer)->postJson(route('payments.repair.intent', $order->id))->assertOk();

        // The browser completed the challenge; Stripe now reports it settled.
        $this->stripe->givenPaymentIntent($this->intentResult('pi_existing', OrderPaymentIntent::STRIPE_SUCCEEDED));

        $this->actingAs($customer)
            ->postJson(route('payments.repair.sync', $order->id))
            ->assertOk()
            ->assertJsonPath('status', PaymentStatus::Paid->value)
            ->assertJsonPath('settled', true)
            ->assertJsonPath('payable', false);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        Mail::assertQueuedCount(1);
        Mail::assertQueued(VehicleReadyForPickupMail::class);
    }

    // ---- requires_payment_method: two different meanings --------------------

    public function test_a_true_card_decline_asks_for_a_different_card_on_the_same_intent(): void
    {
        [$order, $customer, $payment, $intent] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
            'card_declined',
        );

        $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertOk()
            ->assertJsonPath('mode', 'collect')
            ->assertJsonPath('payment_intent_id', 'pi_existing');

        // A decline is Stripe's "re-confirm this one", so nothing new is opened
        // and no server-side confirmation is attempted with the refused card.
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(0, $this->stripe->countCallsTo('confirmPaymentIntent'));
        $this->assertCount(1, $this->intentsFor($payment));
        $this->assertSame(2, $intent->fresh()->confirmation_count);
        $this->assertSame(PaymentInitiator::Customer, $intent->fresh()->last_initiator);
    }

    public function test_an_offsession_authentication_failure_is_recovered_without_asking_for_a_new_card(): void
    {
        [$order, $customer, $payment, $intent] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
            'authentication_required',
        );

        // Confirming on-session is what turns the refusal into a challenge the
        // browser can complete.
        $this->stripe->confirmationResults[] = $this->intentResult(
            'pi_existing',
            OrderPaymentIntent::STRIPE_REQUIRES_ACTION,
            null,
            119000,
            'pm_saved',
        );

        $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertOk()
            ->assertJsonPath('mode', 'authenticate')
            ->assertJsonPath('payment_intent_id', 'pi_existing');

        $confirm = $this->stripe->lastCallTo('confirmPaymentIntent')['arguments'];

        $this->assertSame('pi_existing', $confirm['paymentIntentId']);
        $this->assertSame('pm_saved', $confirm['paymentMethodId'], 'The card already on file is what gets authenticated.');
        $this->assertFalse($confirm['offSession']);

        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertCount(1, $this->intentsFor($payment));
        $this->assertSame(OrderPaymentIntent::STRIPE_REQUIRES_ACTION, $intent->fresh()->status);
    }

    public function test_the_two_meanings_of_requires_payment_method_produce_different_modes(): void
    {
        [$declinedOrder, $declinedCustomer] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
            'card_declined',
        );

        $declined = $this->actingAs($declinedCustomer)
            ->postJson(route('payments.repair.intent', $declinedOrder->id))
            ->json('mode');

        [$scaOrder, $scaCustomer] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
            'authentication_required',
            119000,
            'pi_sca',
        );

        $this->stripe->confirmationResults[] = $this->intentResult('pi_sca', OrderPaymentIntent::STRIPE_REQUIRES_ACTION);

        $sca = $this->actingAs($scaCustomer)
            ->postJson(route('payments.repair.intent', $scaOrder->id))
            ->json('mode');

        // Same Stripe status, same amount, same stored card — only
        // `last_payment_error.code` differs, and it is the whole difference
        // between "authenticate the card you gave us" and "give us another".
        $this->assertSame('collect', $declined);
        $this->assertSame('authenticate', $sca);
    }

    public function test_a_declined_card_can_be_replaced_and_the_payment_settles(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
            'card_declined',
        );

        $this->actingAs($customer)->postJson(route('payments.repair.intent', $order->id))->assertOk();

        // The browser confirmed the same intent with a different card.
        $this->stripe->givenPaymentIntent($this->intentResult('pi_existing', OrderPaymentIntent::STRIPE_SUCCEEDED));

        $this->actingAs($customer)
            ->postJson(route('payments.repair.sync', $order->id))
            ->assertOk()
            ->assertJsonPath('settled', true);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertCount(1, $this->intentsFor($payment), 'A retry re-confirms the existing intent rather than opening another.');
    }

    // ---- requires_confirmation ---------------------------------------------

    public function test_requires_confirmation_with_a_card_attached_is_confirmed_not_re_collected(): void
    {
        [$order, $customer, $payment, $intent] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_REQUIRES_CONFIRMATION,
            null,
        );

        $this->stripe->givenPaymentIntent($this->intentResult(
            'pi_existing',
            OrderPaymentIntent::STRIPE_REQUIRES_CONFIRMATION,
            null,
            119000,
            'pm_saved',
        ));

        $this->stripe->confirmationResults[] = $this->intentResult(
            'pi_existing',
            OrderPaymentIntent::STRIPE_REQUIRES_ACTION,
        );

        $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertOk()
            ->assertJsonPath('mode', 'authenticate');

        $this->assertSame(1, $this->stripe->countCallsTo('confirmPaymentIntent'));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertCount(1, $this->intentsFor($payment));
        $this->assertSame(2, $intent->fresh()->confirmation_count);
    }

    public function test_requires_confirmation_without_a_card_collects_one(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_REQUIRES_CONFIRMATION,
            null,
        );

        OrderPaymentIntent::where('payment_id', $payment->id)->update(['payment_method_id' => null]);
        OrderPaymentMethod::where('order_id', $order->id)->update(['payment_method_id' => null]);

        $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertOk()
            ->assertJsonPath('mode', 'collect')
            ->assertJsonPath('payment_intent_id', 'pi_existing');

        $this->assertSame(0, $this->stripe->countCallsTo('confirmPaymentIntent'));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    // ---- when a new intent IS correct --------------------------------------

    public function test_only_a_cancelled_intent_produces_a_replacement(): void
    {
        [$order, $customer, $payment, $intent] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_CANCELED,
            null,
        );

        $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertOk()
            ->assertJsonPath('mode', 'collect');

        $intents = $this->intentsFor($payment);

        $this->assertCount(2, $intents);
        $this->assertSame(2, $intents[1]->sequence);
        $this->assertNotSame('pi_existing', $intents[1]->payment_intent_id);

        // The superseded row keeps its own id and its own outcome.
        $this->assertSame('pi_existing', $intent->fresh()->payment_intent_id);
        $this->assertSame(OrderPaymentIntent::STRIPE_CANCELED, $intent->fresh()->status);

        // A cancelled *intent* must not make the logical payment terminal —
        // the repair is still owed.
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
    }

    public function test_a_replacement_intent_is_created_on_session_with_its_own_idempotency_key(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_CANCELED,
            null,
        );

        $this->actingAs($customer)->postJson(route('payments.repair.intent', $order->id))->assertOk();

        $call = $this->stripe->lastCallTo('createPaymentIntent')['arguments'];

        $this->assertFalse($call['offSession']);
        $this->assertFalse($call['confirm']);
        $this->assertNull($call['paymentMethodId']);
        $this->assertSame(119000, $call['amountCents']);
        $this->assertSame(sprintf('%s:repair:2:1', $payment->id), $call['idempotencyKey']);
    }

    public function test_requires_manual_collection_opens_an_on_session_intent(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair(
            PaymentStatus::RequiresManualCollection,
            null,
        );

        $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertOk()
            ->assertJsonPath('mode', 'collect');

        $intents = $this->intentsFor($payment);

        $this->assertCount(1, $intents);
        $this->assertSame(1, $intents[0]->sequence);
        $this->assertSame(PaymentInitiator::Customer, $intents[0]->last_initiator);
    }

    // ---- no duplicates ------------------------------------------------------

    public function test_recovery_never_creates_a_second_logical_payment(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair();

        $this->actingAs($customer)->postJson(route('payments.repair.intent', $order->id))->assertOk();
        $this->actingAs($customer)->postJson(route('payments.repair.intent', $order->id))->assertOk();

        $this->stripe->givenPaymentIntent($this->intentResult('pi_existing', OrderPaymentIntent::STRIPE_SUCCEEDED));
        $this->actingAs($customer)->postJson(route('payments.repair.sync', $order->id))->assertOk();

        $this->assertCount(1, $this->repairPayments($order));
        $this->assertSame($payment->id, $this->repairPayments($order)->first()->id);
    }

    public function test_two_concurrent_checkout_requests_cannot_open_two_replacement_intents(): void
    {
        [$order, , $payment] = $this->outstandingRepair(
            PaymentStatus::Failed,
            OrderPaymentIntent::STRIPE_CANCELED,
            null,
        );

        /*
         * The lock is what makes this safe, and a lock cannot be observed from
         * a single-threaded test. So this exercises the thing the lock exists
         * to serialise: two resolutions of the same payment, back to back. The
         * second must find the intent the first opened and reuse it rather than
         * computing the same next sequence again.
         */
        $checkout = app(OrderPaymentCheckout::class);

        $first = $checkout->prepare($order);
        $second = $checkout->prepare($order);

        $this->assertSame($first->paymentIntentId, $second->paymentIntentId);
        $this->assertCount(2, $this->intentsFor($payment), 'One superseded intent plus one replacement — never two replacements.');
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(2, $payment->fresh()->intent_count);
    }

    public function test_a_settled_charge_is_refused_rather_than_charged_again(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair();

        // Stripe settled it behind our back — a webhook we have not processed.
        $this->stripe->givenPaymentIntent($this->intentResult('pi_existing', OrderPaymentIntent::STRIPE_SUCCEEDED));

        $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertStatus(422);

        // Refused, but the observation is kept: the refusal must not roll back
        // the settlement it just discovered.
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertCount(1, $this->intentsFor($payment));
    }

    public function test_a_paid_payment_offers_nothing_to_pay(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair();

        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now()])->save();

        $this->actingAs($customer)
            ->getJson(route('payments.repair.show', $order->id))
            ->assertOk()
            ->assertJsonPath('payable', false)
            ->assertJsonPath('settled', true);

        $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertStatus(422);
    }

    public function test_an_unreadable_intent_never_falls_through_to_a_new_one(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair();

        $this->stripe->paymentIntents = [];

        $this->actingAs($customer)
            ->postJson(route('payments.repair.intent', $order->id))
            ->assertStatus(422);

        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertCount(1, $this->intentsFor($payment));
        $this->assertSame(1, $payment->fresh()->intent_count);
    }

    // ---- the webhook stays authoritative ------------------------------------

    public function test_a_replayed_webhook_after_a_sync_changes_nothing_and_mails_once(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair();

        $this->stripe->givenPaymentIntent($this->intentResult('pi_existing', OrderPaymentIntent::STRIPE_SUCCEEDED));
        $this->actingAs($customer)->postJson(route('payments.repair.sync', $order->id))->assertOk();

        $paidAt = $payment->fresh()->paid_at;

        foreach (range(1, 2) as $ignored) {
            $this->sendWebhook([
                'type' => 'payment_intent.succeeded',
                'data' => ['object' => [
                    'id' => 'pi_existing',
                    'status' => OrderPaymentIntent::STRIPE_SUCCEEDED,
                    'amount' => 119000,
                    'currency' => 'eur',
                    'customer' => 'cus_recovery',
                    'metadata' => ['order_id' => $order->id],
                ]],
            ])->assertOk();
        }

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
        Mail::assertQueuedCount(1);
    }

    public function test_a_late_failure_cannot_un_pay_a_settled_charge(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair();

        $this->stripe->givenPaymentIntent($this->intentResult('pi_existing', OrderPaymentIntent::STRIPE_SUCCEEDED));
        $this->actingAs($customer)->postJson(route('payments.repair.sync', $order->id))->assertOk();

        $this->sendWebhook([
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_existing',
                'status' => OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
                'amount' => 119000,
                'currency' => 'eur',
                'customer' => 'cus_recovery',
                'metadata' => ['order_id' => $order->id],
                'last_payment_error' => ['code' => 'card_declined', 'message' => 'Declined.'],
            ]],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function sendWebhook(array $event): TestResponse
    {
        $this->stripe->webhookEvent = $event;

        return $this->postJson('/api/webhooks/stripe', $event, ['Stripe-Signature' => 't=1,v1=fake']);
    }

    // ---- authorization -------------------------------------------------------

    public function test_a_stranger_gets_a_404_from_every_endpoint(): void
    {
        [$order] = $this->outstandingRepair();

        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($stranger)->getJson(route('payments.repair.show', $order->id))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('payments.repair.intent', $order->id))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('payments.repair.sync', $order->id))->assertNotFound();

        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_admin_cannot_execute_the_customers_payment(): void
    {
        [$order, , $payment] = $this->outstandingRepair();

        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        // 404 rather than 403 throughout: authenticating someone else's card is
        // not an Admin capability that happens to be switched off, it is not a
        // thing anyone can do on a customer's behalf.
        $this->actingAs($admin)->getJson(route('payments.repair.show', $order->id))->assertNotFound();
        $this->actingAs($admin)->postJson(route('payments.repair.intent', $order->id))->assertNotFound();
        $this->actingAs($admin)->postJson(route('payments.repair.sync', $order->id))->assertNotFound();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_a_guest_is_redirected_rather_than_served(): void
    {
        [$order] = $this->outstandingRepair();

        $this->getJson(route('payments.repair.show', $order->id))->assertUnauthorized();
    }

    public function test_a_b2b_order_has_no_repair_checkout(): void
    {
        $company = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => $company->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Delivered->value,
        ]);

        $this->actingAs($company)->getJson(route('payments.repair.show', $order->id))->assertNotFound();
        $this->actingAs($company)->postJson(route('payments.repair.intent', $order->id))->assertNotFound();

        $this->assertCount(0, $this->repairPayments($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    // ---- the gate -----------------------------------------------------------

    public function test_pickup_is_blocked_before_the_recovery_succeeds_and_allowed_after(): void
    {
        [$order, $customer, $payment] = $this->outstandingRepair(
            PaymentStatus::RequiresAction,
            OrderPaymentIntent::STRIPE_REQUIRES_ACTION,
            null,
        );

        try {
            app(TransitionOrderStatus::class)($order, OrderStatus::Completed->value, 'test', 'PHPUnit');
            $this->fail('Completion must be refused while the repair charge is outstanding.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order_status', $e->errors());
        }

        $this->assertSame(OrderStatus::Delivered->value, $order->fresh()->order_status);

        $this->actingAs($customer)->postJson(route('payments.repair.intent', $order->id))->assertOk();
        $this->stripe->givenPaymentIntent($this->intentResult('pi_existing', OrderPaymentIntent::STRIPE_SUCCEEDED));
        $this->actingAs($customer)->postJson(route('payments.repair.sync', $order->id))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);

        app(TransitionOrderStatus::class)($order->fresh(), OrderStatus::Completed->value, 'test', 'PHPUnit');

        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->order_status);
    }

    /**
     * @return array<string, array{0: PaymentStatus}>
     */
    public static function blockingStatuses(): array
    {
        return [
            'requires action' => [PaymentStatus::RequiresAction],
            'failed' => [PaymentStatus::Failed],
            'processing' => [PaymentStatus::Processing],
            'requires manual collection' => [PaymentStatus::RequiresManualCollection],
            'pending' => [PaymentStatus::Pending],
        ];
    }

    #[DataProvider('blockingStatuses')]
    public function test_only_paid_or_not_required_unlocks_completion(PaymentStatus $status): void
    {
        [$order, , $payment] = $this->outstandingRepair($status);

        $payment->forceFill(['status' => $status])->save();

        $this->expectException(ValidationException::class);

        app(TransitionOrderStatus::class)($order, OrderStatus::Completed->value, 'test', 'PHPUnit');
    }

    // ---- the dashboard payload ----------------------------------------------

    public function test_the_state_endpoint_describes_what_is_owed(): void
    {
        [$order, $customer] = $this->outstandingRepair();

        $this->actingAs($customer)
            ->getJson(route('payments.repair.show', $order->id))
            ->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('status', PaymentStatus::Failed->value)
            ->assertJsonPath('amount_cents', 119000)
            ->assertJsonPath('amount', '1190.00')
            ->assertJsonPath('payable', true)
            ->assertJsonPath('settled', false)
            ->assertJsonPath('card.last4', '4242');
    }
}
