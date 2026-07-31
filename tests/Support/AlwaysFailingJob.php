<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Minimal job that always throws, used only to prove the queue's
 * failed-job pipeline (Checkpoint 5) actually works end-to-end against
 * a real queue connection — not specific to any real mailable.
 */
class AlwaysFailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        throw new \RuntimeException('Deliberate failure for failed-job visibility test.');
    }
}
