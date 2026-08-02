<?php

namespace App\Http\Controllers;

use App\Models\VehicleDocument;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VehicleDocumentController extends Controller
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    /**
     * Session-authenticated counterpart of the Sanctum API's
     * VehicleDocumentController::upload()/destroy() — same Policy checks,
     * same VehicleService methods, redirects back to the dashboard instead
     * of returning JSON.
     */
    public function store(Request $request, string $vehicleId): RedirectResponse
    {
        $request->user()->can('create', [VehicleDocument::class, $vehicleId]) || abort(404);

        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            // See the Sanctum API's VehicleDocumentController::upload() for
            // why gutachten/Sonstiges are excluded here.
            'document_type' => 'required|string|in:Leasingvertrag,vorschaden',
        ]);

        $this->vehicleService->uploadDocument($vehicleId, $validated['file'], $validated['document_type'], $request->user());

        return to_route('dashboard')->with('success', 'Dokument wurde hochgeladen.');
    }

    public function destroy(Request $request, string $vehicleId, string $documentId): RedirectResponse
    {
        $doc = VehicleDocument::where('vehicle_id', $vehicleId)
            ->where('document_id', $documentId)
            ->first();

        if (! $doc || ! $request->user()->can('delete', $doc)) {
            abort(404);
        }

        $this->vehicleService->deleteDocument($doc);

        return to_route('dashboard')->with('success', 'Dokument wurde gelöscht.');
    }
}
