<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Data\StripeSetupIntentResult;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Support\PaymentConsentText;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Storing a payment method: who may do it, and what the server insists on
 * seeing before it believes a card was stored.
 *
 * The theme throughout is that the browser is not a source of truth. Every
 * assertion about a rejected SetupIntent is really an assertion that posting an
 * id is not enough to make this application believe anything.
 */
class SetupIntentFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    private function b2cOrder(?User $owner = null): array
    {
        $owner ??= User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $owner->id,
        ]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        return [$owner, $order];
    }

    // ---- opening a SetupIntent -------------------------------------------

    public function test_owner_can_open_a_setup_intent_and_no_charge_is_created(): void
    {
        [$owner, $order] = $this->b2cOrder();

        $this->actingAs($owner)
            ->postJson(route('payments.method.intent', $order->id))
            ->assertOk()
            ->assertJsonStructure(['client_secret', 'setup_intent_id', 'authorization_text']);

        // The entire point of this step: a method is stored, nothing is billed.
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(1, $this->stripe->countCallsTo('createSetupIntent'));
    }

    /**
     * A second Stripe Customer for the same person would orphan the card they
     * already saved and bill them as a stranger.
     */
    public function test_a_stripe_customer_is_created_once_and_reused(): void
    {
        [$owner, $firstOrder] = $this->b2cOrder();
        [, $secondOrder] = $this->b2cOrder($owner);

        $this->actingAs($owner)->postJson(route('payments.method.intent', $firstOrder->id))->assertOk();
        $this->actingAs($owner)->postJson(route('payments.method.intent', $secondOrder->id))->assertOk();

        $this->assertSame(1, $this->stripe->countCallsTo('createCustomer'));
        $this->assertNotNull($owner->fresh()->stripe_customer_id);
    }

    /**
     * Reloading the payment step must land on the same intent, not accumulate
     * one per refresh.
     */
    public function test_an_unconsumed_setup_intent_is_reused_rather_than_replaced(): void
    {
        [$owner, $order] = $this->b2cOrder();

        $first = $this->actingAs($owner)->postJson(route('payments.method.intent', $order->id))->json('setup_intent_id');
        $second = $this->actingAs($owner)->postJson(route('payments.method.intent', $order->id))->json('setup_intent_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->stripe->countCallsTo('createSetupIntent'));
    }

    /**
     * The metadata is what the webhook path later checks the event against, so
     * an intent created without it could never be verified.
     */
    public function test_the_setup_intent_carries_the_order_and_user_in_metadata(): void
    {
        [$owner, $order] = $this->b2cOrder();

        $this->actingAs($owner)->postJson(route('payments.method.intent', $order->id))->assertOk();

        $metadata = $this->stripe->lastCallTo('createSetupIntent')['arguments']['metadata'];

        $this->assertSame($order->id, $metadata['order_id']);
        $this->assertSame((string) $owner->id, $metadata['user_id']);
        $this->assertSame($order->auftragsnummer, $metadata['auftragsnummer']);
    }

    // ---- who may reach these endpoints ------------------------------------

    public function test_a_non_owner_gets_404_not_403(): void
    {
        [, $order] = $this->b2cOrder();
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($stranger)
            ->postJson(route('payments.method.intent', $order->id))
            ->assertNotFound();
    }

    /**
     * The one place this policy departs from every other order ability.
     * Entering a card on someone's behalf would record a mandate attesting to a
     * consent that never happened.
     */
    public function test_admin_may_not_store_a_card_on_a_customers_behalf(): void
    {
        [, $order] = $this->b2cOrder();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)
            ->postJson(route('payments.method.intent', $order->id))
            ->assertNotFound();
    }

    public function test_a_b2b_order_has_no_payment_method_endpoint(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => null]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs($owner)
            ->postJson(route('payments.method.intent', $order->id))
            ->assertNotFound();
    }

    public function test_a_closed_order_accepts_no_new_payment_method(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $order->update(['order_status' => OrderStatus::Cancelled->value]);

        $this->actingAs($owner)
            ->postJson(route('payments.method.intent', $order->id))
            ->assertNotFound();
    }

    // ---- confirmation is verified server-side -----------------------------

    public function test_confirming_a_succeeded_intent_stores_the_mandate_and_the_authorization(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $this->actingAs($owner)->postJson(route('payments.method.intent', $order->id));

        $mandate = OrderPaymentMethod::where('order_id', $order->id)->firstOrFail();
        $this->stripe->givenSucceededSetupIntent(
            $mandate->setup_intent_id,
            $owner->fresh()->stripe_customer_id,
            'pm_stored',
            ['order_id' => $order->id, 'user_id' => (string) $owner->id],
        );

        $this->actingAs($owner)
            ->postJson(route('payments.method.confirm', $order->id), [
                'setup_intent_id' => $mandate->setup_intent_id,
                'offsession_authorized' => true,
            ])
            ->assertOk()
            ->assertJsonPath('card.last4', '4242');

        $mandate->refresh();

        $this->assertTrue($mandate->isChargeableOffSession());
        $this->assertNotNull($mandate->verified_at);
        $this->assertNotNull($mandate->offsession_authorized_at);
        $this->assertSame(
            hash('sha256', PaymentConsentText::offSessionAuthorization()),
            $mandate->authorization_text_hash,
        );
        $this->assertSame(config('payments.authorization_version'), $mandate->authorization_version);
    }

    /**
     * The core of the "do not trust the browser" rule: a client can report
     * success for an intent that never succeeded, and the server has to catch
     * it by reading the intent back.
     */
    public function test_an_unsucceeded_intent_is_refused_and_stores_nothing(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $this->actingAs($owner)->postJson(route('payments.method.intent', $order->id));
        $mandate = OrderPaymentMethod::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($owner)
            ->postJson(route('payments.method.confirm', $order->id), [
                'setup_intent_id' => $mandate->setup_intent_id,
                'offsession_authorized' => true,
            ])
            ->assertStatus(422);

        $mandate->refresh();
        $this->assertNull($mandate->verified_at);
        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->status);
    }

    /**
     * Knowing another customer's SetupIntent id must not be enough to attach
     * it to an order you own.
     */
    public function test_a_setup_intent_belonging_to_another_customer_is_refused(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $this->actingAs($owner)->postJson(route('payments.method.intent', $order->id));
        $mandate = OrderPaymentMethod::where('order_id', $order->id)->firstOrFail();

        $this->stripe->givenSetupIntent(new StripeSetupIntentResult(
            id: $mandate->setup_intent_id,
            status: 'succeeded',
            customerId: 'cus_somebody_else',
            paymentMethodId: 'pm_theirs',
            metadata: ['order_id' => $order->id, 'user_id' => (string) $owner->id],
        ));

        $this->actingAs($owner)
            ->postJson(route('payments.method.confirm', $order->id), [
                'setup_intent_id' => $mandate->setup_intent_id,
                'offsession_authorized' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($mandate->fresh()->verified_at);
    }

    public function test_a_setup_intent_whose_metadata_names_another_order_is_refused(): void
    {
        [$owner, $order] = $this->b2cOrder();
        [, $otherOrder] = $this->b2cOrder($owner);
        $this->actingAs($owner)->postJson(route('payments.method.intent', $order->id));
        $mandate = OrderPaymentMethod::where('order_id', $order->id)->firstOrFail();

        $this->stripe->givenSucceededSetupIntent(
            $mandate->setup_intent_id,
            $owner->fresh()->stripe_customer_id,
            'pm_stored',
            ['order_id' => $otherOrder->id, 'user_id' => (string) $owner->id],
        );

        $this->actingAs($owner)
            ->postJson(route('payments.method.confirm', $order->id), [
                'setup_intent_id' => $mandate->setup_intent_id,
                'offsession_authorized' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($mandate->fresh()->verified_at);
    }

    /**
     * A verified card with no recorded mandate is a card this application has
     * no permission to use, so the two are written together or not at all.
     */
    public function test_without_the_offsession_consent_nothing_is_stored(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $this->actingAs($owner)->postJson(route('payments.method.intent', $order->id));
        $mandate = OrderPaymentMethod::where('order_id', $order->id)->firstOrFail();

        $this->stripe->givenSucceededSetupIntent(
            $mandate->setup_intent_id,
            $owner->fresh()->stripe_customer_id,
            'pm_stored',
            ['order_id' => $order->id, 'user_id' => (string) $owner->id],
        );

        $this->actingAs($owner)
            ->postJson(route('payments.method.confirm', $order->id), [
                'setup_intent_id' => $mandate->setup_intent_id,
                'offsession_authorized' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('offsession_authorized');

        $mandate->refresh();
        $this->assertNull($mandate->offsession_authorized_at);
        $this->assertFalse($mandate->isChargeableOffSession());
    }

    /**
     * The displayed and the recorded wording must be the same bytes, or the
     * stored hash attests to something nobody ever saw.
     */
    public function test_the_authorization_text_returned_to_the_browser_is_the_text_that_is_hashed(): void
    {
        [$owner, $order] = $this->b2cOrder();

        $shown = $this->actingAs($owner)
            ->postJson(route('payments.method.intent', $order->id))
            ->json('authorization_text');

        $mandate = OrderPaymentMethod::where('order_id', $order->id)->firstOrFail();
        $this->stripe->givenSucceededSetupIntent(
            $mandate->setup_intent_id,
            $owner->fresh()->stripe_customer_id,
            'pm_stored',
            ['order_id' => $order->id, 'user_id' => (string) $owner->id],
        );

        $this->actingAs($owner)->postJson(route('payments.method.confirm', $order->id), [
            'setup_intent_id' => $mandate->setup_intent_id,
            'offsession_authorized' => true,
        ])->assertOk();

        $this->assertSame(hash('sha256', $shown), $mandate->fresh()->authorization_text_hash);
    }

    /**
     * The disclosed figure comes from the same config the charge reads, so the
     * amount named to the customer and the amount taken cannot diverge.
     */
    public function test_the_authorization_text_names_the_configured_cancellation_fee(): void
    {
        config(['payments.cancellation_fee_cents' => 20000]);

        $this->assertStringContainsString('200,00 €', PaymentConsentText::offSessionAuthorization());
    }
}
