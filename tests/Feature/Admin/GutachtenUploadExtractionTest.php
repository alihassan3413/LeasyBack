<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use App\Modules\UserProfile\Order\Jobs\ExtractAppraisalPositions;
use App\Modules\UserProfile\Order\Jobs\StartAppraisalExtraction;
use App\Modules\UserProfile\Order\Models\AppraisalExtraction;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\AppraisalExtractionService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GutachtenUploadExtractionTest extends TestCase
{
    use RefreshDatabase;

    private const MAX_KILOBYTES = 51200;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    public function test_a_fifty_megabyte_gutachten_is_accepted(): void
    {
        Queue::fake();
        [$order, $vehicle] = $this->order();

        $this->actingAs($this->admin())
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => $order->auftragsnummer,
                'document_type' => 'gutachten',
                'file' => UploadedFile::fake()->create('erstgutachten.pdf', self::MAX_KILOBYTES, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('vehicle_report_documents', [
            'auftragsnummer' => $order->auftragsnummer,
            'document_type' => 'gutachten',
        ]);
    }

    public function test_a_gutachten_beyond_the_limit_is_refused(): void
    {
        Queue::fake();
        [$order, $vehicle] = $this->order();

        $this->actingAs($this->admin())
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => $order->auftragsnummer,
                'document_type' => 'gutachten',
                'file' => UploadedFile::fake()->create('erstgutachten.pdf', self::MAX_KILOBYTES + 1, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, VehicleReportDocument::count());
        Queue::assertNothingPushed();
    }

    public function test_customer_documents_keep_their_own_smaller_limit(): void
    {
        [, $vehicle] = $this->order();
        $owner = User::find($vehicle->b2c_user_id);

        $this->actingAs($owner)
            ->post(route('vehicles.documents.store', $vehicle->vehicle_id), [
                'document_type' => 'Leasingvertrag',
                'file' => UploadedFile::fake()->create('vertrag.pdf', 10241, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_uploading_a_gutachten_starts_an_extraction(): void
    {
        Bus::fake();
        [$order, $vehicle] = $this->order();

        $this->actingAs($this->admin())
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => $order->auftragsnummer,
                'document_type' => 'gutachten',
                'file' => UploadedFile::fake()->create('erstgutachten.pdf', 120, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        $document = VehicleReportDocument::sole();

        Bus::assertDispatched(
            StartAppraisalExtraction::class,
            fn (StartAppraisalExtraction $job) => $job->documentId === $document->id,
        );
    }

    public function test_uploading_a_nachgutachten_starts_an_extraction(): void
    {
        Bus::fake();
        [$order, $vehicle] = $this->order();

        $this->actingAs($this->admin())
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => $order->auftragsnummer,
                'document_type' => 'nachgutachten',
                'file' => UploadedFile::fake()->create('nachgutachten.pdf', 120, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        Bus::assertDispatched(StartAppraisalExtraction::class);
    }

    public function test_other_documents_never_start_an_extraction(): void
    {
        Bus::fake();
        [$order, $vehicle] = $this->order();

        foreach ([['rechnung', 'rechnung.pdf'], ['sonstiges', 'notiz.pdf'], ['gutachten', 'gutachten-seite.jpg']] as [$type, $name]) {
            $this->actingAs($this->admin())
                ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                    'auftragsnummer' => $order->auftragsnummer,
                    'document_type' => $type,
                    'file' => str_ends_with($name, '.pdf')
                        ? UploadedFile::fake()->create($name, 60, 'application/pdf')
                        : UploadedFile::fake()->image($name),
                ])
                ->assertSessionHas('success');
        }

        $this->assertSame(3, VehicleReportDocument::count());
        Bus::assertNotDispatched(StartAppraisalExtraction::class);
    }

    public function test_the_queued_start_creates_exactly_one_run(): void
    {
        Queue::fake();
        [$order, $vehicle] = $this->order();
        $document = $this->uploadGutachten($order, $vehicle, 'erstgutachten.pdf');

        (new StartAppraisalExtraction($document->id, $this->admin()->id))->handle(app(AppraisalExtractionService::class));
        (new StartAppraisalExtraction($document->id, $this->admin()->id))->handle(app(AppraisalExtractionService::class));

        $this->assertSame(1, AppraisalExtraction::count());
        $this->assertSame(AppraisalExtractionStatus::Pending, AppraisalExtraction::sole()->status);
        Queue::assertPushed(ExtractAppraisalPositions::class, 1);
        $this->assertSame(0, AppraisalPosition::count());
    }

    public function test_a_second_gutachten_starts_its_own_run(): void
    {
        Queue::fake();
        [$order, $vehicle] = $this->order();
        $first = $this->uploadGutachten($order, $vehicle, 'erstgutachten.pdf');
        $second = $this->uploadGutachten($order, $vehicle, 'nachgutachten.pdf', 'nachgutachten');

        $this->runStart($first);
        $this->runStart($second);

        $this->assertSame(
            [$first->id, $second->id],
            AppraisalExtraction::orderBy('created_at')->pluck('source_document_id')->all(),
        );
    }

    public function test_a_refused_extraction_leaves_the_uploaded_document_in_place(): void
    {
        Queue::fake();
        [$order, $vehicle] = $this->order('workshop');
        $document = $this->uploadGutachten($order, $vehicle, 'erstgutachten.pdf');

        $this->runStart($document);

        $this->assertSame(0, AppraisalExtraction::count());
        $this->assertDatabaseHas('vehicle_report_documents', ['id' => $document->id]);
        Queue::assertNotPushed(ExtractAppraisalPositions::class);
    }

    public function test_a_deleted_document_does_not_break_the_queued_start(): void
    {
        Queue::fake();
        [$order, $vehicle] = $this->order();
        $document = $this->uploadGutachten($order, $vehicle, 'erstgutachten.pdf');
        $documentId = $document->id;
        $document->delete();

        (new StartAppraisalExtraction($documentId, null))->handle(app(AppraisalExtractionService::class));

        $this->assertSame(0, AppraisalExtraction::count());
    }

    public function test_the_manual_trigger_still_works_for_an_existing_document(): void
    {
        Queue::fake();
        [$order, $vehicle] = $this->order();
        $document = $this->uploadGutachten($order, $vehicle, 'erstgutachten.pdf');

        $this->actingAs($this->admin())
            ->post(route('admin.orders.appraisal-extractions.store', $order->id), ['document_id' => $document->id])
            ->assertSessionHas('success');

        $this->assertSame(1, AppraisalExtraction::count());
    }

    private function runStart(VehicleReportDocument $document): void
    {
        (new StartAppraisalExtraction($document->id, $this->admin()->id))
            ->handle(app(AppraisalExtractionService::class));
    }

    private function uploadGutachten(LeasybackOrder $order, Vehicle $vehicle, string $name, string $type = 'gutachten'): VehicleReportDocument
    {
        $path = "vehicle-reports/{$order->auftragsnummer}/{$name}";
        Storage::disk('documents')->put($path, '%PDF-1.7 test');

        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $vehicle->vehicle_id,
            'document_type' => $type,
            'document_title' => $name,
            'path' => $path,
        ]);
    }

    private function admin(): User
    {
        return User::firstWhere('user_type', UserType::Admin) ?? User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function order(string $status = 'inspected'): array
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);

        return [$order, $vehicle];
    }
}
