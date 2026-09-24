<?php

namespace App\Modules\UserProfile\Vehicle\Services;

use App\Modules\UserProfile\Vehicle\Support\ReportDocumentImage;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Derives a small WebP copy of a damage image so galleries and pickers can
 * show a grid of photos without downloading several megabytes of full-size
 * appraisal JPEGs.
 *
 * Nothing here is allowed to break an upload. A thumbnail is an optimisation:
 * every failure path returns false and leaves the original untouched, and the
 * delivery routes fall back to the original whenever the derived file is
 * missing, so a host without WebP support simply serves what it always did.
 */
class DamageImageThumbnailService
{
    public const WIDTH = 400;

    private const QUALITY = 82;

    /**
     * A decoded image costs roughly width * height * 4 bytes, so a large
     * enough source would exhaust the worker's memory before it ever reached
     * the resize. Skipping is the safe outcome: the original still works.
     */
    private const MAX_SOURCE_PIXELS = 40000000;

    public function isSupported(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp') && function_exists('imagecreatefromstring');
    }

    public function generate(string $path): bool
    {
        $thumbnailPath = ReportDocumentImage::thumbnailPathFor($path);

        if ($thumbnailPath === null || ! $this->isSupported()) {
            return false;
        }

        try {
            $disk = Storage::disk('documents');
            $contents = $disk->exists($path) ? $disk->get($path) : null;

            if ($contents === null || $contents === '') {
                return false;
            }

            $bytes = $this->resize($contents);

            if ($bytes === null) {
                return false;
            }

            return $disk->put($thumbnailPath, $bytes);
        } catch (Throwable $exception) {
            Log::info('Damage image thumbnail not generated', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function delete(string $path): void
    {
        $thumbnailPath = ReportDocumentImage::thumbnailPathFor($path);

        if ($thumbnailPath !== null) {
            Storage::disk('documents')->delete($thumbnailPath);
        }
    }

    private function resize(string $contents): ?string
    {
        $size = @getimagesizefromstring($contents);

        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            return null;
        }

        try {
            // Never upscale: a photo already smaller than the target keeps its
            // dimensions and still gains the WebP size reduction.
            $width = min(self::WIDTH, imagesx($source));
            $height = max((int) round($width * imagesy($source) / imagesx($source)), 1);

            $thumbnail = imagescale($source, $width, $height);

            if (! $thumbnail instanceof GdImage) {
                return null;
            }

            try {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);

                ob_start();
                $encoded = imagewebp($thumbnail, null, self::QUALITY);
                $bytes = (string) ob_get_clean();

                return $encoded && $bytes !== '' ? $bytes : null;
            } finally {
                imagedestroy($thumbnail);
            }
        } finally {
            imagedestroy($source);
        }
    }
}
