<?php

namespace App\Modules\UserProfile\Admin\Http\Controllers;

use App\Models\VehicleReportDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VehicleReportController extends Controller
{
    /**
     * POST /admin/vehicle/report/transfer
     */
    public function transfer(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can transfer documents'], 403);
        }

        $validated = $request->validate([
            'auftragsnummer' => 'required|string',
            'vehicle_id' => 'required|uuid',
            'document_type' => 'nullable|string',
            'document_title' => 'nullable|string',
            'source_s3_url' => 'required|string',
            'published' => 'nullable|boolean',
            'source_assessment_document_id' => 'nullable|integer',
        ]);

        // Check if already transferred
        if (!empty($validated['source_assessment_document_id'])) {
            $existing = VehicleReportDocument::where('source_assessment_document_id', $validated['source_assessment_document_id'])->first();
            if ($existing) {
                return response()->json([
                    'error' => 'This assessment document is already transferred',
                    'document' => $existing,
                ], 409);
            }
        }

        // Parse s3:// URI
        $parsed = $this->parseS3Uri($validated['source_s3_url']);
        if (!$parsed) {
            return response()->json(['error' => 'Invalid S3 URL. Must start with s3://'], 400);
        }

        $destBucket = config('filesystems.disks.s3.bucket');
        $filename = basename($parsed['key']);
        $destKey = "vehicle-reports/{$validated['auftragsnummer']}/{$filename}";

        // Copy S3 object
        try {
            Storage::disk('s3')->copy("{$parsed['bucket']}/{$parsed['key']}", $destKey);
        } catch (\Throwable $e) {
            return response()->json(['error' => "Failed to copy S3 object: {$e->getMessage()}"], 500);
        }

        $destS3Url = "s3://{$destBucket}/{$destKey}";

        $doc = VehicleReportDocument::create([
            'auftragsnummer' => $validated['auftragsnummer'],
            'vehicle_id' => $validated['vehicle_id'],
            'document_type' => $validated['document_type'] ?? null,
            'document_title' => $validated['document_title'] ?? null,
            's3_bucket' => $destBucket,
            's3_key' => $destKey,
            's3_url' => $destS3Url,
            'published' => $validated['published'] ?? false,
            'source_assessment_document_id' => $validated['source_assessment_document_id'] ?? null,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
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
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can upload published documents'], 403);
        }

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
        $bucket = config('filesystems.disks.s3.bucket');
        $s3Key = "vehicle-reports/{$validated['auftragsnummer']}/{$originalFilename}";

        // Check if file already exists for same vehicle+auftragsnummer+key
        $existing = VehicleReportDocument::where('vehicle_id', $validated['vehicle_id'])
            ->where('auftragsnummer', $validated['auftragsnummer'])
            ->where('s3_key', $s3Key)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'A file with this name already exists for this vehicle and auftragsnummer',
                'file_name' => $originalFilename,
                'existing_document' => $existing,
            ], 409);
        }

        Storage::disk('s3')->put($s3Key, file_get_contents($file), [
            'ContentType' => $file->getMimeType(),
        ]);

        $published = in_array($request->input('published'), ['true', '1', 'yes'], true);

        $doc = VehicleReportDocument::create([
            'auftragsnummer' => $validated['auftragsnummer'],
            'vehicle_id' => $validated['vehicle_id'],
            'document_type' => $validated['document_type'] ?? null,
            'document_title' => $validated['document_title'] ?? null,
            's3_bucket' => $bucket,
            's3_key' => $s3Key,
            's3_url' => "s3://{$bucket}/{$s3Key}",
            'published' => $published,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
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
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can publish/unpublish vehicle report documents'], 403);
        }

        $validated = $request->validate(['published' => 'required|boolean']);

        $doc = VehicleReportDocument::find($documentId);
        if (!$doc) {
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
            'updated_by_user_id' => $user->id,
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
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can delete vehicle report documents'], 403);
        }

        $doc = VehicleReportDocument::find($documentId);
        if (!$doc) {
            return response()->json(['error' => 'Vehicle report document not found'], 404);
        }

        // Check order status — block if delivered
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

        // Delete from S3
        Storage::disk('s3')->delete($doc->s3_key);

        $doc->delete();

        return response()->json([
            'message' => 'Vehicle report document deleted successfully',
            'document_id' => $documentId,
            'auftragsnummer' => $doc->auftragsnummer,
            'vehicle_id' => $doc->vehicle_id,
            'deleted_s3_url' => $doc->s3_url,
        ]);
    }

    private function parseS3Uri(string $uri): ?array
    {
        if (!str_starts_with($uri, 's3://')) return null;
        $path = substr($uri, 5);
        $slashPos = strpos($path, '/');
        if ($slashPos === false) return null;

        return [
            'bucket' => substr($path, 0, $slashPos),
            'key' => substr($path, $slashPos + 1),
        ];
    }
}
