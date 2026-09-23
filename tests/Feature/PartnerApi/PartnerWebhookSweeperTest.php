<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Jobs\DeliverPartnerWebhook;
use App\Modules\PartnerApi\Jobs\FanOutPartnerWebhookEvent;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerWebhooks;
use Tests\TestCase;

/**
 * The half of the outbox that makes a committed event impossible to lose.
 *
 * The emitter dispatches fan-out after the transaction commits, which leaves a
 * window: commit succeeds, process dies, nothing is queued. The row survives
 * with `dispatched_at` null, and this command is what notices.
 */
class PartnerWebhookSweeperTest extends TestCase
{
    use BuildsPartnerClients;
    use BuildsPartnerWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->allowLocalWebhookTargets();
    }

    public function test_an_event_that_was_never_dispatched_is_re_queued(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $this->orphanEvent($client->b2b_id);

        $this->artisan('partner:webhooks:dispatch-pending')->assertSuccessful();

        Queue::assertPushed(FanOutPartnerWebhookEvent::class, 1);
    }

    /**
     * The grace period. An event that committed seconds ago still has its own
     * dispatch in flight, and re-queuing it would race that.
     */
    public function test_a_freshly_committed_event_is_left_alone(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $this->orphanEvent($client->b2b_id, now());

        $this->artisan('partner:webhooks:dispatch-pending')->assertSuccessful();

        Queue::assertNotPushed(FanOutPartnerWebhookEvent::class);
    }

    public function test_an_overdue_retry_whose_job_was_lost_is_re_queued(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client);

        $event = $this->orphanEvent($client->b2b_id);

        PartnerWebhookDelivery::create([
            'partner_webhook_event_id' => $event->id,
            'partner_webhook_subscription_id' => $subscription->id,
            'status' => PartnerWebhookDeliveryStatus::Failed,
            'attempts' => 2,
            'next_attempt_at' => now()->subHour(),
        ]);

        $this->artisan('partner:webhooks:dispatch-pending')->assertSuccessful();

        Queue::assertPushed(DeliverPartnerWebhook::class, 1);
    }

    public function test_a_succeeded_delivery_is_never_re_queued(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client);

        $event = $this->orphanEvent($client->b2b_id);
        $event->forceFill(['dispatched_at' => now()])->save();

        PartnerWebhookDelivery::create([
            'partner_webhook_event_id' => $event->id,
            'partner_webhook_subscription_id' => $subscription->id,
            'status' => PartnerWebhookDeliveryStatus::Succeeded,
            'attempts' => 1,
            'next_attempt_at' => now()->subHour(),
        ]);

        $this->artisan('partner:webhooks:dispatch-pending')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    private function orphanEvent(string $companyId, ?Carbon $occurredAt = null): PartnerWebhookEventRecord
    {
        return PartnerWebhookEventRecord::create([
            'event_id' => 'evt_'.bin2hex(random_bytes(16)),
            'type' => PartnerWebhookEvent::OrderStatusChanged->value,
            'api_version' => 'v1',
            'b2b_id' => $companyId,
            'payload' => ['order' => ['id' => 'x']],
            'occurred_at' => $occurredAt ?? now()->subMinutes(10),
        ]);
    }
}
