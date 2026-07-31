<?php

namespace Tests\Feature;

use App\Mail\RegistrationWelcome;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AlwaysFailingJob;
use Tests\TestCase;

/**
 * Checkpoint 5: proves the queue's failed-job pipeline actually works
 * against a real queue connection, not just that failed_jobs exists as a
 * table. phpunit.xml normally forces QUEUE_CONNECTION=sync (jobs run
 * inline, no queue table involved at all) — this test explicitly switches
 * to the 'database' connection (the real default per .env.example) so the
 * queue:work -> failure -> failed_jobs path is genuinely exercised.
 */
class QueueFailedJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_that_exhausts_its_retries_becomes_visible_in_failed_jobs(): void
    {
        config(['queue.default' => 'database']);

        AlwaysFailingJob::dispatch();

        $this->artisan('queue:work', [
            '--once' => true,
            '--tries' => 1,
        ]);

        $this->assertSame(1, DB::table('failed_jobs')->count());

        $failure = DB::table('failed_jobs')->first();
        $this->assertStringContainsString('Deliberate failure for failed-job visibility test.', $failure->exception);
    }

    public function test_registration_welcome_mailable_has_retry_configuration(): void
    {
        // Documents the retry contract rather than exercising it end-to-end
        // (forcing a real SMTP failure deterministically isn't practical here).
        $mail = new RegistrationWelcome(User::factory()->make());

        $this->assertSame(3, $mail->tries);
        $this->assertInstanceOf(ShouldQueue::class, $mail);
    }
}
