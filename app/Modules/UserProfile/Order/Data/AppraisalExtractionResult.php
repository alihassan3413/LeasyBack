<?php

namespace App\Modules\UserProfile\Order\Data;

use App\Modules\UserProfile\Order\Enums\AppraisalExtractionSource;

final readonly class AppraisalExtractionResult
{
    public function __construct(
        public AppraisalExtractionSource $source,
        public string $extractorVersion,
        public AppraisalExtractionProposal $proposal,
        public array $warnings = [],
    ) {}
}
