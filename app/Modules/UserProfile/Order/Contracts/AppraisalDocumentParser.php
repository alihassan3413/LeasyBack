<?php

namespace App\Modules\UserProfile\Order\Contracts;

use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionResult;

interface AppraisalDocumentParser
{
    public function version(): string;

    public function supports(AppraisalExtractionInput $input): bool;

    public function parse(AppraisalExtractionInput $input): AppraisalExtractionResult;
}
