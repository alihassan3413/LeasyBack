<?php

namespace Tests\Feature\Policies;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleDocumentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_owner_can_list_and_view_own_vehicle_documents(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $document = VehicleDocument::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->withHeaders($this->bearer($owner))
            ->getJson("/vehicle/{$vehicle->vehicle_id}/documents")
            ->assertOk()
            ->assertJsonCount(1);

        $this->withHeaders($this->bearer($owner))
            ->getJson("/vehicle/{$vehicle->vehicle_id}/documents/{$document->document_id}")
            ->assertOk();
    }

    public function test_non_owner_cannot_list_or_view_vehicle_documents(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $document = VehicleDocument::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        // 404, not 403 — the endpoints deliberately don't reveal whether a
        // vehicle/document exists to a caller without access to it.
        $this->withHeaders($this->bearer($intruder))
            ->getJson("/vehicle/{$vehicle->vehicle_id}/documents")
            ->assertNotFound();

        $this->withHeaders($this->bearer($intruder))
            ->getJson("/vehicle/{$vehicle->vehicle_id}/documents/{$document->document_id}")
            ->assertNotFound();
    }

    public function test_non_owner_cannot_delete_vehicle_document(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $document = VehicleDocument::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->withHeaders($this->bearer($intruder))
            ->deleteJson("/vehicle/{$vehicle->vehicle_id}/documents/{$document->document_id}")
            ->assertNotFound();

        $this->assertDatabaseHas('vehicle_documents', ['document_id' => $document->document_id]);
    }

    /**
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Vehicle Document row: Werkstatt
     * is ❌ across the board.
     */
    public function test_werkstatt_cannot_list_or_view_vehicle_documents(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $werkstatt = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $document = VehicleDocument::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->withHeaders($this->bearer($werkstatt))
            ->getJson("/vehicle/{$vehicle->vehicle_id}/documents")
            ->assertNotFound();

        $this->withHeaders($this->bearer($werkstatt))
            ->getJson("/vehicle/{$vehicle->vehicle_id}/documents/{$document->document_id}")
            ->assertNotFound();
    }

    public function test_admin_can_view_any_vehicle_document(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $document = VehicleDocument::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->withHeaders($this->bearer($admin))
            ->getJson("/vehicle/{$vehicle->vehicle_id}/documents/{$document->document_id}")
            ->assertOk();
    }
}
