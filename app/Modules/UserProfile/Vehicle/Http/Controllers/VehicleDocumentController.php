<?php

namespace App\Modules\UserProfile\Vehicle\Http\Controllers;

use App\Models\VehicleDocument;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class VehicleDocumentController extends Controller
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    /**
     * PUT /vehicle/{vehicleId}/documents — upload
     */
    public function upload(Request $request, string $vehicleId): JsonResponse
    {
        $request->user()->can('create', [VehicleDocument::class, $vehicleId]) || abort(404);

        // 10 MB cap + pdf/jpg/jpeg/png allow-list, matching the reference
        // system's documented limits (docs/B2C_ADMIN_MIGRATION_AUDIT.md).
        // Laravel's `mimes` rule sniffs the file's actual content, not just
        // its extension/declared content-type, so this is stricter than the
        // reference in that respect.
        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            // gutachten/Sonstiges deliberately excluded — those are
            // Admin-managed report/invoice types uploaded through the
            // separate VehicleReportService flow, not something a customer
            // may self-declare here (docs/B2C_ADMIN_PERMISSION_MATRIX.md's
            // Vehicle Document row; the frontend's own UploadDocumentModal
            // already only ever offers these two, this makes the backend
            // actually enforce it instead of trusting the UI).
            'document_type' => 'required|string|in:Leasingvertrag,vorschaden',
        ]);

        $doc = $this->vehicleService->uploadDocument($vehicleId, $validated['file'], $validated['document_type'], $request->user());

        return response()->json([
            ...$this->present($doc),
        ], 201);
    }

    /**
     * GET /vehicle/{vehicleId}/documents — list
     */
    public function index(Request $request, string $vehicleId): JsonResponse
    {
        $request->user()->can('viewAny', [VehicleDocument::class, $vehicleId]) || abort(404);

        $docs = VehicleDocument::where('vehicle_id', $vehicleId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (VehicleDocument $doc) => $this->present($doc));

        return response()->json($docs);
    }

    /**
     * GET /vehicle/{vehicleId}/documents/{documentId} — show
     */
    public function show(Request $request, string $vehicleId, string $documentId): JsonResponse
    {
        $doc = VehicleDocument::where('vehicle_id', $vehicleId)
            ->where('document_id', $documentId)
            ->first();

        if (! $doc) {
            return response()->json(['error' => 'Document not found'], 404);
        }

        $request->user()->can('view', $doc) || abort(404);

        return response()->json($this->present($doc));
    }

    /**
     * DELETE /vehicle/{vehicleId}/documents/{documentId}
     */
    public function destroy(Request $request, string $vehicleId, string $documentId): JsonResponse
    {
        $doc = VehicleDocument::where('vehicle_id', $vehicleId)
            ->where('document_id', $documentId)
            ->first();

        if (! $doc) {
            return response()->json(['error' => 'Document not found'], 404);
        }

        $request->user()->can('delete', $doc) || abort(404);

        $this->vehicleService->deleteDocument($doc);

        return response()->json(['status' => 'deleted', 'document_id' => $documentId]);
    }

    /**
     * Every read/download always re-derives the temporary URL from the
     * document's own (authorized, DB-loaded) `path` — never from a
     * client-supplied key. Swapping the `documents` disk's driver to S3
     * later needs no change here: temporaryUrl() works the same way on
     * both drivers.
     *
     * @return array<string, mixed>
     */
    private function present(VehicleDocument $doc): array
    {
        return [
            ...$doc->toArray(),
            'signed_url' => Storage::disk('documents')->temporaryUrl($doc->path, now()->addHours(3)),
            'signed_url_expires_in_seconds' => 3 * 60 * 60,
        ];
    }
}
