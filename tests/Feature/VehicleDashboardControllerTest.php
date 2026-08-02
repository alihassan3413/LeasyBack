<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class VehicleDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_owner_sees_only_their_own_vehicles(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);
        Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'license_plate' => 'K LB 1']);
        Vehicle::factory()->create(['b2c_user_id' => $stranger->id, 'license_plate' => 'K LB 2']);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('vehicles', 1)
                ->where('vehicles.0.license_plate', 'K LB 1')
            );
    }

    public function test_dashboard_includes_each_vehicles_documents(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        VehicleDocument::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'document_type' => 'Leasingvertrag']);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('vehicles.0.documents', 1)
                ->where('vehicles.0.documents.0.document_type', 'Leasingvertrag')
                ->has('vehicles.0.orders', 0)
            );
    }

    /**
     * A cancelled order used to be filtered out of the customer's dashboard
     * entirely, so an order Admin had cancelled simply disappeared — no
     * timeline, no documents, no explanation — while Admin still saw the full
     * history. The customer now sees the same order, and `auth_source` lets
     * the timeline say who cancelled it.
     */
    public function test_dashboard_still_shows_an_order_after_it_is_cancelled(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Cancelled->value,
        ]);
        OrderStatusUpdate::create([
            'auftragsnummer' => $order->auftragsnummer,
            'old_status' => 'confirmed',
            'new_status' => 'cancelled',
            'updated_by' => 'admin@leasyback.test',
            'auth_source' => 'admin',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('vehicles.0.orders', 1)
                ->where('vehicles.0.orders.0.order_status', 'cancelled')
                ->where('vehicles.0.orders.0.status_updates.0.new_status', 'cancelled')
                ->where('vehicles.0.orders.0.status_updates.0.auth_source', 'admin')
            );
    }

    /** `updated_by` can name a staff member, so it stays out of the customer payload. */
    public function test_dashboard_does_not_expose_who_changed_the_status_by_name(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        OrderStatusUpdate::create([
            'auftragsnummer' => $order->auftragsnummer,
            'old_status' => 'order_placed',
            'new_status' => 'confirmed',
            'updated_by' => 'admin@leasyback.test',
            'auth_source' => 'admin',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('vehicles.0.orders.0.status_updates.0.updated_by')
            );
    }

    /**
     * The dashboard's status filter reads the latest order. It used to skip
     * cancelled ones, so a vehicle cancelled after an earlier delivered order
     * kept advertising "delivered" to its owner.
     */
    public function test_vehicle_status_reflects_a_cancelled_latest_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Delivered->value,
            'created_at' => now()->subDays(5),
        ]);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Cancelled->value,
            'created_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard', ['status' => 'cancelled']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('vehicles', 1)
                ->where('vehicles.0.orders.0.order_status', 'cancelled')
            );
    }

    public function test_owner_can_create_a_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('vehicles.store'), [
                'license_plate' => 'K LB 2026',
                'make' => 'Volkswagen',
                'model' => 'Golf',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('vehicles', [
            'license_plate' => 'K LB 2026',
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $owner->id,
        ]);
    }

    public function test_owner_can_update_own_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->patch(route('vehicles.update', $vehicle->vehicle_id), ['make' => 'Volkswagen'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame('Volkswagen', $vehicle->fresh()->make);
    }

    public function test_non_owner_cannot_update_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'make' => 'Original']);

        $this->actingAs($intruder)
            ->from(route('dashboard'))
            ->patch(route('vehicles.update', $vehicle->vehicle_id), ['make' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Original', $vehicle->fresh()->make);
    }
}
