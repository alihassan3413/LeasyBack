<?php

namespace App\Modules\UserProfile\Vehicle\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VehicleDocumentController extends Controller
{
    public function __construct(private VehicleScopeService $scope) {}

    /**
     * PUT /vehicle/{vehicleId}/documents — upload
     */
    public function upload(Request $request, string $vehicleId): JsonResponse
    {
        $user = $request->user();

        // Admin or owner access check
        if ($user->user_type->value !== 'Admin') {
            $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);
            if (!$vehicle) {
                return response()->json(['error' => 'Vehicle not found or access denied'], 404);
            }
        } else {
            $vehicle = Vehicle::find($vehicleId);
            if (!$vehicle) {
                return response()->json(['error' => 'Vehicle not found'], 404);
            }
        }

        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:20480',
            'document_type' => 'required|string|in:Leasingvertrag,vorschaden,gutachten,Sonstiges',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $contentType = $file->getMimeType();
        $fileSize = $file->getSize();

        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $ext = $file->getClientOriginalExtension();
        $s3Key = "vehicle-documents/{$vehicleId}/{$safeName}-" . Str::uuid() . ".{$ext}";

        Storage::disk('s3')->put($s3Key, file_get_contents($file), [
            'ContentType' => $contentType,
        ]);

        $doc = VehicleDocument::create([
            'vehicle_id' => $vehicleId,
            'document_category' => 'Fahrzeug',
            'document_type' => $request->input('document_type'),
            'original_file_name' => $originalName,
            's3_key' => $s3Key,
            'content_type' => $contentType,
            'file_size' => $fileSize,
            'uploaded_by_user_id' => $user->id,
        ]);

        // Generate signed URL
        $signedUrl = Storage::disk('s3')->temporaryUrl($s3Key, now()->addHours(3));

        return response()->json([
            ...$doc->toArray(),
            'signed_url' => $signedUrl,
            'signed_url_expires_in_seconds' => 3 * 60 * 60,
        ], 201);
    }

    /**
     * GET /vehicle/{vehicleId}/documents — list
     */
    public function index(Request $request, string $vehicleId): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type->value !== 'Admin') {
            $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);
            if (!$vehicle) {
                return response()->json(['error' => 'Vehicle not found or access denied'], 404);
            }
        }

        $docs = VehicleDocument::where('vehicle_id', $vehicleId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($doc) {
                $signedUrl = Storage::disk('s3')->temporaryUrl($doc->s3_key, now()->addHours(3));
                return [
                    ...$doc->toArray(),
                    'signed_url' => $signedUrl,
                    'signed_url_expires_in_seconds' => 3 * 60 * 60,
                ];
            });

        return response()->json($docs);
    }

    /**
     * GET /vehicle/{vehicleId}/documents/{documentId} — show
     */
    public function show(Request $request, string $vehicleId, string $documentId): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type->value !== 'Admin') {
            $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);
            if (!$vehicle) {
                return response()->json(['error' => 'Vehicle not found or access denied'], 404);
            }
        }

        $doc = VehicleDocument::where('vehicle_id', $vehicleId)
            ->where('document_id', $documentId)
            ->first();

        if (!$doc) {
            return response()->json(['error' => 'Document not found'], 404);
        }

        $signedUrl = Storage::disk('s3')->temporaryUrl($doc->s3_key, now()->addHours(3));

        return response()->json([
            ...$doc->toArray(),
            'signed_url' => $signedUrl,
            'signed_url_expires_in_seconds' => 3 * 60 * 60,
        ]);
    }

    /**
     * DELETE /vehicle/{vehicleId}/documents/{documentId}
     */
    public function destroy(Request $request, string $vehicleId, string $documentId): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type->value !== 'Admin') {
            $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);
            if (!$vehicle) {
                return response()->json(['error' => 'Vehicle not found or access denied'], 404);
            }
        }

        $doc = VehicleDocument::where('vehicle_id', $vehicleId)
            ->where('document_id', $documentId)
            ->first();

        if (!$doc) {
            return response()->json(['error' => 'Document not found'], 404);
        }

        Storage::disk('s3')->delete($doc->s3_key);
        $doc->delete();

        return response()->json(['status' => 'deleted', 'document_id' => $documentId]);
    }
}
