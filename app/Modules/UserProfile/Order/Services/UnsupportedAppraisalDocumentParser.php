<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Modules\UserProfile\Order\Contracts\AppraisalDocumentParser;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionResult;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;

final class UnsupportedAppraisalDocumentParser implements AppraisalDocumentParser
{
    public function version(): string
    {
        return 'unsupported';
    }

    public function supports(AppraisalExtractionInput $input): bool
    {
        return false;
    }

    public function parse(AppraisalExtractionInput $input): AppraisalExtractionResult
    {
        throw AppraisalExtractionException::unsupportedDocument('No rule-based Gutachten parser is configured.');
    }
}
