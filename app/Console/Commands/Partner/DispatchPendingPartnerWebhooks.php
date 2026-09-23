<?php

namespace App\Console\Commands\Partner;

use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use App\Modules\PartnerApi\Jobs\DeliverPartnerWebhook;
use App\Modules\PartnerApi\Jobs\FanOutPartnerWebhookEvent;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use Illuminate\Console\Command;

/**
 * The half of the outbox that makes it an outbox.
 *
 * Two gaps this closes, both of which the queue alone cannot:
 *
 * 1. **Events that committed but never got queued.** The dispatch in
 *    PartnerWebhookEmitter runs after the transaction commits; a process that
 *    dies in that window leaves a committed business change with an
 *    undispatched event row. Fan-out is idempotent — one delivery per (event,
 *    subscription) — so re-dispatching is always safe.
 *
 * 2. **Retries whose delayed job was lost.** A failed delivery schedules its
 *    own next attempt as a delayed job; a flushed or failed queue takes that
 *    schedule with it. Anything whose `next_attempt_at` is in the past and is
 *    not finished gets pushed again.
 *
 * Idempotent by construction, so running it more often than necessary costs
 * queries and nothing else. Scheduled every five minutes.
 */
class DispatchPendingPartnerWebhooks extends Command
{
    protected $signature = 'partner:webhooks:dispatch-pending
                            {--limit=500 : Maximum rows of each kind to sweep in one run}';

    protected $description = 'Queue partner webhook events and retries the queue never picked up';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $queue = (string) config('partner_api.webhooks.queue', 'webhooks');

        $events = PartnerWebhookEventRecord::query()
            ->whereNull('dispatched_at')
            // A grace period, so this never races the emitter's own dispatch
            // for an event that committed a moment ago.
            ->where('occurred_at', '<=', now()->subMinute())
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            FanOutPartnerWebhookEvent::dispatch($event->id)->onQueue($queue);
        }

        $due = PartnerWebhookDelivery::query()
            ->whereIn('status', [
                PartnerWebhookDeliveryStatus::Pending->value,
                PartnerWebhookDeliveryStatus::Failed->value,
            ])
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now()->subMinute())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();

        foreach ($due as $delivery) {
            DeliverPartnerWebhook::dispatch($delivery->id)->onQueue($queue);
        }

        $this->info(sprintf(
            'Re-queued %d undispatched event(s) and %d overdue delivery attempt(s).',
            $events->count(),
            $due->count(),
        ));

        return self::SUCCESS;
    }
}
