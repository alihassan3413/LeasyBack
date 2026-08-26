<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\Address;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\InspectionStation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * The two places the payment step is reached from: the onboarding wizard and
 * the dashboard "start return process" modal.
 *
 * Both decide server-side whether to ask for a card — via the `order_created`
 * flash — so the browser never has to work it out for itself.
 */
class PaymentStepEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    private function station(string $provider = 'dekra'): InspectionStation
    {
        return InspectionStation::factory()->create(['provider' => $provider, 'is_active' => true]);
    }

    private function privateCustomer(): User
    {
        return User::factory()->create(['user_type' => UserType::Privatkunde]);
    }

    private function b2cVehicle(User $owner): Vehicle
    {
        return Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $owner->id]);
    }

    private function bookingPayload(InspectionStation $station): array
    {
        return [
            'station_id' => $station->station_id,
            'termin' => '2026-09-01T10:00:00+02:00',
        ];
    }

    // ---- dashboard "start return process" ---------------------------------

    public function test_booking_from_the_dashboard_flashes_the_new_order_and_asks_for_a_card(): void
    {
        $owner = $this->privateCustomer();
        $vehicle = $this->b2cVehicle($owner);
        $station = $this->station();

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('orders.store', $vehicle->vehicle_id), $this->bookingPayload($station))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('order_created', function (array $flash) {
                return $flash['requires_payment_method'] === true && ! empty($flash['order_id']);
            });
    }

    /**
     * The behaviour asked for explicitly: a customer who already has a saved,
     * verified, authorized card is not asked for it again.
     */
    public function test_a_customer_with_a_usable_mandate_is_not_asked_for_a_card_again(): void
    {
        $owner = $this->privateCustomer();
        $firstVehicle = $this->b2cVehicle($owner);
        $station = $this->station();

        $firstOrder = LeasybackOrder::factory()->create([
            'vehicle_id' => $firstVehicle->vehicle_id,
            'order_status' => OrderStatus::Completed->value,
        ]);
        OrderPaymentMethod::factory()->saved()->create(['order_id' => $firstOrder->id]);

        // A second return, on a second vehicle, reusing the stored card.
        $secondVehicle = $this->b2cVehicle($owner);

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('orders.store', $secondVehicle->vehicle_id), $this->bookingPayload($station));

        $newOrderId = session('order_created')['order_id'];

        // The new order has no mandate of its own yet, so it does ask...
        $this->assertTrue(session('order_created')['requires_payment_method']);

        // ...and once that order's mandate is usable, the step skips itself.
        OrderPaymentMethod::where('order_id', $newOrderId)->delete();
        OrderPaymentMethod::factory()->saved()->create(['order_id' => $newOrderId]);

        $this->actingAs($owner)
            ->getJson(route('payments.method.show', $newOrderId))
            ->assertOk()
            ->assertJsonPath('usable', true)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('authorized', true);
    }

    public function test_a_b2b_collection_booking_never_asks_for_a_card(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => null]);
        $address = Address::factory()->create();

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'requested_collection_date' => '2026-09-01',
                'collection_address' => [
                    'street' => $address->street ?? 'Teststr.',
                    'zip_code' => '80331',
                    'city' => 'München',
                ],
            ]);

        $this->assertNull(session('order_created'));
    }

    // ---- onboarding wizard step 4 ------------------------------------------

    public function test_the_wizard_exposes_the_order_id_so_step_four_can_attach_a_card(): void
    {
        $owner = $this->privateCustomer();
        $vehicle = $this->b2cVehicle($owner);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs($owner)
            ->get(route('onboarding.show'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('onboarding/B2cRegistration')
                ->where('order.id', $order->id)
                ->where('order.auftragsnummer', $order->auftragsnummer)
            );
    }

    public function test_booking_in_the_wizard_flashes_the_order_for_the_payment_step(): void
    {
        $owner = $this->privateCustomer();
        $this->b2cVehicle($owner);
        $station = $this->station();

        $this->actingAs($owner)
            ->post(route('onboarding.appointment.store'), $this->bookingPayload($station))
            ->assertRedirect(route('onboarding.show'))
            ->assertSessionHas('order_created', fn (array $flash) => $flash['requires_payment_method'] === true);
    }

    // ---- the mandate-state endpoint the step reads on mount ----------------

    public function test_mandate_state_reports_awaiting_for_a_fresh_order(): void
    {
        $owner = $this->privateCustomer();
        $vehicle = $this->b2cVehicle($owner);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs($owner)
            ->getJson(route('payments.method.show', $order->id))
            ->assertOk()
            ->assertJson([
                'status' => OrderPaymentMethod::STATUS_AWAITING_METHOD,
                'usable' => false,
                'verified' => false,
                'authorized' => false,
                'card' => null,
            ]);
    }

    /**
     * A verified card with no recorded consent is not usable, so the step must
     * still collect one rather than waving the customer through.
     */
    public function test_a_verified_but_unauthorized_mandate_is_not_usable(): void
    {
        $owner = $this->privateCustomer();
        $vehicle = $this->b2cVehicle($owner);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        OrderPaymentMethod::factory()->withoutAuthorization()->create(['order_id' => $order->id]);

        $this->actingAs($owner)
            ->getJson(route('payments.method.show', $order->id))
            ->assertOk()
            ->assertJsonPath('usable', false)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('authorized', false);
    }

    /**
     * Only the four display fields ever leave the server.
     */
    public function test_mandate_state_exposes_only_safe_card_details(): void
    {
        $owner = $this->privateCustomer();
        $vehicle = $this->b2cVehicle($owner);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        OrderPaymentMethod::factory()->saved()->create(['order_id' => $order->id]);

        $response = $this->actingAs($owner)->getJson(route('payments.method.show', $order->id))->assertOk();

        $this->assertSame(
            ['brand', 'last4', 'exp_month', 'exp_year'],
            array_keys($response->json('card')),
        );
        $this->assertSame('visa', $response->json('card.brand'));
        $this->assertSame('4242', $response->json('card.last4'));
    }

    public function test_mandate_state_is_404_for_a_non_owner(): void
    {
        $owner = $this->privateCustomer();
        $vehicle = $this->b2cVehicle($owner);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs($this->privateCustomer())
            ->getJson(route('payments.method.show', $order->id))
            ->assertNotFound();
    }

    public function test_mandate_state_is_404_for_an_admin(): void
    {
        $owner = $this->privateCustomer();
        $vehicle = $this->b2cVehicle($owner);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs(User::factory()->create(['user_type' => UserType::Admin]))
            ->getJson(route('payments.method.show', $order->id))
            ->assertNotFound();
    }
}
