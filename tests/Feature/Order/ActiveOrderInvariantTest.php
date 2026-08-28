<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Models\Vehicle as ShimVehicle;
use App\Modules\UserProfile\Order\Models\InspectionStation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\OrderService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * "A vehicle has at most one active order."
 *
 * The invariant used to hold on the TÜV SÜD path and nowhere else, so the same
 * B2C vehicle could carry any number of live orders as long as the customer
 * picked a DEKRA station — 162 of the 257 in the shipped catalogue. These tests
 * pin the rule at every layer it now exists in: the service pre-check that all
 * creation paths share, the unique index that covers the race the pre-check
 * cannot, and the release of the slot when an order legitimately closes.
 */
class ActiveOrderInvariantTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private const CONFLICT = 'vehicle already has an order that cannot be replaced';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    // ---------------------------------------------------------------- B2C

    public function test_the_tuvsud_path_refuses_a_second_active_order(): void
    {
        [$customer, $vehicle] = $this->privateCustomerWithVehicle();
        $station = $this->station('tuvsud');

        app(OrderService::class)->createTuvsudOrder($vehicle, $customer, $this->tuvsudPayload($station));

        $this->assertConflict(
            fn () => app(OrderService::class)->createTuvsudOrder($vehicle, $customer, $this->tuvsudPayload($station)),
        );

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_the_other_provider_path_refuses_a_second_active_order(): void
    {
        [$customer, $vehicle] = $this->privateCustomerWithVehicle();
        $station = $this->station('dekra');

        app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherPayload($station));

        $this->assertConflict(
            fn () => app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherPayload($station)),
        );

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    /**
     * The provider a customer happens to pick must not decide whether the rule
     * applies — that asymmetry is the whole defect being closed here.
     */
    public function test_the_invariant_holds_across_providers_not_only_within_one(): void
    {
        [$customer, $vehicle] = $this->privateCustomerWithVehicle();

        app(OrderService::class)->createTuvsudOrder($vehicle, $customer, $this->tuvsudPayload($this->station('tuvsud')));

        $this->assertConflict(
            fn () => app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherPayload($this->station('dekra'))),
        );
    }

    // ------------------------------------------------------- database layer

    /**
     * The pre-check is defeated on purpose: this is what a lost race looks
     * like from the database's side, and it must be refused there too, or the
     * invariant is only ever advisory.
     */
    public function test_the_database_refuses_a_second_active_order_when_the_service_is_bypassed(): void
    {
        [, $vehicle] = $this->privateCustomerWithVehicle();

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::OrderPlaced->value,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Confirmed->value,
        ]);
    }

    /**
     * And what that same lost race looks like from the caller's side: the
     * identical 409, so no client can tell the two apart — which is also what
     * keeps the Partner API's `order_already_open` mapping correct.
     */
    public function test_a_lost_race_surfaces_as_the_same_conflict_as_the_pre_check(): void
    {
        [$customer, $vehicle] = $this->privateCustomerWithVehicle();
        $station = $this->station('dekra');

        app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherPayload($station));

        // Stands in for the other request having committed between this one's
        // check and its insert.
        $blind = Mockery::mock(VehicleService::class)->makePartial();
        $blind->shouldReceive('blocksNewOrder')->andReturnFalse();
        $this->app->instance(VehicleService::class, $blind);
        $this->app->forgetInstance(OrderService::class);

        $this->assertConflict(
            fn () => app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherPayload($station)),
        );

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    /**
     * The index carries no status knowledge of its own — the model decides
     * whether a row claims its vehicle's slot, so the two can never drift.
     */
    public function test_the_slot_claim_tracks_the_status_on_every_write(): void
    {
        [, $vehicle] = $this->privateCustomerWithVehicle();

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::OrderPlaced->value,
        ]);

        $this->assertSame($vehicle->vehicle_id, $this->slotClaim($order));

        // `delivered` means the car is ready to collect, not that the case is
        // over, so it keeps its claim — the vehicle is not free for a new
        // order until someone has actually picked the car up.
        $order->update(['order_status' => OrderStatus::Delivered->value]);
        $this->assertSame($vehicle->vehicle_id, $this->slotClaim($order));

        $order->update(['order_status' => OrderStatus::Completed->value]);
        $this->assertNull($this->slotClaim($order));
    }

    // ------------------------------------------------------ releasing the slot

    /**
     * Only an order that produced nothing frees the vehicle. Closed is no
     * longer the test — `completed` is closed and must keep its claim, or a
     * car that has already been through the process could be put through it
     * again.
     */
    public function test_a_vehicle_can_order_again_after_each_reorderable_status(): void
    {
        foreach (OrderStatus::reorderableValues() as $terminal) {
            [$customer, $vehicle] = $this->privateCustomerWithVehicle();
            $station = $this->station('dekra');

            $first = app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherPayload($station));
            $first->update(['order_status' => $terminal]);

            $second = app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherPayload($station));

            $this->assertNotSame($first->id, $second->id, "status [{$terminal}] did not release the vehicle");
            $this->assertSame(2, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
        }
    }

    /**
     * The other half of the same rule, and the change this pins: a successful
     * case closes the vehicle out for good. Asserted through the service, so
     * it holds for every caller that reaches a creation method.
     */
    public function test_a_completed_order_never_frees_the_vehicle(): void
    {
        [$customer, $vehicle] = $this->privateCustomerWithVehicle();
        $station = $this->station('dekra');

        $first = app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherPayload($station));
        $first->update(['order_status' => OrderStatus::Completed->value]);

        $this->assertConflict(
            fn () => app(OrderService::class)->createOtherOrder($vehicle, $customer, $this->otherPayload($station)),
        );

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    // ----------------------------------------------------------------- B2B

    public function test_the_b2b_collection_path_still_refuses_a_second_active_order(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->shim($this->makeB2bVehicle($company)->vehicle_id);

        app(OrderService::class)->createB2bCollectionOrder($vehicle, $owner, $this->collectionPayload());

        $this->assertConflict(
            fn () => app(OrderService::class)->createB2bCollectionOrder($vehicle, $owner, $this->collectionPayload()),
        );

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    /**
     * The rule is one rule, not a B2C one: a fleet vehicle whose return
     * completed is as finished as a private customer's car, and a cancelled
     * collection is as recoverable.
     */
    public function test_a_b2b_vehicle_reorders_after_cancellation_but_not_after_completion(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->shim($this->makeB2bVehicle($company)->vehicle_id);

        $first = app(OrderService::class)->createB2bCollectionOrder($vehicle, $owner, $this->collectionPayload());
        $first->update(['order_status' => OrderStatus::Cancelled->value]);

        $second = app(OrderService::class)->createB2bCollectionOrder($vehicle, $owner, $this->collectionPayload());
        $this->assertSame(2, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());

        $second->update(['order_status' => OrderStatus::Completed->value]);

        $this->assertConflict(
            fn () => app(OrderService::class)->createB2bCollectionOrder($vehicle, $owner, $this->collectionPayload()),
        );

        $this->assertSame(2, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    // --------------------------------------------------------- HTTP surfaces

    /**
     * Enforcement is server-side on every route that can reach a creation
     * method, not only on the two the audit happened to look at.
     */
    public function test_the_onboarding_wizard_refuses_a_second_active_order(): void
    {
        [$customer, $vehicle] = $this->privateCustomerWithVehicle();
        $station = $this->station('dekra');

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::OrderPlaced->value,
        ]);

        $this->actingAs($customer)
            ->from(route('onboarding.show'))
            ->post(route('onboarding.appointment.store'), [
                'station_id' => $station->station_id,
                'termin' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertRedirect(route('onboarding.show'))
            ->assertSessionHasErrors(['appointment' => self::CONFLICT]);

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_the_web_order_route_refuses_a_second_active_order(): void
    {
        [$customer, $vehicle] = $this->privateCustomerWithVehicle();
        $station = $this->station('dekra');

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::OrderPlaced->value,
        ]);

        $this->actingAs($customer)
            ->from(route('dashboard'))
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['order' => self::CONFLICT]);

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_the_sanctum_other_provider_route_refuses_a_second_active_order(): void
    {
        [$customer, $vehicle] = $this->privateCustomerWithVehicle();
        $station = $this->station('dekra');

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::OrderPlaced->value,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/others/create/'.$vehicle->vehicle_id, [
                'provider' => 'dekra',
                'station_id' => $station->station_id,
                'termin' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertStatus(409)
            ->assertJson(['error' => self::CONFLICT]);

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_the_sanctum_tuvsud_route_refuses_a_second_active_order(): void
    {
        [$customer, $vehicle] = $this->privateCustomerWithVehicle();
        $station = $this->station('tuvsud');

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::OrderPlaced->value,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/order/tuvsud/create/'.$vehicle->vehicle_id, [
                'station_id' => $station->station_id,
                'termin' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertStatus(409)
            ->assertJson(['error' => self::CONFLICT]);

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return array{0: User, 1: ShimVehicle}
     */
    private function privateCustomerWithVehicle(): array
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $customer->id,
            'b2b_id' => null,
        ]);

        return [$customer, $this->shim($vehicle->vehicle_id)];
    }

    private function station(string $provider): InspectionStation
    {
        return InspectionStation::factory()->create([
            'provider' => $provider,
            'is_active' => true,
        ]);
    }

    private function shim(string $vehicleId): ShimVehicle
    {
        return ShimVehicle::where('vehicle_id', $vehicleId)->firstOrFail();
    }

    private function slotClaim(LeasybackOrder $order): ?string
    {
        return DB::table('leasyback_orders')->where('id', $order->id)->value('active_vehicle_id');
    }

    private function assertConflict(callable $work): void
    {
        try {
            $work();
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame(self::CONFLICT, $e->getResponse()->getData(true)['error'] ?? null);

            return;
        }

        $this->fail('Expected the second order to be refused with a 409 conflict.');
    }

    /**
     * @return array<string, mixed>
     */
    private function tuvsudPayload(InspectionStation $station): array
    {
        return [
            'station_id' => $station->station_id,
            'termin' => now()->addWeek()->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function otherPayload(InspectionStation $station): array
    {
        return [
            'provider' => $station->provider,
            'station_id' => $station->station_id,
            'termin' => now()->addWeek()->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionPayload(): array
    {
        return [
            'collection_date' => now()->addWeek()->toDateString(),
            'contact_name' => 'Anna Beispiel',
            'contact_phone' => '+49 30 1234567',
        ];
    }
}
