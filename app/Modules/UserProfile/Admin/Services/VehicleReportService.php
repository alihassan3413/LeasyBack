<?php

namespace App\Modules\UserProfile\Admin\Services;

use App\Enums\DocumentType;
use App\Enums\NotificationType;
use App\Models\AssessmentDocument;
use App\Models\User;
use App\Models\VehicleReportDocument;
use App\Models\VehicleReportDocumentLog;
use App\Modules\PartnerApi\Services\PartnerDocumentCatalog;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\Order\Jobs\ExtractGutachtenImages;
use App\Modules\UserProfile\Order\Jobs\StartAppraisalExtraction;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle as CanonicalVehicle;
use App\Modules\UserProfile\Vehicle\Services\DamageImageThumbnailService;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Modules\UserProfile\Vehicle\Support\ReportDocumentImage;
use App\Notifications\NotificationPayload;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Vehicle report/invoice document management — moved out of the Sanctum
 * API's Admin\Http\Controllers\VehicleReportController (unchanged logic,
 * unchanged response shapes) so the new session-authenticated web Admin
 * vehicle detail page can reuse it without duplicating the storage/DB
 * calls. Authorization (VehicleReportDocumentPolicy) stays the caller's
 * job in both controllers, same as every other *Service in this app.
 *
 * Every action also writes a VehicleReportDocumentLog row — this table
 * existed since before Checkpoint 10 with zero consumers
 * (docs/B2C_ADMIN_MIGRATION_AUDIT.md's "dead schema" item); this service is
 * its evident intended consumer (Checkpoint 12).
 */
class VehicleReportService
{
    private const EXTRACTABLE_DOCUMENT_TYPES = [
        DocumentType::Gutachten->value,
        DocumentType::Nachgutachten->value,
    ];

    public function __construct(
        private readonly VehicleScopeService $vehicleScope,
        private readonly Notifier $notifier,
        private readonly PartnerWebhookEvents $webhooks,
        private readonly DamageImageThumbnailService $thumbnails,
    ) {}

    /**
     * @return array{document: VehicleReportDocument}
     */
    public function transfer(array $validated, User $user): array
    {
        $existing = VehicleReportDocument::where('source_assessment_document_id', $validated['source_assessment_document_id'])->first();
        if ($existing) {
            $this->fail(409, 'This assessment document is already transferred', ['document' => $existing]);
        }

        $source = AssessmentDocument::findOrFail($validated['source_assessment_document_id']);

        $bytes = Storage::disk('s3')->get($source->s3_key);
        if ($bytes === null) {
            $this->fail(500, 'Source assessment document could not be read from storage');
        }

        $filename = basename($source->s3_key);
        $destPath = "vehicle-reports/{$validated['auftragsnummer']}/{$filename}";
        Storage::disk('documents')->put($destPath, $bytes);
        Storage::disk('documents')->setVisibility(dirname($destPath), 'private');
        $this->thumbnails->generate($destPath);

        // Read once and reuse: the notify check below used to test an
        // undefined `$published`, which PHP evaluated as null — so a
        // transfer that went straight to published never notified the
        // customer. (transfer() has no web route yet, which is why nobody
        // noticed.)
        $published = (bool) ($validated['published'] ?? false);

        $doc = DB::transaction(function () use ($validated, $destPath, $published, $user) {
            $doc = VehicleReportDocument::create([
                'auftragsnummer' => $validated['auftragsnummer'],
                'vehicle_id' => $validated['vehicle_id'],
                'document_type' => $validated['document_type'] ?? null,
                'document_title' => $validated['document_title'] ?? null,
                'path' => $destPath,
                'published' => $published,
                'source_assessment_document_id' => $validated['source_assessment_document_id'],
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);

            $this->auditDocument($doc, 'transferred', $user->id);

            $this->announceDocument($doc, $published ? 'available' : null);

            return $doc;
        });

        if ($published) {
            $this->notifyDocumentPublished($doc);
        }

        return ['document' => $doc];
    }

    /**
     * @return array{document: VehicleReportDocument}
     */
    public function upload(string $auftragsnummer, string $vehicleId, UploadedFile $file, ?string $documentType, ?string $documentTitle, bool $published, User $user): array
    {
        $originalFilename = $file->getClientOriginalName();
        $path = "vehicle-reports/{$auftragsnummer}/{$originalFilename}";

        $existing = VehicleReportDocument::where('vehicle_id', $vehicleId)
            ->where('auftragsnummer', $auftragsnummer)
            ->where('path', $path)
            ->first();

        if ($existing) {
            $this->fail(409, 'A file with this name already exists for this vehicle and auftragsnummer', [
                'file_name' => $originalFilename,
                'existing_document' => $existing,
            ]);
        }

        Storage::disk('documents')->put($path, file_get_contents($file));
        Storage::disk('documents')->setVisibility(dirname($path), 'private');
        $this->thumbnails->generate($path);

        $doc = DB::transaction(function () use ($auftragsnummer, $vehicleId, $documentType, $documentTitle, $path, $published, $user) {
            $doc = VehicleReportDocument::create([
                'auftragsnummer' => $auftragsnummer,
                'vehicle_id' => $vehicleId,
                'document_type' => $documentType,
                'document_title' => $documentTitle,
                'path' => $path,
                'published' => $published,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);

            $this->auditDocument($doc, 'uploaded', $user->id);

            $this->announceDocument($doc, $published ? 'available' : null);

            return $doc;
        });

        if ($published) {
            $this->notifyDocumentPublished($doc);
        }

        $this->startAppraisalExtraction($doc, $user);

        return ['document' => $doc];
    }

    private function startAppraisalExtraction(VehicleReportDocument $document, User $user): void
    {
        $isAppraisal = in_array(strtolower((string) $document->document_type), self::EXTRACTABLE_DOCUMENT_TYPES, true);
        $isPdf = strtolower(pathinfo((string) $document->path, PATHINFO_EXTENSION)) === 'pdf';

        if (! $isAppraisal || ! $isPdf) {
            return;
        }

        StartAppraisalExtraction::dispatch($document->id, $user->id)->afterCommit();
        ExtractGutachtenImages::dispatch($document->id)->afterCommit();
    }

    public function storeGeneratedDocument(
        string $auftragsnummer,
        string $vehicleId,
        string $filename,
        string $contents,
        string $documentType,
        string $documentTitle,
        bool $notifyCustomer = true,
        bool $published = true,
    ): VehicleReportDocument {
        $path = $this->generatedDocumentPath($auftragsnummer, $filename);

        $existing = VehicleReportDocument::where('vehicle_id', $vehicleId)
            ->where('auftragsnummer', $auftragsnummer)
            ->where('path', $path)
            ->first();

        // The 'documents' disk has throw/report both disabled (config/filesystems.php),
        // so a failed write returns false silently — check it, or a Lexware invoice can
        // get marked Documented with no PDF ever on disk.
        if (! Storage::disk('documents')->put($path, $contents)) {
            throw new RuntimeException("Failed to write generated document to the 'documents' disk: {$path}");
        }

        Storage::disk('documents')->setVisibility(dirname($path), 'private');
        $this->thumbnails->generate($path);

        if ($existing !== null) {
            return $existing;
        }

        $doc = DB::transaction(function () use ($auftragsnummer, $vehicleId, $documentType, $documentTitle, $path, $published) {
            $doc = VehicleReportDocument::create([
                'auftragsnummer' => $auftragsnummer,
                'vehicle_id' => $vehicleId,
                'document_type' => $documentType,
                'document_title' => $documentTitle,
                'path' => $path,
                'published' => $published,
                'created_by_user_id' => null,
                'updated_by_user_id' => null,
            ]);

            $this->auditDocument($doc, 'uploaded', null);
            $this->announceDocument($doc, $published ? 'available' : null);

            return $doc;
        });

        if ($notifyCustomer && $published) {
            $this->notifyDocumentPublished($doc);
        }

        return $doc;
    }

    public function fileExists(string $path): bool
    {
        return Storage::disk('documents')->exists($path);
    }

    /**
     * Resolving the thumbnail here rather than in the controller keeps the
     * fallback in one place: asking for a thumbnail that was never generated
     * — an unsupported host, a failed resize, a document uploaded before
     * thumbnails existed — quietly serves the original instead of 404ing.
     */
    public function image(VehicleReportDocument $document, bool $thumbnail = false): ?array
    {
        $contentType = ReportDocumentImage::contentTypeFor((string) $document->path);

        if ($contentType === null || ! $this->fileExists($document->path)) {
            return null;
        }

        if ($thumbnail) {
            $thumbnailPath = ReportDocumentImage::thumbnailPathFor((string) $document->path);

            if ($thumbnailPath !== null && $this->fileExists($thumbnailPath)) {
                return ['path' => $thumbnailPath, 'content_type' => ReportDocumentImage::THUMBNAIL_CONTENT_TYPE];
            }
        }

        return ['path' => $document->path, 'content_type' => $contentType];
    }

    /**
     * @return array{message: string, action?: string, document: VehicleReportDocument}
     */
    public function publish(string $documentId, bool $published, User $user): array
    {
        $doc = VehicleReportDocument::find($documentId);
        if (! $doc) {
            $this->fail(404, 'Vehicle report document not found');
        }

        if ($doc->published === $published) {
            return ['message' => 'Document published status is already same', 'document' => $doc];
        }

        $doc = DB::transaction(function () use ($doc, $published, $user) {
            $doc->update(['published' => $published, 'updated_by_user_id' => $user->id]);
            $this->auditDocument($doc, $published ? 'published' : 'unpublished', $user->id);

            $fresh = $doc->fresh();

            // Withdrawing publication is `document.replaced`, not a deletion
            // event: from the partner's side the document they were told about
            // is no longer the current one, which is exactly what that event
            // means. The file itself still exists.
            $this->announceDocument($fresh, $published ? 'available' : 'replaced', $published ? null : 'unpublished');

            return $fresh;
        });

        if ($published) {
            $this->notifyDocumentPublished($doc);
        }

        return [
            'message' => 'Document published status updated successfully',
            'action' => $published ? 'published' : 'unpublished',
            'document' => $doc,
        ];
    }

    /**
     * Tell partners about a report document, in the same shape
     * `GET /documents/{id}` returns.
     *
     * Metadata only — PartnerDocumentCatalog::fromReport() produces the value
     * object whose `$path` PartnerDocumentResource never reads, so the storage
     * key cannot reach a webhook body, and the bytes certainly cannot: a
     * webhook says a document exists, and the partner fetches it over the
     * authenticated download endpoint if they want it.
     *
     * @param  'available'|'replaced'|null  $what  null emits nothing, which is
     *                                             how an unpublished document stays invisible
     */
    private function announceDocument(?VehicleReportDocument $doc, ?string $what, ?string $reason = null): void
    {
        if ($doc === null || $what === null) {
            return;
        }

        $vehicle = CanonicalVehicle::find($doc->vehicle_id);
        $order = LeasybackOrder::where('auftragsnummer', $doc->auftragsnummer)->first();
        $document = PartnerDocumentCatalog::fromReport($doc, $order);

        if ($what === 'available') {
            $this->webhooks->documentAvailable($document, $order, $vehicle);

            return;
        }

        $this->webhooks->documentReplaced($document, $order, $vehicle, $reason ?? 'replaced');
    }

    private function notifyDocumentPublished(VehicleReportDocument $doc): void
    {
        $vehicle = CanonicalVehicle::find($doc->vehicle_id);

        if ($vehicle === null) {
            return;
        }

        $label = $doc->document_title ?: ($doc->document_type ?: 'Dokument');

        $this->notifier->send(
            $this->vehicleScope->resolveOwnerUsers($vehicle),
            NotificationPayload::make(
                NotificationType::ReportPublished,
                'Neues Dokument verfügbar',
                sprintf('%s für %s wurde bereitgestellt.', $label, $vehicle->license_plate),
                '/dashboard',
                ['auftragsnummer' => $doc->auftragsnummer, 'document_id' => $doc->document_id],
            ),
        );
    }

    /**
     * @return array{message: string, document_id: string, auftragsnummer: string, vehicle_id: string}
     */
    public function delete(string $documentId, User $user): array
    {
        $doc = VehicleReportDocument::find($documentId);
        if (! $doc) {
            $this->fail(404, 'Vehicle report document not found');
        }

        $orderStatus = DB::table('leasyback_orders')
            ->where('auftragsnummer', $doc->auftragsnummer)
            ->value('order_status');

        if ($orderStatus === 'delivered') {
            $this->fail(409, 'Document cannot be deleted because order status is delivered', [
                'order_status' => $orderStatus,
                'document_id' => $doc->id,
            ]);
        }

        $wasPublished = (bool) $doc->published;
        $derived = $this->derivedGutachtenImages($doc);

        DB::transaction(function () use ($doc, $user, $wasPublished, $derived) {
            // The images extracted from this Gutachten go with it. They exist
            // only as a by-product of the source PDF, so leaving them behind
            // strands them in the damage image picker with nothing to trace
            // them back to, and re-uploading a corrected Gutachten would stack
            // a second full set on top of the first.
            foreach ($derived as $image) {
                $this->auditDocument($image, 'deleted', $user->id);

                if ((bool) $image->published) {
                    $this->announceDocument($image, 'replaced', 'deleted');
                }

                $image->delete();
            }

            $this->auditDocument($doc, 'deleted', $user->id);

            // Announced before the row goes, so the payload can still describe
            // what was withdrawn. Only for a document the customer could
            // actually see — an unpublished one was never announced, so there
            // is nothing to retract.
            if ($wasPublished) {
                $this->announceDocument($doc, 'replaced', 'deleted');
            }

            $doc->delete();
        });

        $paths = [$doc->path, ...$derived->pluck('path')->all()];

        // Thumbnails have no row of their own, so they would otherwise outlive
        // every image they were derived from.
        Storage::disk('documents')->delete([
            ...$paths,
            ...array_filter(array_map(
                fn (string $path) => ReportDocumentImage::thumbnailPathFor($path),
                $paths,
            )),
        ]);

        return [
            'message' => 'Vehicle report document deleted successfully',
            'document_id' => $documentId,
            'auftragsnummer' => $doc->auftragsnummer,
            'vehicle_id' => $doc->vehicle_id,
        ];
    }

    private function generatedDocumentPath(string $auftragsnummer, string $filename): string
    {
        return "vehicle-reports/{$auftragsnummer}/{$filename}";
    }

    /**
     * Images ExtractGutachtenImages produced from this document, matched on the
     * storage prefix the job files them under plus its document type. Both
     * conditions are needed: the prefix alone would be enough, but the type
     * keeps a hand-uploaded file that happens to sit in that directory out of
     * the result. A TÜV SÜD AnsichtsFoto is excluded by either one — those are
     * transferred to a flat vehicle-reports/{auftragsnummer}/ path and carry
     * their own document type.
     *
     * @return Collection<int, VehicleReportDocument>
     */
    private function derivedGutachtenImages(VehicleReportDocument $doc): Collection
    {
        $prefix = $this->generatedDocumentPath(
            (string) $doc->auftragsnummer,
            ExtractGutachtenImages::directoryFor((string) $doc->id),
        ).'/';

        return VehicleReportDocument::query()
            ->where('vehicle_id', $doc->vehicle_id)
            ->where('auftragsnummer', $doc->auftragsnummer)
            ->where('document_type', ExtractGutachtenImages::DOCUMENT_TYPE)
            ->where('path', 'like', $prefix.'%')
            ->get();
    }

    private function auditDocument(VehicleReportDocument $doc, string $action, ?int $userId): void
    {
        VehicleReportDocumentLog::create([
            'document_id' => $doc->id,
            'auftragsnummer' => $doc->auftragsnummer,
            'vehicle_id' => $doc->vehicle_id,
            'action' => $action,
            's3_bucket' => null,
            's3_key' => $doc->path,
            's3_url' => null,
            'changed_by_user_id' => $userId,
        ]);
    }

    private function fail(int $status, string $message, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['error' => $message, ...$extra], $status));
    }
}
