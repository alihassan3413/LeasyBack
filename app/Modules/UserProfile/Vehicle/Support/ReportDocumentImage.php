<?php

namespace App\Modules\UserProfile\Vehicle\Support;

final class ReportDocumentImage
{
    public const THUMBNAIL_DIRECTORY = 'thumbnails';

    public const THUMBNAIL_CONTENT_TYPE = 'image/webp';

    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public static function contentTypeFor(string $path): ?string
    {
        return self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    public static function isImage(string $path): bool
    {
        return self::contentTypeFor($path) !== null;
    }

    /**
     * Thumbnails are derived files, not documents: they carry no
     * vehicle_report_documents row, so the only thing tying one to its
     * original is this path. Keeping the convention here means the generator,
     * the delivery routes and the cleanup on delete all read it from one
     * place instead of each rebuilding it.
     */
    public static function thumbnailPathFor(string $path): ?string
    {
        if (! self::isImage($path)) {
            return null;
        }

        $directory = trim(dirname($path), '.');
        $name = pathinfo($path, PATHINFO_FILENAME);

        if ($name === '') {
            return null;
        }

        $prefix = $directory === '' || $directory === DIRECTORY_SEPARATOR ? '' : $directory.'/';

        return $prefix.self::THUMBNAIL_DIRECTORY.'/'.$name.'.webp';
    }
}
