<?php

namespace App\Modules\UserProfile\Vehicle\Support;

final class ReportDocumentImage
{
    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public static function contentTypeFor(string $path): ?string
    {
        return self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    public static function isImage(string $path): bool
    {
        return self::contentTypeFor($path) !== null;
    }
}
