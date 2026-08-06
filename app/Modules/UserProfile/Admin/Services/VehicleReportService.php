<?php

namespace App\Modules\UserProfile\Admin\Services;

use App\Enums\NotificationType;
use App\Models\AssessmentDocument;
use App\Models\User;
use App\Models\VehicleReportDocument;
use App\Models\VehicleReportDocumentLog;
use App\Modules\PartnerApi\Services\PartnerDocumentCatalog;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle as CanonicalVehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Notifications\NotificationPayload;
use App\Services\Notifier;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
    public function __construct(
        private readonly VehicleScopeService $vehicleScope,
        private readonly Notifier $notifier,
        private readonly PartnerWebhookEvents $webhooks,
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

        return ['document' => $doc];
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

        DB::transaction(function () use ($doc, $user, $wasPublished) {
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

        Storage::disk('documents')->delete($doc->path);

        return [
            'message' => 'Vehicle report document deleted successfully',
            'document_id' => $documentId,
            'auftragsnummer' => $doc->auftragsnummer,
            'vehicle_id' => $doc->vehicle_id,
        ];
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
