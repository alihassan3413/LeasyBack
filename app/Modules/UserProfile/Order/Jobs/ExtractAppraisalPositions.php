<?php

namespace App\Modules\UserProfile\Order\Jobs;

use App\Modules\UserProfile\Order\Services\AppraisalExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExtractAppraisalPositions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $extractionId) {}

    public function uniqueId(): string
    {
        return $this->extractionId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(AppraisalExtractionService $extractions): void
    {
        $extractions->run($this->extractionId);
    }

    public function failed(Throwable $exception): void
    {
        app(AppraisalExtractionService::class)->failAfterRetries($this->extractionId, $exception);
    }
}
