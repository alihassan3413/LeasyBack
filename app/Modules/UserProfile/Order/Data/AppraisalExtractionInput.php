<?php

namespace App\Modules\UserProfile\Order\Data;

final readonly class AppraisalExtractionInput
{
    public function __construct(
        public string $extractionId,
        public string $orderId,
        public string $auftragsnummer,
        public string $vehicleId,
        public string $documentId,
        public string $fileName,
        public string $contents,
        public string $sha256,
        public ?string $vin = null,
    ) {}

    public function byteSize(): int
    {
        return strlen($this->contents);
    }
}
