<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Contracts\AppraisalAiExtractor;
use App\Modules\UserProfile\Order\Contracts\AppraisalDocumentParser;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionProposal;
use App\Modules\UserProfile\Order\Data\AppraisalProposalLine;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionSource;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use App\Modules\UserProfile\Order\Jobs\ExtractAppraisalPositions;
use App\Modules\UserProfile\Order\Models\AppraisalExtraction;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\AppraisalExtractionService;
use App\Modules\UserProfile\Order\Services\DisabledAppraisalAiExtractor;
use App\Modules\UserProfile\Order\Services\Extraction\PdfGutachtenParser;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakeAppraisalAiExtractor;
use Tests\Support\FakeAppraisalDocumentParser;
use Tests\TestCase;

class AppraisalExtractionTest extends TestCase
{
    use RefreshDatabase;

    private const VIN = 'WVWZZZ1KZAW000001';

    private FakeAppraisalDocumentParser $parser;

    private FakeAppraisalAiExtractor $ai;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->parser = new FakeAppraisalDocumentParser;
        $this->ai = new FakeAppraisalAiExtractor;
        $this->ai->enabled = false;

        $this->app->instance(AppraisalDocumentParser::class, $this->parser);
        $this->app->instance(AppraisalAiExtractor::class, $this->ai);
    }

    public function test_starting_an_extraction_stores_a_pending_run_and_queues_the_job(): void
    {
        Queue::fake();
        $admin = $this->admin();
        [$order, $document] = $this->orderWithGutachten();

        $extraction = $this->service()->start($order, $document, $admin);

        $this->assertSame(AppraisalExtractionStatus::Pending, $extraction->status);
        $this->assertSame($order->id, $extraction->order_id);
        $this->assertSame($order->auftragsnummer, $extraction->auftragsnummer);
        $this->assertSame($document->id, $extraction->source_document_id);
        $this->assertSame($admin->id, $extraction->requested_by_user_id);
        $this->assertNull($extraction->proposal);
        $this->assertSame(0, $extraction->attempts);

        Queue::assertPushed(ExtractAppraisalPositions::class, fn (ExtractAppraisalPositions $job) => $job->extractionId === $extraction->id);

        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'order_id' => $order->id,
            'action' => 'APPRAISAL_EXTRACTION_REQUESTED',
            'changed_by_user_id' => $admin->id,
        ]);
    }

    public function test_starting_twice_for_the_same_document_reuses_the_active_run(): void
    {
        Queue::fake();
        [$order, $document] = $this->orderWithGutachten();

        $first = $this->service()->start($order, $document, $this->admin());
        $second = $this->service()->start($order, $document, $this->admin());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AppraisalExtraction::count());
        Queue::assertPushed(ExtractAppraisalPositions::class, 1);
    }

    public function test_a_finished_run_does_not_block_a_new_one(): void
    {
        Queue::fake();
        [$order, $document] = $this->orderWithGutachten();

        $first = $this->service()->start($order, $document);
        $first->transitionTo(AppraisalExtractionStatus::Discarded);

        $second = $this->service()->start($order, $document);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, AppraisalExtraction::count());
    }

    public function test_a_document_of_another_order_is_refused(): void
    {
        Queue::fake();
        [$order] = $this->orderWithGutachten();
        [, $foreign] = $this->orderWithGutachten();

        $this->assertRefused(fn () => $this->service()->start($order, $foreign), 422);
        $this->assertSame(0, AppraisalExtraction::count());
        Queue::assertNothingPushed();
    }

    public function test_only_pdf_documents_can_be_extracted(): void
    {
        Queue::fake();
        [$order] = $this->orderWithGutachten();
        $image = $this->document($order, 'photo.jpg', 'image-bytes');

        $this->assertRefused(fn () => $this->service()->start($order, $image), 422);
        $this->assertSame(0, AppraisalExtraction::count());
    }

    public function test_an_order_outside_the_appraisal_phase_is_refused(): void
    {
        Queue::fake();
        [$order, $document] = $this->orderWithGutachten('workshop');

        $this->assertRefused(fn () => $this->service()->start($order, $document), 422);
        $this->assertSame(0, AppraisalExtraction::count());
    }

    public function test_an_order_with_an_accepted_offer_is_refused(): void
    {
        Queue::fake();
        [$order, $document] = $this->orderWithGutachten();
        LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'selected',
        ]);

        $this->assertRefused(fn () => $this->service()->start($order, $document), 422);
    }

    public function test_the_status_lifecycle_allows_only_forward_transitions(): void
    {
        $extraction = AppraisalExtraction::factory()->create();

        $this->assertSame(AppraisalExtractionStatus::Pending, $extraction->status);

        $extraction->transitionTo(AppraisalExtractionStatus::Processing);
        $extraction->transitionTo(AppraisalExtractionStatus::Ready);
        $extraction->transitionTo(AppraisalExtractionStatus::Applied);

        $this->assertSame(AppraisalExtractionStatus::Applied, $extraction->fresh()->status);
        $this->assertTrue(AppraisalExtractionStatus::Applied->isFinal());
        $this->assertTrue(AppraisalExtractionStatus::Discarded->isFinal());
        $this->assertFalse(AppraisalExtractionStatus::Failed->isFinal());

        $this->expectExceptionObject(AppraisalExtractionException::invalidTransition(AppraisalExtractionStatus::Applied, AppraisalExtractionStatus::Processing));

        $extraction->transitionTo(AppraisalExtractionStatus::Processing);
    }

    public function test_an_invalid_transition_leaves_the_record_untouched(): void
    {
        $extraction = AppraisalExtraction::factory()->status(AppraisalExtractionStatus::Ready)->create();

        try {
            $extraction->transitionTo(AppraisalExtractionStatus::Processing, ['error_code' => 'should_not_persist']);
            $this->fail('An invalid transition was accepted.');
        } catch (AppraisalExtractionException $exception) {
            $this->assertSame(AppraisalExtractionException::INVALID_TRANSITION, $exception->errorCode);
        }

        $fresh = $extraction->fresh();
        $this->assertSame(AppraisalExtractionStatus::Ready, $fresh->status);
        $this->assertNull($fresh->error_code);
    }

    public function test_the_parser_produces_a_ready_proposal(): void
    {
        [$order, $document, $contents] = $this->orderWithGutachten();
        $this->parser->proposal = $this->proposal();
        $extraction = $this->pendingExtraction($order, $document);

        $result = $this->service()->run($extraction->id);

        $this->assertSame(AppraisalExtractionStatus::Ready, $result->status);
        $this->assertSame(AppraisalExtractionSource::Parser, $result->source);
        $this->assertSame('fake-parser-1', $result->extractor_version);
        $this->assertSame(hash('sha256', $contents), $result->input_sha256);
        $this->assertSame(1, $result->attempts);
        $this->assertNotNull($result->started_at);
        $this->assertNotNull($result->completed_at);
        $this->assertSame($this->proposal()->toArray(), $result->proposal);
        $this->assertSame([], $result->warnings);

        $this->assertCount(1, $this->parser->calls);
        $this->assertSame($contents, $this->parser->calls[0]->contents);
        $this->assertSame(self::VIN, $this->parser->calls[0]->vin);
        $this->assertSame([], $this->ai->calls);

        $this->assertSame(0, AppraisalPosition::count());
        $this->assertDatabaseHas('leasyback_order_audit_log', ['order_id' => $order->id, 'action' => 'APPRAISAL_EXTRACTION_READY']);
    }

    public function test_plausibility_problems_are_stored_as_warnings(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->proposal = new AppraisalExtractionProposal(
            lines: [
                new AppraisalProposalLine('Stoßfänger vorne', '500.00', chargeableAmountNet: '600.00', confidence: 0.4),
                new AppraisalProposalLine('stoßfänger vorne', '100.00'),
            ],
            vin: 'WVWZZZ1KZAW999999',
            currency: 'CHF',
            totalNet: '900.00',
        );

        $result = $this->service()->run($this->pendingExtraction($order, $document)->id);

        $this->assertSame(AppraisalExtractionStatus::Ready, $result->status);
        $this->assertEqualsCanonicalizing(
            ['chargeable_exceeds_original', 'low_confidence', 'duplicate_component', 'total_mismatch', 'vin_mismatch', 'unexpected_currency'],
            array_column($result->warnings, 'code'),
        );
    }

    public function test_a_failing_parser_falls_back_to_the_ai_extractor(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->failure = AppraisalExtractionException::extractorFailed('Layout not recognised.');
        $this->ai->enabled = true;
        $this->ai->proposal = $this->proposal();

        $result = $this->service()->run($this->pendingExtraction($order, $document)->id);

        $this->assertSame(AppraisalExtractionStatus::Ready, $result->status);
        $this->assertSame(AppraisalExtractionSource::Ai, $result->source);
        $this->assertSame('fake-ai-1', $result->extractor_version);
        $this->assertCount(1, $this->parser->calls);
        $this->assertCount(1, $this->ai->calls);
        $this->assertSame(['parser_failed'], array_column($result->warnings, 'code'));
    }

    public function test_an_invalid_parser_proposal_falls_back_to_the_ai_extractor(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->proposal = new AppraisalExtractionProposal([new AppraisalProposalLine('Tür links', '1.234,56')]);
        $this->ai->enabled = true;
        $this->ai->proposal = $this->proposal();

        $result = $this->service()->run($this->pendingExtraction($order, $document)->id);

        $this->assertSame(AppraisalExtractionSource::Ai, $result->source);
        $this->assertSame(['parser_failed'], array_column($result->warnings, 'code'));
    }

    public function test_an_unsupported_document_goes_straight_to_the_ai_extractor(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->supported = false;
        $this->ai->enabled = true;
        $this->ai->proposal = $this->proposal();

        $result = $this->service()->run($this->pendingExtraction($order, $document)->id);

        $this->assertSame(AppraisalExtractionSource::Ai, $result->source);
        $this->assertSame([], $this->parser->calls);
        $this->assertSame([], $result->warnings);
    }

    public function test_the_disabled_ai_fallback_is_never_called(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->failure = AppraisalExtractionException::extractorFailed('Layout not recognised.');

        $result = $this->service()->run($this->pendingExtraction($order, $document)->id);

        $this->assertSame(AppraisalExtractionStatus::Failed, $result->status);
        $this->assertSame(AppraisalExtractionException::EXTRACTOR_FAILED, $result->error_code);
        $this->assertSame([], $this->ai->calls);
    }

    public function test_no_available_extractor_fails_the_run(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->supported = false;

        $result = $this->service()->run($this->pendingExtraction($order, $document)->id);

        $this->assertSame(AppraisalExtractionStatus::Failed, $result->status);
        $this->assertSame(AppraisalExtractionException::NO_EXTRACTOR_AVAILABLE, $result->error_code);
        $this->assertNotNull($result->failed_at);
        $this->assertNull($result->proposal);
        $this->assertDatabaseHas('leasyback_order_audit_log', ['order_id' => $order->id, 'action' => 'APPRAISAL_EXTRACTION_FAILED']);
    }

    public function test_when_every_extractor_fails_the_last_error_is_kept_with_both_notes(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->failure = AppraisalExtractionException::extractorFailed('Layout not recognised.');
        $this->ai->enabled = true;
        $this->ai->proposal = new AppraisalExtractionProposal([]);

        $result = $this->service()->run($this->pendingExtraction($order, $document)->id);

        $this->assertSame(AppraisalExtractionStatus::Failed, $result->status);
        $this->assertSame(AppraisalExtractionException::INVALID_PROPOSAL, $result->error_code);
        $this->assertSame(['parser_failed', 'ai_failed'], array_column($result->warnings, 'code'));
    }

    public function test_a_deleted_source_document_fails_the_run(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $extraction = $this->pendingExtraction($order, $document);
        $document->delete();

        $result = $this->service()->run($extraction->id);

        $this->assertSame(AppraisalExtractionStatus::Failed, $result->status);
        $this->assertSame(AppraisalExtractionException::DOCUMENT_MISSING, $result->error_code);
        $this->assertSame([], $this->parser->calls);
    }

    public function test_a_missing_file_fails_the_run(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        Storage::disk('documents')->delete($document->path);

        $result = $this->service()->run($this->pendingExtraction($order, $document)->id);

        $this->assertSame(AppraisalExtractionStatus::Failed, $result->status);
        $this->assertSame(AppraisalExtractionException::DOCUMENT_UNREADABLE, $result->error_code);
    }

    public function test_an_unexpected_error_releases_the_run_for_a_retry(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->failure = new RuntimeException('Parser crashed.');
        $extraction = $this->pendingExtraction($order, $document);

        try {
            $this->service()->run($extraction->id);
            $this->fail('The unexpected error was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Parser crashed.', $exception->getMessage());
        }

        $this->assertSame(AppraisalExtractionStatus::Pending, $extraction->fresh()->status);

        $this->parser->failure = null;
        $this->parser->proposal = $this->proposal();

        $result = $this->service()->run($extraction->id);

        $this->assertSame(AppraisalExtractionStatus::Ready, $result->status);
        $this->assertSame(2, $result->attempts);
    }

    public function test_a_failed_run_can_be_retried(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->supported = false;
        $extraction = $this->pendingExtraction($order, $document);

        $this->assertSame(AppraisalExtractionStatus::Failed, $this->service()->run($extraction->id)->status);

        $this->parser->supported = true;
        $this->parser->proposal = $this->proposal();

        $result = $this->service()->run($extraction->id);

        $this->assertSame(AppraisalExtractionStatus::Ready, $result->status);
        $this->assertSame(2, $result->attempts);
        $this->assertNull($result->error_code);
        $this->assertNull($result->failed_at);
    }

    public function test_a_run_left_processing_by_a_killed_worker_is_reclaimed(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->proposal = $this->proposal();
        $extraction = $this->pendingExtraction($order, $document);
        $extraction->transitionTo(AppraisalExtractionStatus::Processing, [
            'attempts' => 1,
            'started_at' => now()->subSeconds(AppraisalExtractionService::STALE_PROCESSING_SECONDS + 1),
        ]);

        $result = $this->service()->run($extraction->id);

        $this->assertSame(AppraisalExtractionStatus::Ready, $result->status);
        $this->assertSame(2, $result->attempts);
    }

    public function test_a_run_still_processing_elsewhere_is_left_alone(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->proposal = $this->proposal();
        $extraction = $this->pendingExtraction($order, $document);
        $extraction->transitionTo(AppraisalExtractionStatus::Processing, ['attempts' => 1, 'started_at' => now()]);

        $result = $this->service()->run($extraction->id);

        $this->assertSame(AppraisalExtractionStatus::Processing, $result->status);
        $this->assertSame([], $this->parser->calls);
    }

    public function test_running_a_ready_extraction_again_is_a_no_op(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->proposal = $this->proposal();
        $extraction = $this->pendingExtraction($order, $document);

        $this->service()->run($extraction->id);
        $again = $this->service()->run($extraction->id);

        $this->assertSame(AppraisalExtractionStatus::Ready, $again->status);
        $this->assertSame(1, $again->attempts);
        $this->assertCount(1, $this->parser->calls);
    }

    public function test_the_job_runs_the_extraction(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->proposal = $this->proposal();
        $extraction = $this->pendingExtraction($order, $document);

        ExtractAppraisalPositions::dispatchSync($extraction->id);

        $this->assertSame(AppraisalExtractionStatus::Ready, $extraction->fresh()->status);
    }

    public function test_the_job_marks_the_run_failed_once_retries_are_exhausted(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $extraction = $this->pendingExtraction($order, $document);

        (new ExtractAppraisalPositions($extraction->id))->failed(new RuntimeException('Worker timed out.'));

        $fresh = $extraction->fresh();
        $this->assertSame(AppraisalExtractionStatus::Failed, $fresh->status);
        $this->assertSame(AppraisalExtractionException::UNEXPECTED_ERROR, $fresh->error_code);
        $this->assertStringNotContainsString('Worker timed out.', (string) $fresh->error_message);
    }

    public function test_the_job_is_unique_per_extraction(): void
    {
        $job = new ExtractAppraisalPositions('extraction-1');

        $this->assertSame('extraction-1', $job->uniqueId());
        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120], $job->backoff());
    }

    public function test_the_default_bindings_never_extract(): void
    {
        $this->app->forgetInstance(AppraisalDocumentParser::class);
        $this->app->forgetInstance(AppraisalAiExtractor::class);

        config(['gutachten.pdftotext.binary' => '/nonexistent/bin/pdftotext']);

        $this->assertInstanceOf(PdfGutachtenParser::class, app(AppraisalDocumentParser::class));
        $this->assertInstanceOf(DisabledAppraisalAiExtractor::class, app(AppraisalAiExtractor::class));

        [$order, $document] = $this->orderWithGutachten();

        $result = app(AppraisalExtractionService::class)->run($this->pendingExtraction($order, $document)->id);

        $this->assertSame(AppraisalExtractionStatus::Failed, $result->status);
        $this->assertSame(AppraisalExtractionException::NO_EXTRACTOR_AVAILABLE, $result->error_code);
    }

    public function test_a_proposal_can_be_discarded(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->proposal = $this->proposal();
        $extraction = $this->service()->run($this->pendingExtraction($order, $document)->id);
        $admin = $this->admin();

        $discarded = $this->service()->discard($extraction, $admin);

        $this->assertSame(AppraisalExtractionStatus::Discarded, $discarded->status);
        $this->assertSame($admin->id, $discarded->discarded_by_user_id);
        $this->assertNotNull($discarded->discarded_at);
        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'order_id' => $order->id,
            'action' => 'APPRAISAL_EXTRACTION_DISCARDED',
            'changed_by_user_id' => $admin->id,
        ]);

        $this->assertRefused(fn () => $this->service()->discard($discarded, $admin), 422);
    }

    public function test_only_a_ready_proposal_is_applicable(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->proposal = $this->proposal();
        $extraction = $this->pendingExtraction($order, $document);

        $this->assertRefused(fn () => $this->service()->applicableProposal($extraction), 422);

        $ready = $this->service()->run($extraction->id);
        $proposal = $this->service()->applicableProposal($ready);

        $this->assertEquals($this->proposal(), $proposal);
        $this->assertSame(0, AppraisalPosition::count());
    }

    public function test_a_ready_proposal_is_not_applicable_once_an_offer_is_accepted(): void
    {
        [$order, $document] = $this->orderWithGutachten();
        $this->parser->proposal = $this->proposal();
        $ready = $this->service()->run($this->pendingExtraction($order, $document)->id);

        LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'selected',
        ]);

        $this->assertRefused(fn () => $this->service()->applicableProposal($ready), 422);
    }

    private function service(): AppraisalExtractionService
    {
        return app(AppraisalExtractionService::class);
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
            'vin' => self::VIN,
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);

        $contents = '%PDF-1.7 '.fake()->uuid();

        return [$order, $this->document($order, 'erstgutachten.pdf', $contents), $contents];
    }

    private function document(LeasybackOrder $order, string $name, string $contents): VehicleReportDocument
    {
        $path = "vehicle-reports/{$order->auftragsnummer}/{$name}";

        Storage::disk('documents')->put($path, $contents);

        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => 'gutachten',
            'path' => $path,
        ]);
    }

    private function pendingExtraction(LeasybackOrder $order, VehicleReportDocument $document): AppraisalExtraction
    {
        return AppraisalExtraction::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'source_document_id' => $document->id,
        ]);
    }

    private function proposal(): AppraisalExtractionProposal
    {
        return new AppraisalExtractionProposal(
            lines: [
                new AppraisalProposalLine('Stoßfänger vorne', '850.00', chargeableAmountNet: '700.00', damageDescription: 'Kratzer', repairMethod: 'Lackierung', pageNumber: 4, confidence: 0.95),
                new AppraisalProposalLine('Kotflügel links', '420.50', repairMethod: 'Instandsetzung', pageNumber: 4, confidence: 0.9),
            ],
            appraisalNumber: 'GA-2026-0042',
            appraisalDate: '2026-09-01',
            vin: self::VIN,
            currency: 'EUR',
            totalNet: '1120.50',
        );
    }

    private function assertRefused(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('The action was not refused.');
        } catch (HttpResponseException $exception) {
            $this->assertSame($status, $exception->getResponse()->getStatusCode());
        }
    }
}
