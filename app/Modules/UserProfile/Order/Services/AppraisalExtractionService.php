<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\LeasybackOffer;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Modules\UserProfile\Order\Contracts\AppraisalAiExtractor;
use App\Modules\UserProfile\Order\Contracts\AppraisalDocumentParser;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionProposal;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use App\Modules\UserProfile\Order\Jobs\ExtractAppraisalPositions;
use App\Modules\UserProfile\Order\Models\AppraisalExtraction;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AppraisalExtractionService
{
    public const STALE_PROCESSING_SECONDS = 600;

    private const ERROR_MESSAGE_LIMIT = 2000;

    public function __construct(
        private readonly AppraisalDocumentParser $parser,
        private readonly AppraisalAiExtractor $aiExtractor,
        private readonly AppraisalProposalValidator $validator,
        private readonly AppraisalPositionService $positions,
        private readonly DamageImageMatcher $imageMatcher,
    ) {}

    public function start(LeasybackOrder $order, VehicleReportDocument $document, ?User $user = null): AppraisalExtraction
    {
        if ($document->auftragsnummer !== $order->auftragsnummer || $document->vehicle_id !== $order->vehicle_id) {
            $this->fail(422, 'Das Dokument gehört nicht zu diesem Auftrag.');
        }

        if (strtolower(pathinfo((string) $document->path, PATHINFO_EXTENSION)) !== 'pdf') {
            $this->fail(422, 'Nur Gutachten im PDF-Format können ausgelesen werden.');
        }

        return DB::transaction(function () use ($order, $document, $user) {
            $status = LeasybackOrder::whereKey($order->id)->lockForUpdate()->value('order_status');

            if (! in_array($status, AppraisalPositionService::EDITABLE_STATUSES, true)) {
                $this->fail(422, 'Gutachten können nur zwischen Begutachtung und Angebotsfreigabe ausgelesen werden.');
            }

            if ($this->hasSelectedOffer($order->id)) {
                $this->fail(422, 'Der Kunde hat bereits ein Angebot freigegeben. Das Gutachten wird nicht mehr ausgelesen.');
            }

            $active = AppraisalExtraction::where('order_id', $order->id)
                ->where('source_document_id', $document->id)
                ->whereIn('status', AppraisalExtractionStatus::activeValues())
                ->latest()
                ->first();

            if ($active !== null) {
                return $active;
            }

            $extraction = AppraisalExtraction::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'source_document_id' => $document->id,
                'status' => AppraisalExtractionStatus::Pending,
                'requested_by_user_id' => $user?->id,
            ]);

            $this->audit($extraction, $order->vehicle_id, 'APPRAISAL_EXTRACTION_REQUESTED', ['document_id' => $document->id], $user?->id);

            ExtractAppraisalPositions::dispatch($extraction->id)->afterCommit();

            return $extraction;
        });
    }

    public function run(string $extractionId): ?AppraisalExtraction
    {
        [$extraction, $claimed] = $this->claim($extractionId);

        if (! $claimed) {
            return $extraction;
        }

        $notes = [];

        try {
            $input = $this->input($extraction);
            $extraction->forceFill(['input_sha256' => $input->sha256])->save();

            [$result, $warnings] = $this->extract($input, $notes);

            $extraction->transitionTo(AppraisalExtractionStatus::Ready, [
                'source' => $result->source,
                'extractor_version' => $result->extractorVersion,
                'proposal' => $result->proposal->toArray(),
                'warnings' => [...$notes, ...$result->warnings, ...$warnings],
                'error_code' => null,
                'error_message' => null,
                'completed_at' => now(),
            ]);

            $this->audit($extraction, $input->vehicleId, 'APPRAISAL_EXTRACTION_READY', [
                'source' => $result->source->value,
                'extractor_version' => $result->extractorVersion,
                'line_count' => count($result->proposal->lines),
            ]);
        } catch (AppraisalExtractionException $exception) {
            $this->markFailed($extraction, $exception->errorCode, $exception->getMessage(), $notes);
        } catch (Throwable $exception) {
            if ($extraction->status === AppraisalExtractionStatus::Processing) {
                $extraction->transitionTo(AppraisalExtractionStatus::Pending);
            }

            throw $exception;
        }

        return $extraction->fresh();
    }

    public function failAfterRetries(string $extractionId, Throwable $exception): void
    {
        $extraction = AppraisalExtraction::find($extractionId);

        if ($extraction === null || ! $extraction->status->canTransitionTo(AppraisalExtractionStatus::Failed)) {
            return;
        }

        Log::error('Appraisal extraction failed after retries', [
            'extraction_id' => $extractionId,
            'exception' => $exception::class,
        ]);

        $this->markFailed($extraction, AppraisalExtractionException::UNEXPECTED_ERROR, 'Die Auslese ist wiederholt fehlgeschlagen.');
    }

    public function discard(AppraisalExtraction $extraction, User $user): AppraisalExtraction
    {
        return DB::transaction(function () use ($extraction, $user) {
            $locked = AppraisalExtraction::whereKey($extraction->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(AppraisalExtractionStatus::Discarded)) {
                $this->fail(422, 'Dieser Vorschlag kann nicht mehr verworfen werden.');
            }

            $locked->transitionTo(AppraisalExtractionStatus::Discarded, [
                'discarded_at' => now(),
                'discarded_by_user_id' => $user->id,
            ]);

            $this->audit($locked, $this->vehicleIdFor($locked), 'APPRAISAL_EXTRACTION_DISCARDED', null, $user->id);

            return $locked;
        });
    }

    public static function applyRules(array $allowedDocumentIds = []): array
    {
        return [
            'positions' => ['present', 'array', 'min:1', 'max:200'],
            'positions.*.component' => ['required', 'string', 'max:255'],
            'positions.*.damage_description' => ['nullable', 'string', 'max:2000'],
            'positions.*.original_amount_net' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'positions.*.chargeable_amount_net' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'positions.*.repair_method' => ['nullable', 'string', 'max:255'],
            'positions.*.damage_image_document_ids' => ['nullable', 'array', 'max:50'],
            'positions.*.damage_image_document_ids.*' => ['uuid', Rule::in($allowedDocumentIds)],
        ];
    }

    public function apply(AppraisalExtraction $extraction, User $user, array $validated): int
    {
        return DB::transaction(function () use ($extraction, $user, $validated) {
            $locked = AppraisalExtraction::whereKey($extraction->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== AppraisalExtractionStatus::Ready || $locked->proposal === null) {
                $this->fail(422, 'Dieser Vorschlag wurde bereits übernommen oder liegt nicht mehr vor.');
            }

            $order = LeasybackOrder::whereKey($locked->order_id)->first();

            if ($order === null) {
                $this->fail(404, 'Der Auftrag zu diesem Vorschlag existiert nicht mehr.');
            }

            $created = $this->positions->appendExtracted($order, $user, $validated['positions']);

            $locked->transitionTo(AppraisalExtractionStatus::Applied, [
                'applied_at' => now(),
                'applied_by_user_id' => $user->id,
            ]);

            $this->audit($locked, $order->vehicle_id, 'APPRAISAL_EXTRACTION_APPLIED', [
                'position_count' => $created,
                'line_count' => count($locked->proposal['lines'] ?? []),
            ], $user->id);

            return $created;
        });
    }

    public function applicableProposal(AppraisalExtraction $extraction): AppraisalExtractionProposal
    {
        if ($extraction->status !== AppraisalExtractionStatus::Ready || $extraction->proposal === null) {
            $this->fail(422, 'Nur fertig ausgelesene Vorschläge können übernommen werden.');
        }

        $status = LeasybackOrder::whereKey($extraction->order_id)->value('order_status');

        if (! in_array($status, AppraisalPositionService::EDITABLE_STATUSES, true)) {
            $this->fail(422, 'Gutachtenpositionen können nur zwischen Begutachtung und Angebotsfreigabe bearbeitet werden.');
        }

        if ($this->hasSelectedOffer($extraction->order_id)) {
            $this->fail(422, 'Der Kunde hat bereits ein Angebot freigegeben. Die Gutachtenpositionen können nicht mehr geändert werden.');
        }

        return AppraisalExtractionProposal::fromArray($extraction->proposal);
    }

    public function forOrder(string $orderId): array
    {
        $extractions = AppraisalExtraction::where('order_id', $orderId)
            ->orderByDesc('created_at')
            ->get();

        $order = $extractions->contains(fn (AppraisalExtraction $extraction) => $extraction->status === AppraisalExtractionStatus::Ready)
            ? LeasybackOrder::whereKey($orderId)->first()
            : null;

        return $extractions->map(fn (AppraisalExtraction $extraction) => $this->present($extraction, $order))->all();
    }

    private function suggestionsFor(?LeasybackOrder $order, AppraisalExtraction $extraction): array
    {
        if ($order === null || $extraction->status !== AppraisalExtractionStatus::Ready || $extraction->proposal === null) {
            return [];
        }

        return $this->imageMatcher->suggest($order, AppraisalExtractionProposal::fromArray($extraction->proposal));
    }

    private function present(AppraisalExtraction $extraction, ?LeasybackOrder $order = null): array
    {
        $proposal = $extraction->proposal ?? [];
        $lines = array_values(array_filter(is_array($proposal['lines'] ?? null) ? $proposal['lines'] : [], is_array(...)));
        $suggestions = $this->suggestionsFor($order, $extraction);

        return [
            'id' => $extraction->id,
            'status' => $extraction->status->value,
            'source' => $extraction->source?->value,
            'source_document_id' => $extraction->source_document_id,
            'extractor_version' => $extraction->extractor_version,
            'attempts' => $extraction->attempts,
            'line_count' => count($lines),
            'total_net' => $proposal['total_net'] ?? null,
            'appraisal_number' => $proposal['appraisal_number'] ?? null,
            'appraisal_date' => $proposal['appraisal_date'] ?? null,
            'warnings' => array_values(array_map(
                fn (array $warning) => ['code' => $warning['code'] ?? null, 'message' => $warning['message'] ?? null],
                array_filter($extraction->warnings ?? [], is_array(...)),
            )),
            'error_code' => $extraction->error_code,
            'error_message' => $extraction->error_message,
            'lines' => array_values(array_map(fn (int $index, array $line) => [
                'component' => (string) ($line['component'] ?? ''),
                'damage_description' => $line['damage_description'] ?? null,
                'original_amount_net' => $line['original_amount_net'] ?? null,
                'chargeable_amount_net' => $line['chargeable_amount_net'] ?? null,
                'repair_method' => $line['repair_method'] ?? null,
                'page_number' => isset($line['page_number']) ? (int) $line['page_number'] : null,
                'source_text' => $line['source_text'] ?? null,
                'confidence' => isset($line['confidence']) ? (float) $line['confidence'] : null,
                'damage_number' => isset($line['damage_number']) ? (int) $line['damage_number'] : null,
                'suggested_images' => $this->suggestedImages($suggestions[$index] ?? null),
            ], array_keys($lines), $lines)),
            'requested_by_user_id' => $extraction->requested_by_user_id,
            'applied_at' => $extraction->applied_at?->toISOString(),
            'applied_by_name' => $extraction->applied_by_user_id === null
                ? null
                : DB::table('users')->where('id', $extraction->applied_by_user_id)->value('name'),
            'created_at' => $extraction->created_at?->toISOString(),
            'started_at' => $extraction->started_at?->toISOString(),
            'completed_at' => $extraction->completed_at?->toISOString(),
            'failed_at' => $extraction->failed_at?->toISOString(),
        ];
    }

    private function suggestedImages(?array $suggestion): array
    {
        if ($suggestion === null) {
            return [];
        }

        return array_values(array_map(fn (string $documentId) => [
            'document_id' => $documentId,
            'url' => route('admin.vehicles.reports.image', $documentId),
            'strategy' => $suggestion['strategy'],
        ], $suggestion['document_ids']));
    }

    private function claim(string $extractionId): array
    {
        return DB::transaction(function () use ($extractionId) {
            $extraction = AppraisalExtraction::whereKey($extractionId)->lockForUpdate()->first();

            if ($extraction !== null && $this->isStale($extraction)) {
                $extraction->transitionTo(AppraisalExtractionStatus::Pending);
            }

            if ($extraction === null || ! in_array($extraction->status, [AppraisalExtractionStatus::Pending, AppraisalExtractionStatus::Failed], true)) {
                return [$extraction, false];
            }

            $extraction->transitionTo(AppraisalExtractionStatus::Processing, [
                'attempts' => $extraction->attempts + 1,
                'started_at' => now(),
                'failed_at' => null,
                'error_code' => null,
                'error_message' => null,
            ]);

            return [$extraction, true];
        });
    }

    private function isStale(AppraisalExtraction $extraction): bool
    {
        return $extraction->status === AppraisalExtractionStatus::Processing
            && ($extraction->started_at === null || $extraction->started_at->lte(now()->subSeconds(self::STALE_PROCESSING_SECONDS)));
    }

    private function input(AppraisalExtraction $extraction): AppraisalExtractionInput
    {
        $document = $extraction->source_document_id === null
            ? null
            : VehicleReportDocument::whereKey($extraction->source_document_id)->first(['id', 'auftragsnummer', 'vehicle_id', 'path']);

        if ($document === null) {
            throw AppraisalExtractionException::documentMissing();
        }

        $disk = Storage::disk('documents');
        $contents = $disk->exists($document->path) ? $disk->get($document->path) : null;

        if ($contents === null || $contents === '') {
            throw AppraisalExtractionException::documentUnreadable($document->path);
        }

        return new AppraisalExtractionInput(
            extractionId: $extraction->id,
            orderId: $extraction->order_id,
            auftragsnummer: $extraction->auftragsnummer,
            vehicleId: $document->vehicle_id,
            documentId: $document->id,
            fileName: basename($document->path),
            contents: $contents,
            sha256: hash('sha256', $contents),
            vin: Vehicle::where('vehicle_id', $document->vehicle_id)->value('vin'),
        );
    }

    private function extract(AppraisalExtractionInput $input, array &$notes): array
    {
        $candidates = [];

        if ($this->parser->supports($input)) {
            $candidates['parser'] = fn () => $this->parser->parse($input);
        }

        if ($this->aiExtractor->isEnabled()) {
            $candidates['ai'] = fn () => $this->aiExtractor->extract($input);
        }

        if ($candidates === []) {
            throw AppraisalExtractionException::noExtractorAvailable();
        }

        $lastFailure = null;

        foreach ($candidates as $name => $candidate) {
            try {
                $result = $candidate();
                $warnings = $this->validator->validate($result->proposal, $input->vin);

                return [$result, $warnings];
            } catch (AppraisalExtractionException $exception) {
                $lastFailure = $exception;
                $notes[] = [
                    'code' => "{$name}_failed",
                    'message' => Str::limit($exception->getMessage(), 500),
                    'line' => null,
                ];
            }
        }

        throw $lastFailure;
    }

    private function markFailed(AppraisalExtraction $extraction, string $errorCode, string $message, array $notes = []): void
    {
        $extraction->transitionTo(AppraisalExtractionStatus::Failed, [
            'error_code' => $errorCode,
            'error_message' => Str::limit($message, self::ERROR_MESSAGE_LIMIT),
            'warnings' => $notes === [] ? null : $notes,
            'failed_at' => now(),
        ]);

        $this->audit($extraction, $this->vehicleIdFor($extraction), 'APPRAISAL_EXTRACTION_FAILED', ['error_code' => $errorCode]);
    }

    private function hasSelectedOffer(string $orderId): bool
    {
        return LeasybackOffer::where('order_id', $orderId)->where('offer_status', 'selected')->exists();
    }

    private function vehicleIdFor(AppraisalExtraction $extraction): ?string
    {
        return LeasybackOrder::whereKey($extraction->order_id)->value('vehicle_id');
    }

    private function audit(AppraisalExtraction $extraction, ?string $vehicleId, string $action, ?array $values, ?int $userId = null): void
    {
        OrderAuditLog::create([
            'order_id' => $extraction->order_id,
            'vehicle_id' => $vehicleId,
            'action' => $action,
            'old_values' => null,
            'new_values' => ['extraction_id' => $extraction->id, ...($values ?? [])],
            'changed_by_user_id' => $userId,
        ]);
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
