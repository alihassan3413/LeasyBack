<?php

namespace Tests\Feature\Policies;

use App\Enums\UserType;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\Vehicle as CanonicalVehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehiclePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Re-fetched through the App\Models shim (as every real controller
     * does, e.g. VehicleController's `use App\Models\Vehicle;`) since
     * VehiclePolicy type-hints that class specifically.
     */
    private function vehicleFor(int $ownerId): Vehicle
    {
        $created = CanonicalVehicle::factory()->create(['b2c_user_id' => $ownerId]);

        return Vehicle::find($created->vehicle_id);
    }

    public function test_owner_can_view_and_update_own_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = $this->vehicleFor($owner->id);

        $this->assertTrue($owner->can('view', $vehicle));
        $this->assertTrue($owner->can('update', $vehicle));
    }

    public function test_non_owner_cannot_view_or_update_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = $this->vehicleFor($owner->id);

        $this->assertFalse($stranger->can('view', $vehicle));
        $this->assertFalse($stranger->can('update', $vehicle));
    }

    public function test_admin_can_view_and_update_any_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $vehicle = $this->vehicleFor($owner->id);

        $this->assertTrue($admin->can('view', $vehicle));
        $this->assertTrue($admin->can('update', $vehicle));
    }

    /**
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Vehicle row: Werkstatt is ❌
     * across the board (no vehicle relationship modeled for workshops).
     * Distinct from test_non_owner_cannot_view_or_update_vehicle above —
     * that proves ownership scoping; this proves Werkstatt specifically
     * hits VehicleScopeService::scopeQuery()'s `default => deny` branch,
     * not just "isn't the owner."
     */
    public function test_werkstatt_cannot_view_or_update_any_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $werkstatt = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $vehicle = $this->vehicleFor($owner->id);

        $this->assertFalse($werkstatt->can('view', $vehicle));
        $this->assertFalse($werkstatt->can('update', $vehicle));
    }
}
