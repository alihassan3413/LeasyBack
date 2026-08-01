<?php

namespace Tests\Feature\Policies;

use App\Enums\UserType;
use App\Models\LeasybackOrder;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder as CanonicalOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Re-fetched through the App\Models shim (as every real controller
     * does) since OrderPolicy type-hints that class specifically.
     */
    private function orderFor(int $ownerId): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $ownerId]);
        $created = CanonicalOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        return LeasybackOrder::find($created->id);
    }

    public function test_owner_can_view_own_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $order = $this->orderFor($owner->id);

        $this->assertTrue($owner->can('view', $order));
    }

    public function test_non_owner_cannot_view_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $order = $this->orderFor($owner->id);

        $this->assertFalse($stranger->can('view', $order));
    }

    public function test_admin_can_view_any_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $order = $this->orderFor($owner->id);

        $this->assertTrue($admin->can('view', $order));
    }

    /**
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Order row: Werkstatt is ❌
     * across the board (no order↔workshop assignment modeled currently).
     */
    public function test_werkstatt_cannot_view_any_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $werkstatt = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $order = $this->orderFor($owner->id);

        $this->assertFalse($werkstatt->can('view', $order));
    }

    public function test_only_admin_can_approve_confirm_manage_status_or_create_station(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $werkstatt = User::factory()->create(['user_type' => UserType::Werkstatt]);

        foreach (['approve', 'confirm', 'manageStatus', 'createStation'] as $ability) {
            $this->assertTrue($admin->can($ability, LeasybackOrder::class), "Admin should be able to {$ability}");
            $this->assertFalse($customer->can($ability, LeasybackOrder::class), "Customer should not be able to {$ability}");
            $this->assertFalse($werkstatt->can($ability, LeasybackOrder::class), "Werkstatt should not be able to {$ability}");
        }
    }
}
