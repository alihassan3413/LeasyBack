<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Why PHP refused an uploaded file, in words an admin can act on.
 *
 * Laravel's `uploaded` rule reports every PHP upload error as "The file failed
 * to upload." — the same text whether the file was bigger than the server
 * allows, arrived incomplete, or could not be written. The usual cause, a file
 * above `upload_max_filesize`, looked like a broken upload; this names it, and
 * the limit this server actually enforces.
 */
final class UploadFailure
{
    public static function message(mixed $file): string
    {
        $error = $file instanceof UploadedFile ? $file->getError() : UPLOAD_ERR_NO_FILE;

        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'Die Datei ist größer, als der Server annimmt (höchstens %s). Bitte laden Sie eine kleinere Datei hoch oder lassen Sie das Upload-Limit des Servers erhöhen.',
                self::serverLimit(),
            ),
            UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur teilweise übertragen. Bitte versuchen Sie es erneut.',
            UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei übertragen.',
            default => 'Der Server konnte die Datei nicht speichern (Upload-Verzeichnis). Bitte versuchen Sie es später erneut.',
        };
    }

    /** The smaller of PHP's per-file and per-request limits, e.g. "2 MB". */
    public static function serverLimit(): string
    {
        $bytes = min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX);

        return $bytes >= 1024 ** 2 ? round($bytes / 1024 ** 2).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
