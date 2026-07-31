<?php

namespace App\Modules\UserProfile\Admin\Http\Controllers;

use App\Models\AssessmentDocument;
use App\Models\VehicleReportDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VehicleReportController extends Controller
{
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

        $existing = VehicleReportDocument::where('source_assessment_document_id', $validated['source_assessment_document_id'])->first();
        if ($existing) {
            return response()->json([
                'error' => 'This assessment document is already transferred',
                'document' => $existing,
            ], 409);
        }

        $source = AssessmentDocument::findOrFail($validated['source_assessment_document_id']);

        $bytes = Storage::disk('s3')->get($source->s3_key);
        if ($bytes === null) {
            return response()->json(['error' => 'Source assessment document could not be read from storage'], 500);
        }

        $filename = basename($source->s3_key);
        $destPath = "vehicle-reports/{$validated['auftragsnummer']}/{$filename}";
        Storage::disk('documents')->put($destPath, $bytes);

        $doc = VehicleReportDocument::create([
            'auftragsnummer' => $validated['auftragsnummer'],
            'vehicle_id' => $validated['vehicle_id'],
            'document_type' => $validated['document_type'] ?? null,
            'document_title' => $validated['document_title'] ?? null,
            'path' => $destPath,
            'published' => $validated['published'] ?? false,
            'source_assessment_document_id' => $validated['source_assessment_document_id'],
            'created_by_user_id' => $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Document transferred successfully',
            'document' => $doc,
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

        $file = $request->file('file');
        $originalFilename = $file->getClientOriginalName();
        $path = "vehicle-reports/{$validated['auftragsnummer']}/{$originalFilename}";

        $existing = VehicleReportDocument::where('vehicle_id', $validated['vehicle_id'])
            ->where('auftragsnummer', $validated['auftragsnummer'])
            ->where('path', $path)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'A file with this name already exists for this vehicle and auftragsnummer',
                'file_name' => $originalFilename,
                'existing_document' => $existing,
            ], 409);
        }

        Storage::disk('documents')->put($path, file_get_contents($file));

        $published = in_array($request->input('published'), ['true', '1', 'yes'], true);

        $doc = VehicleReportDocument::create([
            'auftragsnummer' => $validated['auftragsnummer'],
            'vehicle_id' => $validated['vehicle_id'],
            'document_type' => $validated['document_type'] ?? null,
            'document_title' => $validated['document_title'] ?? null,
            'path' => $path,
            'published' => $published,
            'created_by_user_id' => $request->user()->id,
            'updated_by_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully',
            'document' => $doc,
        ]);
    }

    /**
     * PATCH /admin/vehicle/report/publish/{documentId}
     */
    public function publish(Request $request, string $documentId): JsonResponse
    {
        $request->user()->can('publish', VehicleReportDocument::class) || abort(403, 'Only admin can publish/unpublish vehicle report documents');

        $validated = $request->validate(['published' => 'required|boolean']);

        $doc = VehicleReportDocument::find($documentId);
        if (! $doc) {
            return response()->json(['error' => 'Vehicle report document not found'], 404);
        }

        if ($doc->published === $validated['published']) {
            return response()->json([
                'message' => 'Document published status is already same',
                'document' => $doc,
            ]);
        }

        $doc->update([
            'published' => $validated['published'],
            'updated_by_user_id' => $request->user()->id,
        ]);

        $action = $validated['published'] ? 'published' : 'unpublished';

        return response()->json([
            'message' => 'Document published status updated successfully',
            'action' => $action,
            'document' => $doc->fresh(),
        ]);
    }

    /**
     * DELETE /admin/vehicle/report/delete/{documentId}
     */
    public function delete(Request $request, string $documentId): JsonResponse
    {
        $request->user()->can('delete', VehicleReportDocument::class) || abort(403, 'Only admin can delete vehicle report documents');

        $doc = VehicleReportDocument::find($documentId);
        if (! $doc) {
            return response()->json(['error' => 'Vehicle report document not found'], 404);
        }

        $orderStatus = DB::table('leasyback_orders')
            ->where('auftragsnummer', $doc->auftragsnummer)
            ->value('order_status');

        if ($orderStatus === 'delivered') {
            return response()->json([
                'error' => 'Document cannot be deleted because order status is delivered',
                'order_status' => $orderStatus,
                'document_id' => $doc->id,
            ], 409);
        }

        Storage::disk('documents')->delete($doc->path);

        $doc->delete();

        return response()->json([
            'message' => 'Vehicle report document deleted successfully',
            'document_id' => $documentId,
            'auftragsnummer' => $doc->auftragsnummer,
            'vehicle_id' => $doc->vehicle_id,
        ]);
    }
}
