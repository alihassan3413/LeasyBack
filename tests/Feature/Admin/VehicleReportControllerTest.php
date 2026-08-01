<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    public function test_admin_can_upload_a_report_document(): void
    {
        Storage::fake('documents');
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => 'AUF-12345678',
                'document_type' => 'invoice',
                'document_title' => 'Rechnung',
                'published' => true,
                'file' => UploadedFile::fake()->create('rechnung.pdf', 100),
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('vehicle_report_documents', [
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => 'AUF-12345678',
            'published' => true,
        ]);
        // vehicle_report_document_logs existed with zero consumers before
        // Checkpoint 12 (docs/B2C_ADMIN_MIGRATION_AUDIT.md's "dead schema"
        // item) — VehicleReportService is now its consumer.
        $this->assertDatabaseHas('vehicle_report_document_logs', [
            'vehicle_id' => $vehicle->vehicle_id,
            'action' => 'uploaded',
        ]);
    }

    public function test_non_admin_cannot_upload_a_report_document(): void
    {
        Storage::fake('documents');
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => 'AUF-12345678',
                'file' => UploadedFile::fake()->create('rechnung.pdf', 100),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('vehicle_report_documents', ['vehicle_id' => $vehicle->vehicle_id]);
    }

    public function test_admin_can_publish_a_report_document(): void
    {
        $admin = $this->admin();
        $document = VehicleReportDocument::factory()->create(['published' => false]);

        $this->actingAs($admin)
            ->patch(route('admin.vehicles.reports.publish', $document->id), ['published' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('vehicle_report_documents', ['id' => $document->id, 'published' => true]);
        $this->assertDatabaseHas('vehicle_report_document_logs', ['document_id' => $document->id, 'action' => 'published']);
    }

    public function test_non_admin_cannot_publish_a_report_document(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $document = VehicleReportDocument::factory()->create(['published' => false]);

        $this->actingAs($user)
            ->patch(route('admin.vehicles.reports.publish', $document->id), ['published' => true])
            ->assertForbidden();

        $this->assertDatabaseHas('vehicle_report_documents', ['id' => $document->id, 'published' => false]);
    }

    public function test_admin_can_delete_a_report_document(): void
    {
        Storage::fake('documents');
        $admin = $this->admin();
        $document = VehicleReportDocument::factory()->create();

        $documentId = $document->id;

        $this->actingAs($admin)
            ->delete(route('admin.vehicles.reports.delete', $document->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('vehicle_report_documents', ['id' => $documentId]);
        $this->assertDatabaseHas('vehicle_report_document_logs', ['document_id' => $documentId, 'action' => 'deleted']);
    }

    public function test_non_admin_cannot_delete_a_report_document(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $document = VehicleReportDocument::factory()->create();

        $this->actingAs($user)
            ->delete(route('admin.vehicles.reports.delete', $document->id))
            ->assertForbidden();

        $this->assertDatabaseHas('vehicle_report_documents', ['id' => $document->id]);
    }
}
