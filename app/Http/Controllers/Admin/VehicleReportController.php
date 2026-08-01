<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Controllers\Controller;
use App\Models\VehicleReportDocument;
use App\Modules\UserProfile\Admin\Services\VehicleReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Session-authenticated counterpart of the Sanctum API's
 * Admin\Http\Controllers\VehicleReportController — same
 * VehicleReportService, same VehicleReportDocumentPolicy checks. Only
 * upload/publish/delete are exposed here; transfer() (copying an
 * already-synced TIM assessment document in) has no web route yet — there
 * is no TIM assessment browsing UI anywhere in this app for an admin to
 * pick a source document from, so a transfer route with nothing to call it
 * would be dead code (see Checkpoint 10 decisions).
 */
class VehicleReportController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(private readonly VehicleReportService $vehicleReportService) {}

    public function upload(Request $request, string $vehicleId): RedirectResponse
    {
        $request->user()->can('create', VehicleReportDocument::class) || abort(403, 'Only admin can upload published documents');

        $validated = $request->validate([
            'auftragsnummer' => 'required|string',
            'document_type' => 'nullable|string',
            'document_title' => 'nullable|string',
            'published' => 'nullable|boolean',
            'file' => 'required|file|max:51200',
        ]);

        return $this->withServiceErrorHandling('report', function () use ($request, $vehicleId, $validated) {
            $this->vehicleReportService->upload(
                $validated['auftragsnummer'],
                $vehicleId,
                $request->file('file'),
                $validated['document_type'] ?? null,
                $validated['document_title'] ?? null,
                (bool) ($validated['published'] ?? false),
                $request->user(),
            );
        }) ?? back();
    }

    public function publish(Request $request, string $documentId): RedirectResponse
    {
        $request->user()->can('publish', VehicleReportDocument::class) || abort(403, 'Only admin can publish/unpublish vehicle report documents');

        $validated = $request->validate(['published' => 'required|boolean']);

        return $this->withServiceErrorHandling('report', function () use ($request, $documentId, $validated) {
            $this->vehicleReportService->publish($documentId, $validated['published'], $request->user());
        }) ?? back();
    }

    public function delete(Request $request, string $documentId): RedirectResponse
    {
        $request->user()->can('delete', VehicleReportDocument::class) || abort(403, 'Only admin can delete vehicle report documents');

        return $this->withServiceErrorHandling('report', function () use ($documentId) {
            $this->vehicleReportService->delete($documentId);
        }) ?? back();
    }
}
