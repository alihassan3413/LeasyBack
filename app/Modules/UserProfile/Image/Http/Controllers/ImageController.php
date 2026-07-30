<?php

namespace App\Modules\UserProfile\Image\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    /**
     * POST /image/logos/upload
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:png,jpg,jpeg|max:10240',
        ]);

        $file = $request->file('file');
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $ext = $file->getClientOriginalExtension();
        $safeName = Str::slug($originalName);
        $contentType = $file->getMimeType();

        $key = "logos/{$safeName}-" . Str::uuid() . ".{$ext}";

        Storage::disk('s3')->put($key, file_get_contents($file), [
            'ContentType' => $contentType,
        ]);

        $signedUrl = Storage::disk('s3')->temporaryUrl($key, now()->addHours(3));
        $bucket = config('filesystems.disks.s3.bucket');
        $region = config('filesystems.disks.s3.region');
        $objectUrl = "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";

        return response()->json([
            'key' => $key,
            'content_type' => $contentType,
            'object_url' => $objectUrl,
            'signed_url' => $signedUrl,
            'expires_in_seconds' => 3 * 60 * 60,
        ]);
    }

    /**
     * GET /image/logos/{key}/signed-url
     */
    public function signedUrl(Request $request, string $key): JsonResponse
    {
        if (empty(trim($key))) {
            return response()->json(['error' => 'Key cannot be empty'], 400);
        }

        $signedUrl = Storage::disk('s3')->temporaryUrl($key, now()->addHours(3));

        return response()->json([
            'key' => $key,
            'signed_url' => $signedUrl,
            'expires_in_seconds' => 3 * 60 * 60,
        ]);
    }

    /**
     * DELETE /image/logos/{key}
     */
    public function delete(Request $request, string $key): JsonResponse
    {
        if (empty(trim($key))) {
            return response()->json(['error' => 'Key cannot be empty'], 400);
        }

        Storage::disk('s3')->delete($key);

        $bucket = config('filesystems.disks.s3.bucket');
        $region = config('filesystems.disks.s3.region');
        $objectUrl = "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";

        return response()->json([
            'key' => $key,
            'deleted' => true,
            'object_url' => $objectUrl,
        ]);
    }
}
