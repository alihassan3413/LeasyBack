<?php

namespace Tests\Feature\Payment;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\InspectionStation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Regression: an order Admin books for a customer correctly skips the payment
 * step for Admin, but the customer was then never told a card was still needed
 * — the order sat active with no mandate and no way to supply one.
 *
 * Walks the whole handoff: Admin books, Admin is offered nothing, the customer
 * logs in and is shown the requirement, and completing it clears it.
 */
class AdminCreatedOrderRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function customerWithVehicle(): array
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $customer->id]);

        return [$customer, $vehicle];
    }

    /**
     * Admin books on the customer's behalf, through the same route the
     * customer's own modal uses.
     */
    private function adminBooksFor(Vehicle $vehicle): string
    {
        $station = InspectionStation::factory()->create(['provider' => 'dekra', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->from(route('admin.dashboard'))
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ]);

        return LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)
            ->firstOrFail()->id;
    }

    /**
     * Storing a card is never asked of Admin — doing so would record a consent
     * the customer never gave.
     */
    public function test_admin_booking_is_never_offered_the_payment_step(): void
    {
        [, $vehicle] = $this->customerWithVehicle();

        $this->adminBooksFor($vehicle);

        $flash = session('order_created');

        $this->assertNotNull($flash);
        $this->assertFalse($flash['requires_payment_method']);
    }

    /**
     * The order carries no mandate row at all — the absence is the
     * `awaiting_method` state, and every reader has to treat it that way.
     */
    public function test_an_admin_created_order_has_no_mandate_and_reads_as_awaiting_method(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();

        $orderId = $this->adminBooksFor($vehicle);

        $this->assertDatabaseMissing('order_payment_methods', ['order_id' => $orderId]);

        $this->actingAs($customer)
            ->getJson(route('payments.method.show', $orderId))
            ->assertOk()
            ->assertJsonPath('status', OrderPaymentMethod::STATUS_AWAITING_METHOD)
            ->assertJsonPath('usable', false)
            ->assertJsonPath('card', null);
    }

    /**
     * The bug itself: the customer opens their dashboard and the order must
     * announce that a payment method is still required.
     */
    public function test_the_customer_dashboard_surfaces_the_requirement(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();
        $this->adminBooksFor($vehicle);

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicles.0.orders.0.payment.requires_setup', true)
                ->where('vehicles.0.orders.0.payment.status', OrderPaymentMethod::STATUS_AWAITING_METHOD)
                ->where('vehicles.0.orders.0.payment.card', null)
            );
    }

    /**
     * Admin looking at the same vehicle must not be shown a prompt aimed at the
     * customer, even though the order genuinely lacks a mandate.
     */
    public function test_admin_viewing_the_same_vehicle_is_shown_no_requirement(): void
    {
        [, $vehicle] = $this->customerWithVehicle();
        $orderId = $this->adminBooksFor($vehicle);

        $admin = $this->admin();
        $payload = app(VehicleService::class)
            ->findVehicleWithOrders($vehicle->vehicle_id, null, 'ALL', $admin);

        $order = collect($payload['orders'])->firstWhere('id', $orderId);

        $this->assertFalse($order['payment']['requires_setup']);
    }

    /**
     * End to end: the customer follows the prompt, and it is gone afterwards.
     */
    public function test_completing_the_step_clears_the_requirement(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();
        $orderId = $this->adminBooksFor($vehicle);

        $this->actingAs($customer)->postJson(route('payments.method.intent', $orderId))->assertOk();

        $mandate = OrderPaymentMethod::where('order_id', $orderId)->firstOrFail();
        $this->stripe->givenSucceededSetupIntent(
            $mandate->setup_intent_id,
            $customer->fresh()->stripe_customer_id,
            'pm_customer_supplied',
            ['order_id' => $orderId, 'user_id' => (string) $customer->id],
        );

        $this->actingAs($customer)
            ->postJson(route('payments.method.confirm', $orderId), [
                'setup_intent_id' => $mandate->setup_intent_id,
                'offsession_authorized' => true,
            ])
            ->assertOk();

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicles.0.orders.0.payment.requires_setup', false)
                ->where('vehicles.0.orders.0.payment.card.last4', '4242')
            );
    }

    /**
     * A half-finished attempt must keep prompting: a card verified without the
     * off-session consent is one we have no permission to use.
     */
    public function test_a_verified_but_unauthorized_mandate_still_prompts(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();
        $orderId = $this->adminBooksFor($vehicle);

        OrderPaymentMethod::factory()->withoutAuthorization()->create(['order_id' => $orderId]);

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicles.0.orders.0.payment.requires_setup', true)
                ->where('vehicles.0.orders.0.payment.card', null)
            );
    }

    /**
     * B2B has no customer-card flow, so the key must be absent entirely rather
     * than present-and-false — its presence would imply a step that does not
     * exist in that channel.
     */
    public function test_b2b_orders_carry_no_payment_key_at_all(): void
    {
        $member = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => null]);
        LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $payload = app(VehicleService::class)
            ->findVehicleWithOrders($vehicle->vehicle_id, null, 'ALL', $member);

        $this->assertArrayNotHasKey('payment', $payload['orders'][0]);
    }
}
