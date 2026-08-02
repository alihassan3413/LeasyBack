<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
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
