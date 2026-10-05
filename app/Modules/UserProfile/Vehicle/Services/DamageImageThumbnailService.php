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
     * Print width in pixels. The document lays a damage photo out around 45mm
     * wide, so 320px keeps it sharp on paper without carrying a full-size
     * appraisal photo into every page of the PDF.
     */
    private const PDF_WIDTH = 320;

    /**
     * Print width for the full-size photo section at the end of the PDF, laid
     * out up to about 166mm wide: 1100px keeps that near 170 dpi. Read from the
     * original upload, because the stored thumbnail is only WIDTH pixels wide.
     */
    public const PDF_DETAIL_WIDTH = 1100;

    private const PDF_QUALITY = 78;

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

    /**
     * A damage image re-encoded as a JPEG `data:` URI, sized for print.
     *
     * The PDF renderer needs this rather than the stored thumbnail for two
     * reasons: dompdf cannot decode WebP, which is what thumbnails are, and it
     * runs with `enable_remote` off, so an image has to arrive inside the
     * document rather than as a URL it would fetch.
     *
     * The stored thumbnail is still preferred as the *source* — decoding 400px
     * of WebP costs a fraction of a multi-megabyte appraisal JPEG, and the
     * original is only read when no thumbnail was ever derived. Nothing here
     * writes to disk; this is a second encoding of an existing image, not a
     * second thumbnail.
     *
     * Returns null on every failure path, like the rest of this class: a photo
     * that cannot be encoded is left out of the PDF rather than breaking it.
     *
     * The pixel dimensions come back with the bytes because dompdf needs both
     * sides of the box: given only a width it mis-measures the row and the
     * image rides up over the text above it.
     *
     * @return array{src: string, width: int, height: int}|null
     */
    public function pdfJpegDataUri(string $path, int $maxWidth = self::PDF_WIDTH): ?array
    {
        if (! $this->isSupported()) {
            return null;
        }

        try {
            $disk = Storage::disk('documents');
            $thumbnailPath = ReportDocumentImage::thumbnailPathFor($path);
            $hasThumbnail = $thumbnailPath !== null && $disk->exists($thumbnailPath);

            // The thumbnail is enough for anything up to its own width; a
            // larger print needs the original, with the thumbnail as fallback.
            $source = $maxWidth <= self::WIDTH
                ? ($hasThumbnail ? $thumbnailPath : $path)
                : ($disk->exists($path) || ! $hasThumbnail ? $path : $thumbnailPath);

            if (! $disk->exists($source)) {
                return null;
            }

            $jpeg = $this->toJpeg((string) $disk->get($source), $maxWidth);

            if ($jpeg === null) {
                return null;
            }

            $size = @getimagesizefromstring($jpeg);

            if ($size === false) {
                return null;
            }

            return [
                'src' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
                'width' => (int) $size[0],
                'height' => (int) $size[1],
            ];
        } catch (Throwable $exception) {
            Log::info('Damage image not embedded in PDF', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function delete(string $path): void
    {
        $thumbnailPath = ReportDocumentImage::thumbnailPathFor($path);

        if ($thumbnailPath !== null) {
            Storage::disk('documents')->delete($thumbnailPath);
        }
    }

    /**
     * Flattened onto white, because JPEG has no alpha and a transparent PNG
     * would otherwise render with a black background in the PDF.
     */
    private function toJpeg(string $contents, int $maxWidth): ?string
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
            // Never upscale — a 400px thumbnail stays 400px rather than being
            // blown up to the print width.
            $width = min($maxWidth, imagesx($source));
            $height = max((int) round($width * imagesy($source) / imagesx($source)), 1);

            $canvas = imagecreatetruecolor($width, $height);

            if (! $canvas instanceof GdImage) {
                return null;
            }

            try {
                $white = imagecolorallocate($canvas, 255, 255, 255);

                if ($white !== false) {
                    imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
                }

                imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

                ob_start();
                $encoded = imagejpeg($canvas, null, self::PDF_QUALITY);
                $bytes = (string) ob_get_clean();

                return $encoded && $bytes !== '' ? $bytes : null;
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($source);
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
