<?php

namespace Tests\Feature\Payment;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * The webhook has no session and no user. Every test here asserts that the
 * expected identity is derived from the persisted order, and that anything
 * which does not match is dropped with a 200 rather than an error.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    /**
     * @return array{0: User, 1: LeasybackOrder, 2: OrderPaymentMethod}
     */
    private function orderWithMandate(array $mandateState = [], string $belongs = 'B2C'): array
    {
        $owner = User::factory()->create([
            'user_type' => UserType::Privatkunde,
            'stripe_customer_id' => 'cus_owner_'.fake()->unique()->numerify('####'),
        ]);

        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => $belongs,
            'b2c_user_id' => $belongs === 'B2C' ? $owner->id : null,
        ]);

        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $mandate = OrderPaymentMethod::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'stripe_customer_id' => $owner->stripe_customer_id,
            'setup_intent_id' => 'seti_known',
            ...$mandateState,
        ]);

        return [$owner, $order, $mandate];
    }

    private function sendEvent(array $event): TestResponse
    {
        $this->stripe->webhookEvent = $event;

        return $this->postJson(route('webhooks.stripe'), $event, [
            'Stripe-Signature' => 't=1,v1=fake',
        ]);
    }

    private function setupIntentSucceeded(User $owner, LeasybackOrder $order, array $overrides = []): array
    {
        return [
            'id' => 'evt_1',
            'type' => 'setup_intent.succeeded',
            'data' => ['object' => [
                'id' => 'seti_known',
                'status' => 'succeeded',
                'customer' => $owner->stripe_customer_id,
                'payment_method' => 'pm_from_webhook',
                'metadata' => ['order_id' => $order->id, 'user_id' => (string) $owner->id],
                ...$overrides,
            ]],
        ];
    }

    // ---- signature ---------------------------------------------------------

    public function test_missing_webhook_secret_answers_503(): void
    {
        config(['services.stripe.webhook_secret' => '']);

        $this->postJson(route('webhooks.stripe'), [], ['Stripe-Signature' => 't=1,v1=x'])
            ->assertStatus(503);
    }

    public function test_invalid_signature_answers_401(): void
    {
        $this->stripe->webhookSignatureValid = false;

        $this->postJson(route('webhooks.stripe'), ['type' => 'setup_intent.succeeded'], [
            'Stripe-Signature' => 't=1,v1=wrong',
        ])->assertStatus(401);
    }

    public function test_absent_signature_header_answers_401(): void
    {
        $this->postJson(route('webhooks.stripe'), ['type' => 'setup_intent.succeeded'])
            ->assertStatus(401);
    }

    public function test_the_endpoint_requires_no_authentication(): void
    {
        [$owner, $order] = $this->orderWithMandate();

        $this->sendEvent($this->setupIntentSucceeded($owner, $order))->assertOk();

        $this->assertGuest();
    }

    // ---- setup_intent.succeeded --------------------------------------------

    public function test_succeeded_setup_intent_saves_the_mandate_for_the_owner_derived_from_the_order(): void
    {
        [$owner, $order, $mandate] = $this->orderWithMandate();

        $this->sendEvent($this->setupIntentSucceeded($owner, $order))->assertOk();

        $mandate->refresh();

        $this->assertSame(OrderPaymentMethod::STATUS_SAVED, $mandate->status);
        $this->assertSame('pm_from_webhook', $mandate->payment_method_id);
        $this->assertNotNull($mandate->verified_at);
        $this->assertSame('4242', $mandate->pm_last4);
    }

    /**
     * The webhook can verify a card but cannot witness a consent. Until the
     * customer's own confirm call records the mandate, the card stays
     * unchargeable.
     */
    public function test_a_webhook_alone_does_not_authorize_off_session_charging(): void
    {
        [$owner, $order, $mandate] = $this->orderWithMandate();

        $this->sendEvent($this->setupIntentSucceeded($owner, $order))->assertOk();

        $mandate->refresh();

        $this->assertNull($mandate->offsession_authorized_at);
        $this->assertFalse($mandate->isChargeableOffSession());
    }

    public function test_an_unknown_setup_intent_is_ignored_with_200(): void
    {
        [$owner, $order, $mandate] = $this->orderWithMandate();

        $this->sendEvent($this->setupIntentSucceeded($owner, $order, ['id' => 'seti_never_seen']))
            ->assertOk();

        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->fresh()->status);
    }

    public function test_a_customer_that_is_not_the_order_owner_is_ignored_with_200(): void
    {
        [$owner, $order, $mandate] = $this->orderWithMandate();

        $this->sendEvent($this->setupIntentSucceeded($owner, $order, ['customer' => 'cus_someone_else']))
            ->assertOk();

        $mandate->refresh();
        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->status);
        $this->assertNull($mandate->payment_method_id);
    }

    public function test_metadata_naming_another_order_is_ignored_with_200(): void
    {
        [$owner, $order, $mandate] = $this->orderWithMandate();
        [, $otherOrder] = $this->orderWithMandate();

        $event = $this->setupIntentSucceeded($owner, $order);
        $event['data']['object']['metadata']['order_id'] = $otherOrder->id;

        $this->sendEvent($event)->assertOk();

        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->fresh()->status);
    }

    public function test_metadata_naming_another_user_is_ignored_with_200(): void
    {
        [$owner, $order, $mandate] = $this->orderWithMandate();

        $event = $this->setupIntentSucceeded($owner, $order);
        $event['data']['object']['metadata']['user_id'] = '999999';

        $this->sendEvent($event)->assertOk();

        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->fresh()->status);
    }

    public function test_a_b2b_order_is_ignored_with_200(): void
    {
        [$owner, $order, $mandate] = $this->orderWithMandate([], 'B2B');

        $this->sendEvent($this->setupIntentSucceeded($owner, $order))->assertOk();

        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->fresh()->status);
    }

    /**
     * Stripe redelivers. A replay must change nothing and must not re-fetch
     * the card.
     */
    public function test_a_replayed_success_is_idempotent(): void
    {
        [$owner, $order, $mandate] = $this->orderWithMandate();
        $event = $this->setupIntentSucceeded($owner, $order);

        $this->sendEvent($event)->assertOk();
        $verifiedAt = $mandate->fresh()->verified_at;
        $fetchesAfterFirst = $this->stripe->countCallsTo('retrievePaymentMethod');

        $this->sendEvent($event)->assertOk();

        $mandate->refresh();
        $this->assertEquals($verifiedAt, $mandate->verified_at);
        $this->assertSame($fetchesAfterFirst, $this->stripe->countCallsTo('retrievePaymentMethod'));
    }

    // ---- setup_intent.setup_failed -----------------------------------------

    public function test_setup_failed_keeps_the_mandate_awaiting_and_records_the_error(): void
    {
        [, , $mandate] = $this->orderWithMandate();

        $this->sendEvent([
            'id' => 'evt_2',
            'type' => 'setup_intent.setup_failed',
            'data' => ['object' => [
                'id' => 'seti_known',
                'status' => 'requires_payment_method',
                'last_setup_error' => ['message' => 'Your card was declined.'],
            ]],
        ])->assertOk();

        $mandate->refresh();

        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->status);
        $this->assertSame('Your card was declined.', $mandate->last_error);
    }

    /**
     * A redelivered failure for a superseded attempt must not strip a card that
     * has since been stored.
     */
    public function test_setup_failed_cannot_undo_an_already_saved_mandate(): void
    {
        [, , $mandate] = $this->orderWithMandate();
        $mandate->update(OrderPaymentMethod::factory()->saved()->make()->only([
            'status', 'payment_method_id', 'verified_at', 'offsession_authorized_at',
        ]));

        $this->sendEvent([
            'id' => 'evt_3',
            'type' => 'setup_intent.setup_failed',
            'data' => ['object' => ['id' => 'seti_known', 'last_setup_error' => ['message' => 'stale']]],
        ])->assertOk();

        $mandate->refresh();

        $this->assertSame(OrderPaymentMethod::STATUS_SAVED, $mandate->status);
        $this->assertNull($mandate->last_error);
    }

    // ---- payment_method.detached -------------------------------------------

    private function detachedEvent(string $paymentMethodId, ?string $customerId): array
    {
        return [
            'id' => 'evt_4',
            'type' => 'payment_method.detached',
            'data' => ['object' => [
                'id' => $paymentMethodId,
                'object' => 'payment_method',
                'customer' => $customerId,
            ]],
        ];
    }

    public function test_detached_clears_the_mandate_resolved_by_payment_method_id(): void
    {
        [$owner, , $mandate] = $this->orderWithMandate();
        $mandate->forceFill(OrderPaymentMethod::factory()->saved()->make()->only([
            'status', 'payment_method_id', 'pm_brand', 'pm_last4', 'verified_at', 'offsession_authorized_at',
        ]))->save();
        $mandate->forceFill(['payment_method_id' => 'pm_detach_me'])->save();

        $this->sendEvent($this->detachedEvent('pm_detach_me', $owner->stripe_customer_id))->assertOk();

        $mandate->refresh();

        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->status);
        $this->assertNull($mandate->payment_method_id);
        $this->assertNull($mandate->pm_last4);
        $this->assertNull($mandate->verified_at);
        $this->assertNull($mandate->offsession_authorized_at);
    }

    /**
     * One saved card legitimately backs every order the same customer books, so
     * this resolves to a collection rather than a single row.
     */
    public function test_detached_clears_every_mandate_backed_by_that_card(): void
    {
        $owner = User::factory()->create([
            'user_type' => UserType::Privatkunde,
            'stripe_customer_id' => 'cus_shared',
        ]);

        $mandates = collect(range(1, 2))->map(function (int $i) use ($owner) {
            $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $owner->id]);
            $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

            return OrderPaymentMethod::factory()->saved()->create([
                'order_id' => $order->id,
                'stripe_customer_id' => 'cus_shared',
                'setup_intent_id' => "seti_{$i}",
                'payment_method_id' => 'pm_shared',
            ]);
        });

        $this->sendEvent($this->detachedEvent('pm_shared', 'cus_shared'))->assertOk();

        foreach ($mandates as $mandate) {
            $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->fresh()->status);
            $this->assertNull($mandate->fresh()->payment_method_id);
        }
    }

    public function test_detached_for_an_unknown_card_is_ignored_with_200(): void
    {
        [$owner, , $mandate] = $this->orderWithMandate();
        $mandate->forceFill([
            'status' => OrderPaymentMethod::STATUS_SAVED,
            'payment_method_id' => 'pm_mine',
            'verified_at' => now(),
        ])->save();

        $this->sendEvent($this->detachedEvent('pm_not_ours', $owner->stripe_customer_id))->assertOk();

        $this->assertSame(OrderPaymentMethod::STATUS_SAVED, $mandate->fresh()->status);
    }

    /**
     * Resolution by our own stored id is most of the authorization, but the
     * event's customer still has to match the order's owner.
     */
    public function test_detached_naming_another_customer_clears_nothing(): void
    {
        [, , $mandate] = $this->orderWithMandate();
        $mandate->forceFill([
            'status' => OrderPaymentMethod::STATUS_SAVED,
            'payment_method_id' => 'pm_mine',
            'verified_at' => now(),
        ])->save();

        $this->sendEvent($this->detachedEvent('pm_mine', 'cus_a_stranger'))->assertOk();

        $mandate->refresh();
        $this->assertSame(OrderPaymentMethod::STATUS_SAVED, $mandate->status);
        $this->assertSame('pm_mine', $mandate->payment_method_id);
    }

    // ---- everything else ---------------------------------------------------

    public function test_an_unhandled_event_type_is_acknowledged_and_writes_nothing(): void
    {
        [, , $mandate] = $this->orderWithMandate();

        $this->sendEvent([
            'id' => 'evt_5',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_not_handled_yet']],
        ])->assertOk();

        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->fresh()->status);
    }

    public function test_a_malformed_event_is_acknowledged(): void
    {
        $this->sendEvent(['id' => 'evt_6', 'type' => 'setup_intent.succeeded'])->assertOk();
    }
}
