<?php

namespace App\Modules\UserProfile\Admin\Http\Controllers;

use App\Models\VehicleReportDocument;
use App\Modules\UserProfile\Admin\Services\VehicleReportService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VehicleReportController extends Controller
{
    public function __construct(private readonly VehicleReportService $vehicleReportService) {}

    /**
     * POST /admin/vehicle/report/transfer
     *
     * Copies an already-synced TIM assessment document into the
     * customer-visible report/invoice store. The source location is always
     * resolved from the AssessmentDocument DB record — the client supplies
     * only its id, never a storage path/URL directly (that was the
     * previous, fixed bug: this endpoint used to accept an arbitrary
     * `source_s3_url` string from the request body).
     */
    public function transfer(Request $request): JsonResponse
    {
        $request->user()->can('create', VehicleReportDocument::class) || abort(403, 'Only admin can transfer documents');

        $validated = $request->validate([
            'auftragsnummer' => 'required|string',
            'vehicle_id' => 'required|uuid',
            'document_type' => 'nullable|string',
            'document_title' => 'nullable|string',
            'published' => 'nullable|boolean',
            'source_assessment_document_id' => ['required', 'integer', 'exists:assessment_documents,id'],
        ]);

        try {
            $result = $this->vehicleReportService->transfer($validated, $request->user());
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json([
            'message' => 'Document transferred successfully',
            'document' => $result['document'],
        ]);
    }

    /**
     * POST /admin/vehicle/report/upload (multipart)
     */
    public function upload(Request $request): JsonResponse
    {
        $request->user()->can('create', VehicleReportDocument::class) || abort(403, 'Only admin can upload published documents');

        $validated = $request->validate([
            'auftragsnummer' => 'required|string',
            'vehicle_id' => 'required|uuid',
            'document_type' => 'nullable|string',
            'document_title' => 'nullable|string',
            'published' => 'nullable|string',
            'file' => 'required|file|max:51200',
        ]);

        $published = in_array($request->input('published'), ['true', '1', 'yes'], true);

        try {
            $result = $this->vehicleReportService->upload(
                $validated['auftragsnummer'],
                $validated['vehicle_id'],
                $request->file('file'),
                $validated['document_type'] ?? null,
                $validated['document_title'] ?? null,
                $published,
                $request->user(),
            );
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json([
            'message' => 'Document uploaded successfully',
            'document' => $result['document'],
        ]);
    }

    /**
     * PATCH /admin/vehicle/report/publish/{documentId}
     */
    public function publish(Request $request, string $documentId): JsonResponse
    {
        $request->user()->can('publish', VehicleReportDocument::class) || abort(403, 'Only admin can publish/unpublish vehicle report documents');

        $validated = $request->validate(['published' => 'required|boolean']);

        try {
            $result = $this->vehicleReportService->publish($documentId, $validated['published'], $request->user());
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json($result);
    }

    /**
     * DELETE /admin/vehicle/report/delete/{documentId}
     */
    public function delete(Request $request, string $documentId): JsonResponse
    {
        $request->user()->can('delete', VehicleReportDocument::class) || abort(403, 'Only admin can delete vehicle report documents');

        try {
            $result = $this->vehicleReportService->delete($documentId, $request->user());
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json($result);
    }
}
