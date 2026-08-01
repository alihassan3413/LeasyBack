<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for docs/B2C_ADMIN_MIGRATION_AUDIT.md's flagged item:
 * verify Laravel actually enforces the reference system's documented 10 MB
 * size cap and pdf/jpg/jpeg/png allow-list on vehicle document uploads —
 * not just that the validation rule exists in the source.
 */
class VehicleDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_upload_accepts_an_allowed_file_within_the_size_limit(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $response = $this->withHeaders($this->bearer($owner))
            ->putJson("/vehicle/{$vehicle->vehicle_id}/documents", [
                'file' => UploadedFile::fake()->create('vertrag.pdf', 5000),
                'document_type' => 'Leasingvertrag',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('vehicle_documents', ['vehicle_id' => $vehicle->vehicle_id]);
    }

    public function test_upload_rejects_a_file_over_the_10mb_cap(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $response = $this->withHeaders($this->bearer($owner))
            ->putJson("/vehicle/{$vehicle->vehicle_id}/documents", [
                'file' => UploadedFile::fake()->create('too-big.pdf', 10241),
                'document_type' => 'Leasingvertrag',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertDatabaseMissing('vehicle_documents', ['vehicle_id' => $vehicle->vehicle_id]);
    }

    public function test_upload_rejects_a_disallowed_file_type(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $response = $this->withHeaders($this->bearer($owner))
            ->putJson("/vehicle/{$vehicle->vehicle_id}/documents", [
                'file' => UploadedFile::fake()->create('script.exe', 100),
                'document_type' => 'Leasingvertrag',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertDatabaseMissing('vehicle_documents', ['vehicle_id' => $vehicle->vehicle_id]);
    }

    /**
     * Regression test for a real, previously-unenforced rule flagged in
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Vehicle Document row: a
     * customer upload's document_type must be restricted to
     * Leasingvertrag/vorschaden — gutachten (report) is an Admin-managed
     * type, uploaded through the separate VehicleReportService flow, not
     * something a customer may self-declare. Previously the validation
     * rule allowed it (the live frontend's own dropdown just never
     * offered it — "frontend visibility is never authorization").
     */
    public function test_upload_rejects_the_admin_only_gutachten_document_type(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);

        $response = $this->withHeaders($this->bearer($owner))
            ->putJson("/vehicle/{$vehicle->vehicle_id}/documents", [
                'file' => UploadedFile::fake()->create('gutachten.pdf', 100),
                'document_type' => 'gutachten',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['document_type']);
        $this->assertDatabaseMissing('vehicle_documents', ['vehicle_id' => $vehicle->vehicle_id]);
    }
}
