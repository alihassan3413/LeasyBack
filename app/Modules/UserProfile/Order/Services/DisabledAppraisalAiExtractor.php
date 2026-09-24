<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Modules\UserProfile\Order\Contracts\AppraisalAiExtractor;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionResult;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;

final class DisabledAppraisalAiExtractor implements AppraisalAiExtractor
{
    public function version(): string
    {
        return 'disabled';
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function extract(AppraisalExtractionInput $input): AppraisalExtractionResult
    {
        throw AppraisalExtractionException::extractorFailed('AI extraction is not enabled.');
    }
}
