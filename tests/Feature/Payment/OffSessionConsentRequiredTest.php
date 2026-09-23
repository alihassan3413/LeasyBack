<?php

namespace Tests\Feature\Payment;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Regression: the payment step shipped with `offsession_authorized: true`
 * hardcoded in the confirm payload, so the server's `accepted` rule could never
 * fire and the client-side checkbox was the only thing enforcing consent.
 *
 * These tests pin the server side of that: everything else about the SetupIntent
 * is valid — succeeded, right customer, right metadata — and consent alone
 * decides whether a mandate is recorded.
 */
class OffSessionConsentRequiredTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    /**
     * A customer sitting on the payment step with a card already confirmed at
     * Stripe — exactly the state the browser is in when "Zahlungsmethode
     * speichern" is clicked.
     *
     * @return array{0: User, 1: LeasybackOrder, 2: OrderPaymentMethod}
     */
    private function customerAtPaymentStep(): array
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs($owner)->postJson(route('payments.method.intent', $order->id))->assertOk();

        $mandate = OrderPaymentMethod::where('order_id', $order->id)->firstOrFail();

        $this->stripe->givenSucceededSetupIntent(
            $mandate->setup_intent_id,
            $owner->fresh()->stripe_customer_id,
            'pm_card_confirmed_at_stripe',
            ['order_id' => $order->id, 'user_id' => (string) $owner->id],
        );

        return [$owner, $order, $mandate];
    }

    private function assertNoMandateRecorded(OrderPaymentMethod $mandate): void
    {
        $mandate->refresh();

        $this->assertNull($mandate->offsession_authorized_at);
        $this->assertNull($mandate->authorization_version);
        $this->assertNull($mandate->authorization_text_hash);
        $this->assertNull($mandate->authorized_ip);
        $this->assertNull($mandate->authorized_user_agent);

        // Nothing else may have been written either — a card without a mandate
        // is a card we have no permission to use.
        $this->assertNull($mandate->verified_at);
        $this->assertNull($mandate->payment_method_id);
        $this->assertSame(OrderPaymentMethod::STATUS_AWAITING_METHOD, $mandate->status);

        $this->assertFalse($mandate->isUsable());
        $this->assertFalse($mandate->isChargeableOffSession());
    }

    /**
     * The reported case: Save clicked with the box unticked. Everything at
     * Stripe is in order, so only the consent rule stands between the request
     * and a stored mandate.
     */
    public function test_confirm_without_consent_is_rejected_and_stores_nothing(): void
    {
        [$owner, $order, $mandate] = $this->customerAtPaymentStep();

        $this->actingAs($owner)
            ->postJson(route('payments.method.confirm', $order->id), [
                'setup_intent_id' => $mandate->setup_intent_id,
                'offsession_authorized' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('offsession_authorized');

        $this->assertNoMandateRecorded($mandate);
    }

    /**
     * Every shape a "not accepted" consent can arrive as, including the field
     * being absent entirely — which is what a client that simply stopped
     * sending it would produce.
     *
     * @param  array<string, mixed>  $consentPayload
     */
    #[DataProvider('unacceptedConsentPayloads')]
    public function test_every_form_of_missing_consent_is_rejected(array $consentPayload): void
    {
        [$owner, $order, $mandate] = $this->customerAtPaymentStep();

        $this->actingAs($owner)
            ->postJson(route('payments.method.confirm', $order->id), [
                'setup_intent_id' => $mandate->setup_intent_id,
                ...$consentPayload,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('offsession_authorized');

        $this->assertNoMandateRecorded($mandate);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unacceptedConsentPayloads(): array
    {
        return [
            'omitted entirely' => [[]],
            'boolean false' => [['offsession_authorized' => false]],
            'null' => [['offsession_authorized' => null]],
            'integer zero' => [['offsession_authorized' => 0]],
            'string zero' => [['offsession_authorized' => '0']],
            'string false' => [['offsession_authorized' => 'false']],
            'empty string' => [['offsession_authorized' => '']],
        ];
    }

    /**
     * The rejection must not be a one-shot: a client that keeps retrying
     * without consent keeps being refused, rather than the second attempt
     * finding a half-written row it can complete.
     */
    public function test_repeated_attempts_without_consent_never_accumulate_into_a_mandate(): void
    {
        [$owner, $order, $mandate] = $this->customerAtPaymentStep();

        foreach (range(1, 3) as $_) {
            $this->actingAs($owner)
                ->postJson(route('payments.method.confirm', $order->id), [
                    'setup_intent_id' => $mandate->setup_intent_id,
                    'offsession_authorized' => false,
                ])
                ->assertStatus(422);
        }

        $this->assertNoMandateRecorded($mandate);
    }

    /**
     * A refused confirm must leave the order exactly as unpayable as before, so
     * the customer is sent back to the payment step rather than waved through.
     */
    public function test_a_refused_confirm_leaves_the_order_still_requiring_a_payment_method(): void
    {
        [$owner, $order, $mandate] = $this->customerAtPaymentStep();

        $this->actingAs($owner)->postJson(route('payments.method.confirm', $order->id), [
            'setup_intent_id' => $mandate->setup_intent_id,
            'offsession_authorized' => false,
        ])->assertStatus(422);

        $this->actingAs($owner)
            ->getJson(route('payments.method.show', $order->id))
            ->assertOk()
            ->assertJsonPath('usable', false)
            ->assertJsonPath('verified', false)
            ->assertJsonPath('authorized', false)
            ->assertJsonPath('card', null);
    }

    /**
     * The positive control, so the tests above cannot pass by simply breaking
     * the endpoint: the identical request with consent given does record the
     * mandate, and records the wording it was given.
     */
    public function test_the_same_request_with_consent_records_the_mandate(): void
    {
        [$owner, $order, $mandate] = $this->customerAtPaymentStep();

        $this->actingAs($owner)
            ->postJson(route('payments.method.confirm', $order->id), [
                'setup_intent_id' => $mandate->setup_intent_id,
                'offsession_authorized' => true,
            ])
            ->assertOk();

        $mandate->refresh();

        $this->assertNotNull($mandate->offsession_authorized_at);
        $this->assertNotNull($mandate->authorization_version);
        $this->assertNotNull($mandate->authorization_text_hash);
        $this->assertSame('pm_card_confirmed_at_stripe', $mandate->payment_method_id);
        $this->assertTrue($mandate->isChargeableOffSession());
    }
}
