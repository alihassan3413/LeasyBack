<?php

namespace App\Modules\UserProfile\Admin\Services;

use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Tim\Services\TimService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Dokumente abrufen" — pull a vehicle's TÜV SÜD appraisal (Gutachten) and
 * copy the documents it produces into the vehicle's own report repository.
 *
 * v1 drove this from the browser: sync, reload, then one transfer request per
 * document, silently swallowing per-document failures. It runs server-side
 * here instead, so a partial pull is reported rather than hidden, and the
 * caller makes one request.
 *
 * The two halves already existed and are reused unchanged: TimService::sync()
 * (SOAP call + `vehicle_assessments`/`assessment_documents` ingest, extracted
 * previously but until now unwired) and VehicleReportService::transfer()
 * (copy one assessment document into `vehicle_report_documents`).
 */
class AppraisalDocumentPullService
{
    public function __construct(
        private readonly TimService $timService,
        private readonly VehicleReportService $vehicleReportService,
    ) {}

    /**
     * @return array{auftragsnummer: string, transferred: int, skipped: int, already_synced: bool}
     */
    public function pull(string $vehicleId, User $user): array
    {
        $order = $this->appraisalOrder($vehicleId);

        // response_body holds the Gutachtennummer TÜV SÜD returned when the
        // order was placed — the id TIM's assessment lookup is keyed on.
        $bewertungId = $this->bewertungId($order);

        $alreadySynced = $this->runSync($bewertungId, $user);
        $documents = $this->assessmentDocuments($order->auftragsnummer);

        if ($documents->isEmpty()) {
            $this->fail(422, $alreadySynced
                ? 'Für diesen Auftrag liegen bei TÜV SÜD keine Dokumente vor.'
                : 'Die Synchronisierung hat keine Dokumente geliefert.');
        }

        $transferred = 0;
        $skipped = 0;

        foreach ($documents as $document) {
            // Already-transferred documents come back as a 409 from
            // transfer(); that is the normal case on a repeat pull, so it
            // counts as skipped rather than failing the whole run.
            try {
                $this->vehicleReportService->transfer([
                    'auftragsnummer' => $order->auftragsnummer,
                    'vehicle_id' => $vehicleId,
                    'document_type' => $document->doc_type,
                    'document_title' => $document->title,
                    // Never auto-publish: the admin releases each document to
                    // the customer explicitly, same as an uploaded one.
                    'published' => false,
                    'source_assessment_document_id' => $document->id,
                ], $user);

                $transferred++;
            } catch (HttpResponseException $e) {
                $skipped++;

                Log::info('Assessment document not transferred', [
                    'assessment_document_id' => $document->id,
                    'auftragsnummer' => $order->auftragsnummer,
                    'status' => $e->getResponse()->getStatusCode(),
                ]);
            }
        }

        return [
            'auftragsnummer' => $order->auftragsnummer,
            'transferred' => $transferred,
            'skipped' => $skipped,
            'already_synced' => $alreadySynced,
        ];
    }

    /**
     * The vehicle's TÜV SÜD order carrying an appraisal number. Mirrors v1's
     * appraisalOrderForVehicle(): prefer an order that actually has a
     * response_body, newest first.
     */
    private function appraisalOrder(string $vehicleId): LeasybackOrder
    {
        $order = LeasybackOrder::where('vehicle_id', $vehicleId)
            ->where('leasyback_partner', 'tuvsud')
            ->orderByRaw('CASE WHEN response_body IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('created_at')
            ->first();

        if ($order === null) {
            $this->fail(422, 'Für dieses Fahrzeug existiert kein TÜV SÜD Auftrag.');
        }

        return $order;
    }

    private function bewertungId(LeasybackOrder $order): int
    {
        $raw = $order->response_body;

        // TÜV SÜD returns the number bare; tolerate the {"bewertung_id": n}
        // envelope too rather than guessing wrong on a 404 later.
        if (is_array($raw)) {
            $raw = $raw['bewertung_id'] ?? $raw['gutachtennummer'] ?? null;
        }

        if (! is_numeric($raw)) {
            $this->fail(422, 'Für diesen Auftrag liegt noch keine Gutachtennummer vor.');
        }

        return (int) $raw;
    }

    /**
     * @return bool True when TIM reported the assessment as already ingested,
     *              which is a normal repeat pull rather than a failure — the
     *              transfer step below still runs against what is on record.
     */
    private function runSync(int $bewertungId, User $user): bool
    {
        try {
            $result = $this->timService->sync($bewertungId, $user->id);
        } catch (\Throwable $e) {
            Log::error('TIM appraisal sync failed', ['bewertung_id' => $bewertungId, 'error' => $e->getMessage()]);

            $this->fail(502, 'TÜV SÜD ist derzeit nicht erreichbar. Bitte später erneut versuchen.');
        }

        $status = $result['status'] ?? 500;

        if ($status === 200) {
            return false;
        }

        // 400/404 here both mean "already processed" (see TimService::sync);
        // anything else is a real failure.
        if (in_array($status, [400, 404], true) && isset($result['body']['message'])) {
            return true;
        }

        $this->fail(502, (string) ($result['body']['error'] ?? 'Die Synchronisierung mit TÜV SÜD ist fehlgeschlagen.'));
    }

    private function assessmentDocuments(string $auftragsnummer): Collection
    {
        return DB::table('assessment_documents as ad')
            ->join('vehicle_assessments as va', 'va.id', '=', 'ad.assessment_id')
            ->where('va.auftragsnummer', $auftragsnummer)
            ->orderBy('ad.sort_order')
            ->get(['ad.id', 'ad.doc_type', 'ad.title']);
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
