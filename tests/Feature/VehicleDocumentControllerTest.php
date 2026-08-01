<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleDocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_upload_a_document(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('vehicles.documents.store', $vehicle->vehicle_id), [
                'file' => UploadedFile::fake()->create('vertrag.pdf', 100),
                'document_type' => 'Leasingvertrag',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('vehicle_documents', ['vehicle_id' => $vehicle->vehicle_id]);
    }

    public function test_non_owner_cannot_upload_a_document(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->actingAs($intruder)
            ->post(route('vehicles.documents.store', $vehicle->vehicle_id), [
                'file' => UploadedFile::fake()->create('vertrag.pdf', 100),
                'document_type' => 'Leasingvertrag',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('vehicle_documents', ['vehicle_id' => $vehicle->vehicle_id]);
    }

    /**
     * Regression test matching the Sanctum API's equivalent — see
     * VehicleDocumentUploadTest::test_upload_rejects_the_admin_only_gutachten_document_type().
     */
    public function test_owner_cannot_upload_the_admin_only_gutachten_document_type(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('vehicles.documents.store', $vehicle->vehicle_id), [
                'file' => UploadedFile::fake()->create('gutachten.pdf', 100),
                'document_type' => 'gutachten',
            ])
            ->assertSessionHasErrors('document_type');

        $this->assertDatabaseMissing('vehicle_documents', ['vehicle_id' => $vehicle->vehicle_id]);
    }

    public function test_owner_can_delete_a_document(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $document = VehicleDocument::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs($owner)
            ->delete(route('vehicles.documents.destroy', [$vehicle->vehicle_id, $document->document_id]))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('vehicle_documents', ['document_id' => $document->document_id]);
    }

    public function test_non_owner_cannot_delete_a_document(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $document = VehicleDocument::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs($intruder)
            ->delete(route('vehicles.documents.destroy', [$vehicle->vehicle_id, $document->document_id]))
            ->assertNotFound();

        $this->assertDatabaseHas('vehicle_documents', ['document_id' => $document->document_id]);
    }
}
