<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use App\Modules\UserProfile\Order\Jobs\ExtractAppraisalPositions;
use App\Modules\UserProfile\Order\Jobs\ExtractGutachtenImages;
use App\Modules\UserProfile\Order\Jobs\StartAppraisalExtraction;
use App\Modules\UserProfile\Order\Models\AppraisalExtraction;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\AppraisalExtractionService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use App\Support\UploadFailure;
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

    /**
     * The QA sequence: a JPG uploaded as the Gutachten (stored, never
     * extracted), then a wrong PDF whose extraction fails, then the correct
     * PDF — under the same file name as the wrong one. The correct one is a
     * new document with its own, fresh extraction run; nothing earlier blocks
     * it or is overwritten. In both channels.
     */
    public function test_a_corrected_gutachten_after_a_jpg_and_a_bad_pdf_uploads_and_is_extracted(): void
    {
        Bus::fake([ExtractAppraisalPositions::class, ExtractGutachtenImages::class]);

        foreach (['B2B' => 'vehicle_collected', 'B2C' => 'confirmed'] as $channel => $status) {
            [$order, $vehicle] = $this->order($status, $channel);
            $upload = fn (UploadedFile $file) => $this->actingAs($this->admin())
                ->from('/admin')
                ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                    'auftragsnummer' => $order->auftragsnummer,
                    'document_type' => 'gutachten',
                    'file' => $file,
                ])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('success');

            $upload(UploadedFile::fake()->image('Gutachten.jpg'));
            $this->assertSame(0, AppraisalExtraction::where('order_id', $order->id)->count(), "{$channel}: a JPG is stored, not extracted");

            $upload(UploadedFile::fake()->createWithContent('Gutachten.pdf', '%PDF-1.4 kein Gutachten'));
            $bad = AppraisalExtraction::where('order_id', $order->id)->sole();
            $bad->update(['status' => AppraisalExtractionStatus::Failed]);

            $upload(UploadedFile::fake()->createWithContent('Gutachten.pdf', '%PDF-1.4 richtiges Gutachten'));

            $documents = VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)->orderBy('created_at')->get();
            $this->assertCount(3, $documents, $channel);

            $correct = $documents->first(fn (VehicleReportDocument $document) => str_ends_with($document->path, 'Gutachten (2).pdf'));
            $this->assertNotNull($correct, "{$channel}: same name, stored next to the wrong one");
            $this->assertSame('%PDF-1.4 kein Gutachten', Storage::disk('documents')->get("vehicle-reports/{$order->auftragsnummer}/Gutachten.pdf"), 'the wrong one is not overwritten');

            $fresh = AppraisalExtraction::where('order_id', $order->id)->where('source_document_id', $correct->id)->sole();
            $this->assertSame(AppraisalExtractionStatus::Pending, $fresh->status, "{$channel}: a fresh run for the correct PDF");
            $this->assertSame(AppraisalExtractionStatus::Failed, $bad->fresh()->status);
            Bus::assertDispatched(ExtractAppraisalPositions::class, fn (ExtractAppraisalPositions $job) => $job->extractionId === $fresh->id);
        }
    }

    /** PHP refused the file before the app saw it: say why, with this server's limit — not "failed to upload". */
    public function test_a_file_the_server_refuses_names_the_real_cause(): void
    {
        [$order, $vehicle] = $this->order('vehicle_collected', 'B2B');
        $path = tempnam(sys_get_temp_dir(), 'gutachten');
        file_put_contents($path, '%PDF-1.4');

        $response = $this->actingAs($this->admin())
            ->from('/admin')
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => $order->auftragsnummer,
                'document_type' => 'gutachten',
                'file' => new UploadedFile($path, 'Gutachten.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true),
            ]);

        $response->assertSessionHasErrors('file');
        $message = session('errors')->first('file');
        $this->assertStringContainsString('größer, als der Server annimmt', $message);
        $this->assertStringContainsString(UploadFailure::serverLimit(), $message);
        $this->assertStringNotContainsString('failed to upload', $message);
        $this->assertSame(0, VehicleReportDocument::count());
    }

    private function admin(): User
    {
        return User::firstWhere('user_type', UserType::Admin) ?? User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function order(string $status = 'inspected', string $channel = 'B2C'): array
    {
        $vehicle = Vehicle::factory()->create($channel === 'B2B'
            ? ['vehicle_belongs' => 'B2B', 'b2c_user_id' => null]
            : [
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
