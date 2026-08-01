<?php

namespace Tests\Feature\Policies;

use App\Enums\UserType;
use App\Models\User;
use App\Models\VehicleReportDocument as VehicleReportDocumentShim;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VehicleReportDocumentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_non_admin_cannot_upload_report_document(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create();

        $response = $this->withHeaders($this->bearer($user))
            ->post('/admin/vehicle/report/upload', [
                'auftragsnummer' => 'AUF-12345678',
                'vehicle_id' => $vehicle->vehicle_id,
                'file' => UploadedFile::fake()->create('invoice.pdf', 10),
            ]);

        $response->assertForbidden();
    }

    public function test_admin_can_upload_report_document(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $vehicle = Vehicle::factory()->create();

        $response = $this->withHeaders($this->bearer($admin))
            ->post('/admin/vehicle/report/upload', [
                'auftragsnummer' => 'AUF-12345678',
                'vehicle_id' => $vehicle->vehicle_id,
                'file' => UploadedFile::fake()->create('invoice.pdf', 10),
            ]);

        $response->assertOk();
    }

    public function test_non_admin_cannot_publish_report_document(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $document = VehicleReportDocument::factory()->create();

        $response = $this->withHeaders($this->bearer($user))
            ->patchJson("/admin/vehicle/report/publish/{$document->id}", ['published' => true]);

        $response->assertForbidden();
        $this->assertFalse($document->fresh()->published);
    }

    public function test_non_admin_cannot_delete_report_document(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $document = VehicleReportDocument::factory()->create();

        $response = $this->withHeaders($this->bearer($user))
            ->deleteJson("/admin/vehicle/report/delete/{$document->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('vehicle_report_documents', ['id' => $document->id]);
    }

    public function test_view_ability_requires_published_or_admin(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $unpublished = VehicleReportDocument::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        $published = VehicleReportDocument::factory()->published()->create(['vehicle_id' => $vehicle->vehicle_id]);

        // No live route exercises this ability yet — asserted directly
        // against the Policy, same as the checks a future customer-facing
        // "view my report documents" endpoint would perform. Re-fetched
        // through the App\Models shim (as every real controller does) since
        // the Policy type-hints that class specifically.
        $this->assertFalse($owner->can('view', VehicleReportDocumentShim::find($unpublished->id)));
        $this->assertTrue($owner->can('view', VehicleReportDocumentShim::find($published->id)));
        $this->assertTrue($admin->can('view', VehicleReportDocumentShim::find($unpublished->id)));
    }

    /**
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Vehicle Document row: Werkstatt
     * is ❌ even for a published report document (no vehicle relationship
     * modeled for workshops at all) — distinct from the owner/admin cases
     * above, which turn on the `published` flag.
     */
    public function test_werkstatt_cannot_view_even_a_published_report_document(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $werkstatt = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $published = VehicleReportDocument::factory()->published()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->assertFalse($werkstatt->can('view', VehicleReportDocumentShim::find($published->id)));
    }
}
