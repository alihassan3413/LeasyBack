<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Profile\Models\LeasybackUserProfile;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * Regression test for the store()/VehicleService::createVehicle()
     * refactor in Checkpoint 5 — proves the Privatkunde ownership-resolution
     * branch still assigns the vehicle to the creating user, unchanged.
     */
    public function test_privatkunde_can_create_own_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $response = $this->withHeaders($this->bearer($owner))
            ->postJson('/vehicle/create', [
                'license_plate' => 'K LB 2026',
                'make' => 'Volkswagen',
                'model' => 'Golf',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('vehicles', [
            'license_plate' => 'K LB 2026',
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $owner->id,
        ]);
    }

    public function test_owner_can_find_own_vehicle_by_owner(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->withHeaders($this->bearer($owner))
            ->getJson("/vehicle/find/{$vehicle->vehicle_id}/{$owner->id}")
            ->assertOk()
            ->assertJsonPath('vehicle_id', $vehicle->vehicle_id);
    }

    /**
     * Regression test for the fixed IDOR: findByOwner() used to run an
     * unscoped query and trust the client-supplied ownerId alone, so any
     * authenticated user could read any vehicle by pairing a guessed
     * vehicleId with its real owner_id — exactly what this attacker does.
     */
    public function test_non_owner_cannot_find_another_users_vehicle_by_owner(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $attacker = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->withHeaders($this->bearer($attacker))
            ->getJson("/vehicle/find/{$vehicle->vehicle_id}/{$owner->id}")
            ->assertNotFound();
    }

    public function test_admin_can_find_any_vehicle_by_owner(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->withHeaders($this->bearer($admin))
            ->getJson("/vehicle/find/{$vehicle->vehicle_id}/{$owner->id}")
            ->assertOk();
    }

    public function test_mismatched_owner_id_is_not_found_even_for_the_real_owner(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->withHeaders($this->bearer($owner))
            ->getJson("/vehicle/find/{$vehicle->vehicle_id}/999999")
            ->assertNotFound();
    }

    public function test_owner_can_update_own_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->withHeaders($this->bearer($owner))
            ->patchJson("/vehicle/{$vehicle->vehicle_id}", ['make' => 'Volkswagen'])
            ->assertOk()
            ->assertJsonPath('make', 'Volkswagen');
    }

    public function test_non_owner_cannot_update_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'make' => 'Original']);

        $this->withHeaders($this->bearer($intruder))
            ->patchJson("/vehicle/{$vehicle->vehicle_id}", ['make' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Original', $vehicle->fresh()->make);
    }

    /**
     * Regression test for the Checkpoint 10 StoreVehicleRequest hardening:
     * b2c_user_id/b2b_id previously had no exists: rule, so a bogus id from
     * an Admin caller would hit the DB's FK constraint raw (a QueryException,
     * not a clean validation error). docs/B2C_ADMIN_PERMISSION_MATRIX.md's
     * Vehicle `create` row requires the owner to be "validated to be a real
     * user/company".
     */
    public function test_admin_creating_a_vehicle_for_a_nonexistent_b2c_owner_gets_a_validation_error(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $response = $this->withHeaders($this->bearer($admin))
            ->postJson('/vehicle/create', [
                'license_plate' => 'K LB 2026',
                'vehicle_belongs' => 'B2C',
                'b2c_user_id' => 999999,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('b2c_user_id');
        $this->assertDatabaseMissing('vehicles', ['license_plate' => 'K LB 2026']);
    }

    public function test_admin_can_create_a_vehicle_for_a_real_b2c_owner(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $response = $this->withHeaders($this->bearer($admin))
            ->postJson('/vehicle/create', [
                'license_plate' => 'K LB 2026',
                'vehicle_belongs' => 'B2C',
                'b2c_user_id' => $customer->id,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('vehicles', [
            'license_plate' => 'K LB 2026',
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $customer->id,
        ]);
    }

    public function test_admin_creating_a_vehicle_without_vehicle_belongs_gets_a_validation_error(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->withHeaders($this->bearer($admin))
            ->postJson('/vehicle/create', ['license_plate' => 'K LB 2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_belongs');
    }

    public function test_non_owner_cannot_assign_profile_to_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->withHeaders($this->bearer($intruder))
            ->putJson("/vehicle/{$vehicle->vehicle_id}", ['profile_id' => 1])
            ->assertNotFound();

        $this->assertNull($vehicle->fresh()->assigned_profile_id);
    }

    /**
     * Regression test for a real, previously-unfixed bug flagged in
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Vehicle row (audit §3.2):
     * assignProfile() validated profile_id was an integer and nothing
     * else — any vehicle owner could attach a completely unrelated user's
     * profile to their own vehicle by guessing/incrementing an id.
     */
    public function test_owner_cannot_assign_a_profile_belonging_to_someone_else(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $strangersProfile = LeasybackUserProfile::factory()->create(['user_id' => $stranger->id]);

        $this->withHeaders($this->bearer($owner))
            ->putJson("/vehicle/{$vehicle->vehicle_id}", ['profile_id' => $strangersProfile->profile_id])
            ->assertStatus(422);

        $this->assertNull($vehicle->fresh()->assigned_profile_id);
    }

    public function test_owner_can_assign_their_own_profile(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $profile = LeasybackUserProfile::factory()->create(['user_id' => $owner->id]);

        $this->withHeaders($this->bearer($owner))
            ->putJson("/vehicle/{$vehicle->vehicle_id}", ['profile_id' => $profile->profile_id])
            ->assertOk();

        $this->assertSame($profile->profile_id, $vehicle->fresh()->assigned_profile_id);
    }

    public function test_admin_can_assign_the_real_owners_profile_on_their_behalf(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $profile = LeasybackUserProfile::factory()->create(['user_id' => $owner->id]);

        $this->withHeaders($this->bearer($admin))
            ->putJson("/vehicle/{$vehicle->vehicle_id}", ['profile_id' => $profile->profile_id])
            ->assertOk();

        $this->assertSame($profile->profile_id, $vehicle->fresh()->assigned_profile_id);
    }
}
