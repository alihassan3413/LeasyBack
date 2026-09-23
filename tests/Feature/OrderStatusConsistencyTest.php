<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\InspectionStation;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * QA Bug 11: the same B2C order read "Angefragt" in Admin and "Bestellt" on
 * the customer dashboard. `leasyback_orders.order_status` is the single
 * canonical state (docs/B2C_ADMIN_STATUS_MATRIX.md §1), so both surfaces must
 * receive the identical value and derive their wording from it — the labels
 * themselves now come from one shared map in resources/js/lib/vehicleStatus.ts.
 */
class OrderStatusConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function createB2cOrder(User $owner, Vehicle $vehicle): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ])
            ->assertRedirect(route('dashboard'));
    }

    /**
     * A Privatkunde booking is placed straight away — `order_requested` is the
     * B2B staging state and must never be the initial B2C one.
     */
    public function test_a_new_b2c_order_is_persisted_as_order_placed(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'vehicle_belongs' => 'B2C']);

        $this->createB2cOrder($owner, $vehicle);

        $this->assertDatabaseHas('leasyback_orders', [
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_placed',
        ]);

        $this->assertDatabaseMissing('leasyback_orders', [
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_requested',
        ]);
    }

    public function test_admin_and_b2c_surfaces_receive_the_same_order_status(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'vehicle_belongs' => 'B2C']);

        $this->createB2cOrder($owner, $vehicle);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicles.0.orders.0.order_status', 'order_placed')
            );

        $this->actingAs($owner)
            ->get(route('vehicles.show', $vehicle->vehicle_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.orders.0.order_status', 'order_placed')
            );

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.order_status', 'order_placed')
            );

        $this->actingAs($admin)
            ->get(route('admin.vehicles.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicles.data.0.current_order_status', 'order_placed')
            );
    }
}
