<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use App\Modules\UserProfile\Order\Jobs\ExtractAppraisalPositions;
use App\Modules\UserProfile\Order\Models\AppraisalExtraction;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppraisalExtractionAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Queue::fake();
    }

    public function test_an_admin_can_start_an_extraction_for_a_gutachten_pdf(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post($this->route($order), ['document_id' => $document->id]);

        $response->assertRedirect()->assertSessionHas('success');

        $extraction = AppraisalExtraction::sole();
        $this->assertSame(AppraisalExtractionStatus::Pending, $extraction->status);
        $this->assertSame($order->id, $extraction->order_id);
        $this->assertSame($document->id, $extraction->source_document_id);
        $this->assertSame($admin->id, $extraction->requested_by_user_id);

        Queue::assertPushed(ExtractAppraisalPositions::class);
        $this->assertSame(0, AppraisalPosition::count());
    }

    public function test_a_nachgutachten_pdf_can_also_be_extracted(): void
    {
        [$order] = $this->orderWithGutachten();
        $document = $this->document($order, 'nachgutachten.pdf', 'nachgutachten');

        $this->actingAs($this->admin())
            ->post($this->route($order), ['document_id' => $document->id])
            ->assertSessionHas('success');

        $this->assertSame($document->id, AppraisalExtraction::sole()->source_document_id);
    }

    public function test_starting_twice_reuses_the_active_run(): void
    {
        [$order, $document] = $this->orderWithGutachten();

        $this->actingAs($this->admin())->post($this->route($order), ['document_id' => $document->id]);
        $this->actingAs($this->admin())->post($this->route($order), ['document_id' => $document->id]);

        $this->assertSame(1, AppraisalExtraction::count());
        Queue::assertPushed(ExtractAppraisalPositions::class, 1);
    }

    public function test_a_customer_cannot_start_an_extraction(): void
    {
        [$order, $document] = $this->orderWithGutachten();

        $this->actingAs(User::factory()->create(['user_type' => UserType::Privatkunde]))
            ->post($this->route($order), ['document_id' => $document->id])
            ->assertForbidden();

        $this->assertSame(0, AppraisalExtraction::count());
        Queue::assertNothingPushed();
    }

    public function test_a_guest_cannot_start_an_extraction(): void
    {
        [$order, $document] = $this->orderWithGutachten();

        $this->post($this->route($order), ['document_id' => $document->id])->assertRedirect(route('login'));

        $this->assertSame(0, AppraisalExtraction::count());
    }

    public function test_a_document_of_another_order_is_refused(): void
    {
        [$order] = $this->orderWithGutachten();
        [, $foreign] = $this->orderWithGutachten();

        $this->actingAs($this->admin())
            ->post($this->route($order), ['document_id' => $foreign->id])
            ->assertSessionHasErrors('document_id');

        $this->assertSame(0, AppraisalExtraction::count());
    }

    public function test_a_document_that_is_not_a_gutachten_is_refused(): void
    {
        [$order] = $this->orderWithGutachten();
        $invoice = $this->document($order, 'rechnung.pdf', 'rechnung');

        $this->actingAs($this->admin())
            ->post($this->route($order), ['document_id' => $invoice->id])
            ->assertSessionHasErrors('document_id');

        $this->assertSame(0, AppraisalExtraction::count());
    }

    public function test_a_gutachten_that_is_not_a_pdf_is_refused(): void
    {
        [$order] = $this->orderWithGutachten();
        $photo = $this->document($order, 'gutachten-seite.jpg', 'gutachten');

        $this->actingAs($this->admin())
            ->post($this->route($order), ['document_id' => $photo->id])
            ->assertSessionHasErrors('document_id');

        $this->assertSame(0, AppraisalExtraction::count());
    }

    public function test_an_order_outside_the_appraisal_phase_is_refused(): void
    {
        [$order, $document] = $this->orderWithGutachten('workshop');

        $this->actingAs($this->admin())
            ->post($this->route($order), ['document_id' => $document->id])
            ->assertSessionHasErrors('document_id');

        $this->assertSame(0, AppraisalExtraction::count());
    }

    public function test_an_order_with_an_accepted_offer_is_refused(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'selected',
        ]);

        $this->actingAs($this->admin())
            ->post($this->route($order), ['document_id' => $document->id])
            ->assertSessionHasErrors('document_id');
    }

    public function test_the_order_page_reports_extraction_status_to_the_admin(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $extraction = AppraisalExtraction::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'source_document_id' => $document->id,
            'status' => AppraisalExtractionStatus::Processing,
            'started_at' => now(),
        ]);

        $payload = $this->orderPayload($order);

        $this->assertCount(1, $payload['appraisal_extractions']);
        $row = $payload['appraisal_extractions'][0];
        $this->assertSame($extraction->id, $row['id']);
        $this->assertSame('processing', $row['status']);
        $this->assertSame(0, $row['line_count']);
        $this->assertNull($row['total_net']);
        $this->assertNotNull($row['started_at']);
    }

    public function test_a_ready_run_reports_that_a_proposal_exists(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        AppraisalExtraction::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'source_document_id' => $document->id,
            'status' => AppraisalExtractionStatus::Ready,
            'source' => 'parser',
            'extractor_version' => 'pdf-gutachten-1/pdftotext-layout-1/502fce3a',
            'completed_at' => now(),
            'warnings' => [['code' => 'missing_positions', 'message' => 'Es fehlen Positionen.', 'line' => null]],
            'proposal' => [
                'appraisal_number' => '42772146',
                'appraisal_date' => '2025-12-02',
                'total_net' => '200.00',
                'lines' => [
                    ['component' => 'Stossfänger hinten', 'original_amount_net' => '120.00'],
                    ['component' => 'Heckdeckel', 'original_amount_net' => '80.00'],
                ],
            ],
        ]);

        $row = $this->orderPayload($order)['appraisal_extractions'][0];

        $this->assertSame('ready', $row['status']);
        $this->assertSame('parser', $row['source']);
        $this->assertSame(2, $row['line_count']);
        $this->assertSame('200.00', $row['total_net']);
        $this->assertSame('42772146', $row['appraisal_number']);
        $this->assertSame([['code' => 'missing_positions', 'message' => 'Es fehlen Positionen.']], $row['warnings']);
        $this->assertSame(0, AppraisalPosition::count());
    }

    public function test_a_failed_run_reports_its_error_to_the_admin(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        AppraisalExtraction::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'source_document_id' => $document->id,
            'status' => AppraisalExtractionStatus::Failed,
            'error_code' => 'unsupported_document',
            'error_message' => 'The PDF carries no usable text layer.',
            'failed_at' => now(),
            'attempts' => 1,
        ]);

        $row = $this->orderPayload($order)['appraisal_extractions'][0];

        $this->assertSame('failed', $row['status']);
        $this->assertSame('unsupported_document', $row['error_code']);
        $this->assertSame(1, $row['attempts']);
    }

    public function test_runs_are_listed_newest_first(): void
    {
        [$order, $document] = $this->orderWithGutachten();

        $older = AppraisalExtraction::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'source_document_id' => $document->id,
            'status' => AppraisalExtractionStatus::Failed,
            'created_at' => now()->subHour(),
        ]);
        $newer = AppraisalExtraction::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'source_document_id' => $document->id,
            'status' => AppraisalExtractionStatus::Ready,
        ]);

        $this->assertSame(
            [$newer->id, $older->id],
            array_column($this->orderPayload($order)['appraisal_extractions'], 'id'),
        );
    }

    public function test_report_documents_say_which_files_can_be_extracted(): void
    {
        [$order] = $this->orderWithGutachten();
        $this->document($order, 'schadenbild.jpg', 'gutachten');

        $documents = collect($this->orderPayload($order)['report_documents'])->keyBy('document_title');

        $this->assertTrue($documents['erstgutachten.pdf']['is_pdf']);
        $this->assertFalse($documents['schadenbild.jpg']['is_pdf']);
        $this->assertTrue($documents['schadenbild.jpg']['is_image']);
    }

    private function route(LeasybackOrder $order): string
    {
        return route('admin.orders.appraisal-extractions.store', $order->id);
    }

    private function orderPayload(LeasybackOrder $order): array
    {
        $response = $this->actingAs($this->admin())->get(route('admin.orders.show', $order->id));
        $response->assertOk();

        return $response->viewData('page')['props']['order'];
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function orderWithGutachten(string $status = 'inspected'): array
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

        return [$order, $this->document($order, 'erstgutachten.pdf', 'gutachten')];
    }

    private function document(LeasybackOrder $order, string $name, string $type): VehicleReportDocument
    {
        $path = "vehicle-reports/{$order->auftragsnummer}/".Str::uuid().'-'.$name;

        Storage::disk('documents')->put($path, '%PDF-1.7 test');

        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => $type,
            'document_title' => $name,
            'path' => $path,
        ]);
    }
}
