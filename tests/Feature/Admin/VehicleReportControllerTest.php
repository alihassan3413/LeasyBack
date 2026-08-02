<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\VehicleReportService;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Tim\Services\TimService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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
                'document_type' => 'rechnung',
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

    /**
     * The customer is told about a document exactly when it becomes visible
     * to them — never on a draft upload. Both report and invoice uploads
     * default to draft (v1 posts published=false for each), so this is the
     * normal path and it must stay silent.
     */
    public function test_uploading_a_draft_document_notifies_nobody(): void
    {
        Notification::fake();
        Storage::fake('documents');
        $admin = $this->admin();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $owner->id]);

        $this->actingAs($admin)
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => 'AUF-DRAFT-UP',
                'document_type' => 'gutachten',
                'published' => false,
                'file' => UploadedFile::fake()->create('gutachten.pdf', 100),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('vehicle_report_documents', [
            'auftragsnummer' => 'AUF-DRAFT-UP',
            'published' => false,
        ]);
        Notification::assertNothingSent();
    }

    /** …and releasing that draft is what reaches the customer. */
    public function test_publishing_a_draft_document_notifies_the_owner(): void
    {
        Notification::fake();
        Storage::fake('documents');
        $admin = $this->admin();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $owner->id]);
        $document = VehicleReportDocument::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'published' => false,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.vehicles.reports.publish', $document->id), ['published' => true])
            ->assertRedirect();

        $this->assertTrue((bool) $document->fresh()->published);
        Notification::assertSentTo($owner, SystemNotification::class);
    }

    /** Opting in at upload time still notifies — "notify when published" holds on every path. */
    public function test_uploading_an_already_published_document_notifies_the_owner(): void
    {
        Notification::fake();
        Storage::fake('documents');
        $admin = $this->admin();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $owner->id]);

        $this->actingAs($admin)
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => 'AUF-PUB-UP',
                'document_type' => 'rechnung',
                'published' => true,
                'file' => UploadedFile::fake()->create('rechnung.pdf', 100),
            ])
            ->assertRedirect();

        Notification::assertSentTo($owner, SystemNotification::class);
    }

    /** Withdrawing must not fire a second "new document" notification. */
    public function test_withdrawing_a_published_document_notifies_nobody(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $owner->id]);
        $document = VehicleReportDocument::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'published' => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.vehicles.reports.publish', $document->id), ['published' => false])
            ->assertRedirect();

        $this->assertFalse((bool) $document->fresh()->published);
        Notification::assertNothingSent();
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

    /**
     * Regression test: VehicleReportService::transfer() gated its "notify the
     * customer" call on an undefined `$published` variable, which PHP read as
     * null — so transferring a TÜV SÜD document straight to published stored
     * it correctly but silently never told the owner about it.
     */
    public function test_transferring_a_published_assessment_document_notifies_the_owner(): void
    {
        Notification::fake();
        Storage::fake('s3');
        Storage::fake('documents');
        Storage::disk('s3')->put('tim/gutachten.pdf', 'content');

        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $owner->id]);
        $sourceId = $this->assessmentDocument('AUF-TRANSFER', 'tim/gutachten.pdf');

        app(VehicleReportService::class)->transfer([
            'auftragsnummer' => 'AUF-TRANSFER',
            'vehicle_id' => $vehicle->vehicle_id,
            'document_type' => 'gutachten',
            'document_title' => 'Gutachten',
            'published' => true,
            'source_assessment_document_id' => $sourceId,
        ], $this->admin());

        $this->assertDatabaseHas('vehicle_report_documents', [
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => 'AUF-TRANSFER',
            'published' => true,
        ]);

        Notification::assertSentTo($owner, SystemNotification::class);
    }

    public function test_transferring_an_unpublished_assessment_document_notifies_nobody(): void
    {
        Notification::fake();
        Storage::fake('s3');
        Storage::fake('documents');
        Storage::disk('s3')->put('tim/gutachten.pdf', 'content');

        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $owner->id]);
        $sourceId = $this->assessmentDocument('AUF-DRAFT', 'tim/gutachten.pdf');

        app(VehicleReportService::class)->transfer([
            'auftragsnummer' => 'AUF-DRAFT',
            'vehicle_id' => $vehicle->vehicle_id,
            'published' => false,
            'source_assessment_document_id' => $sourceId,
        ], $this->admin());

        $this->assertDatabaseHas('vehicle_report_documents', ['auftragsnummer' => 'AUF-DRAFT', 'published' => false]);
        Notification::assertNothingSent();
    }

    /**
     * "Dokumente abrufen" on an appraisal TIM has already ingested: no SOAP
     * call is needed, and the documents on record get copied into the
     * vehicle's report repository as unpublished drafts.
     */
    public function test_admin_can_pull_already_synced_appraisal_documents(): void
    {
        Storage::fake('s3');
        Storage::fake('documents');
        Storage::disk('s3')->put('tim/gutachten.pdf', 'content');

        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => 'AUF-PULL',
            'leasyback_partner' => 'tuvsud',
            'response_body' => 4242,
        ]);
        $this->assessmentDocument($order->auftragsnummer, 'tim/gutachten.pdf');

        $this->actingAs($admin)
            ->post(route('admin.vehicles.reports.pull', $vehicle->vehicle_id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('vehicle_report_documents', [
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => 'AUF-PULL',
            'document_type' => 'gutachten',
            'published' => false,
        ]);
    }

    /** A repeat pull must not duplicate documents or fail — each is skipped. */
    public function test_pulling_twice_does_not_duplicate_documents(): void
    {
        Storage::fake('s3');
        Storage::fake('documents');
        Storage::disk('s3')->put('tim/gutachten.pdf', 'content');

        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => 'AUF-TWICE',
            'leasyback_partner' => 'tuvsud',
            'response_body' => 5151,
        ]);
        $this->assessmentDocument($order->auftragsnummer, 'tim/gutachten.pdf');

        $this->actingAs($admin)->post(route('admin.vehicles.reports.pull', $vehicle->vehicle_id));
        $this->actingAs($admin)
            ->post(route('admin.vehicles.reports.pull', $vehicle->vehicle_id))
            ->assertRedirect();

        $this->assertDatabaseCount('vehicle_report_documents', 1);
    }

    public function test_pull_is_rejected_when_the_order_has_no_gutachtennummer(): void
    {
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'leasyback_partner' => 'tuvsud',
            'response_body' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.vehicles.reports.pull', $vehicle->vehicle_id))
            ->assertSessionHasErrors('report');

        $this->assertDatabaseCount('vehicle_report_documents', 0);
    }

    public function test_pull_is_rejected_for_a_vehicle_without_a_tuvsud_order(): void
    {
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'leasyback_partner' => 'dekra',
            'response_body' => 999,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.vehicles.reports.pull', $vehicle->vehicle_id))
            ->assertSessionHasErrors('report');
    }

    /**
     * The fresh-sync path: TimService reports a successful ingest, and the
     * documents it wrote are transferred. TimService itself is stubbed —
     * exercising it for real would mean standing up TIM's SOAP endpoint.
     */
    public function test_a_successful_sync_transfers_the_documents_it_ingested(): void
    {
        Storage::fake('s3');
        Storage::fake('documents');
        Storage::disk('s3')->put('tim/neu.pdf', 'content');

        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => 'AUF-FRESH',
            'leasyback_partner' => 'tuvsud',
            'response_body' => 6262,
        ]);

        $this->app->bind(TimService::class, fn () => new class($order->auftragsnummer) extends TimService
        {
            public function __construct(private readonly string $auftragsnummer) {}

            public function sync(int $bewertungId, int|string $userId): array
            {
                // Stand in for the SOAP ingest: write what TIM would have written.
                $assessmentId = DB::table('vehicle_assessments')->insertGetId([
                    'uid' => 'uid-'.$bewertungId,
                    'auftragsnummer' => $this->auftragsnummer,
                ]);
                DB::table('assessment_documents')->insert([
                    'assessment_id' => $assessmentId,
                    'doc_type' => 'gutachten',
                    's3_bucket' => 'test',
                    's3_key' => 'tim/neu.pdf',
                    's3_url' => 'https://example.test/tim/neu.pdf',
                ]);

                return ['status' => 200, 'body' => ['bewertung_id' => $bewertungId]];
            }
        });

        $this->actingAs($admin)
            ->post(route('admin.vehicles.reports.pull', $vehicle->vehicle_id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('vehicle_report_documents', [
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => 'AUF-FRESH',
            'published' => false,
        ]);
    }

    /** A TIM outage surfaces as a form error, not a 500. */
    public function test_a_failing_sync_reports_an_error_instead_of_crashing(): void
    {
        $admin = $this->admin();
        $vehicle = Vehicle::factory()->create();
        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'leasyback_partner' => 'tuvsud',
            'response_body' => 7373,
        ]);

        $this->app->bind(TimService::class, fn () => new class extends TimService
        {
            public function sync(int $bewertungId, int|string $userId): array
            {
                throw new RuntimeException('TIM token is unavailable');
            }
        });

        $this->actingAs($admin)
            ->post(route('admin.vehicles.reports.pull', $vehicle->vehicle_id))
            ->assertSessionHasErrors('report');

        $this->assertDatabaseCount('vehicle_report_documents', 0);
    }

    public function test_non_admin_cannot_pull_appraisal_documents(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.vehicles.reports.pull', $vehicle->vehicle_id))
            ->assertForbidden();
    }

    private function assessmentDocument(string $auftragsnummer, string $s3Key): int
    {
        $assessmentId = DB::table('vehicle_assessments')->insertGetId([
            'uid' => 'uid-'.$auftragsnummer,
            'auftragsnummer' => $auftragsnummer,
        ]);

        return DB::table('assessment_documents')->insertGetId([
            'assessment_id' => $assessmentId,
            'doc_type' => 'gutachten',
            's3_bucket' => 'test',
            's3_key' => $s3Key,
            's3_url' => 'https://example.test/'.$s3Key,
        ]);
    }
}
