<?php

namespace App\Modules\UserProfile\Order\Contracts;

use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionResult;

interface AppraisalAiExtractor
{
    public function version(): string;

    public function isEnabled(): bool;

    public function extract(AppraisalExtractionInput $input): AppraisalExtractionResult;
}
