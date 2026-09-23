<?php

namespace Tests\Support;

use App\Modules\UserProfile\Order\Contracts\AppraisalDocumentParser;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionProposal;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionResult;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionSource;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use Throwable;

class FakeAppraisalDocumentParser implements AppraisalDocumentParser
{
    public array $calls = [];

    public bool $supported = true;

    public ?AppraisalExtractionProposal $proposal = null;

    public array $warnings = [];

    public ?Throwable $failure = null;

    public function version(): string
    {
        return 'fake-parser-1';
    }

    public function supports(AppraisalExtractionInput $input): bool
    {
        return $this->supported;
    }

    public function parse(AppraisalExtractionInput $input): AppraisalExtractionResult
    {
        $this->calls[] = $input;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->proposal === null) {
            throw AppraisalExtractionException::extractorFailed('The fake parser has no proposal configured.');
        }

        return new AppraisalExtractionResult(AppraisalExtractionSource::Parser, $this->version(), $this->proposal, $this->warnings);
    }
}
