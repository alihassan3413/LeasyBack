<?php

namespace App\Modules\UserProfile\Order\Contracts;

use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;

interface PdfTextExtractor
{
    public function version(): string;

    public function isAvailable(): bool;

    public function extract(AppraisalExtractionInput $input): array;
}
