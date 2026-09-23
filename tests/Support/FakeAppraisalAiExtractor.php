<?php

namespace Tests\Support;

use App\Modules\UserProfile\Order\Contracts\AppraisalAiExtractor;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionProposal;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionResult;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionSource;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use Throwable;

class FakeAppraisalAiExtractor implements AppraisalAiExtractor
{
    public array $calls = [];

    public bool $enabled = true;

    public ?AppraisalExtractionProposal $proposal = null;

    public ?Throwable $failure = null;

    public function version(): string
    {
        return 'fake-ai-1';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function extract(AppraisalExtractionInput $input): AppraisalExtractionResult
    {
        $this->calls[] = $input;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->proposal === null) {
            throw AppraisalExtractionException::extractorFailed('The fake AI extractor has no proposal configured.');
        }

        return new AppraisalExtractionResult(AppraisalExtractionSource::Ai, $this->version(), $this->proposal);
    }
}
