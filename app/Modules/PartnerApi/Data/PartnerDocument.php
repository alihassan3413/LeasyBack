<?php

namespace App\Modules\PartnerApi\Data;

use Illuminate\Support\Carbon;

/**
 * One document, normalised across the two tables that store them.
 *
 * `$path` is the storage key and is the reason this is a value object rather
 * than an array handed straight to a resource: the path is needed to stream
 * the bytes and must never reach a response body. It lives on a typed
 * property that PartnerDocumentResource does not read, so exposing it would
 * take an explicit edit rather than a forgotten `unset()`.
 */
final class PartnerDocument
{
    public const SOURCE_REPORT = 'report';

    public const SOURCE_VEHICLE = 'vehicle';

    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $type,
        public readonly string $typeLabel,
        public readonly ?string $title,
        public readonly string $filename,
        public readonly string $path,
        public readonly string $contentType,
        public readonly ?int $sizeBytes,
        public readonly string $vehicleId,
        public readonly ?string $orderId,
        public readonly ?string $orderReference,
        public readonly ?Carbon $createdAt,
        public readonly ?Carbon $updatedAt,
    ) {}
}
