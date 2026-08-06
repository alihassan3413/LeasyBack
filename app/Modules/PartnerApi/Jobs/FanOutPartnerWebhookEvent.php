<?php

namespace App\Modules\PartnerApi\Jobs;

use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use App\Modules\PartnerApi\Models\PartnerWebhookSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One event → one delivery row per interested subscription → one delivery job
 * each.
 *
 * Separate from the delivery itself so that a slow or dead endpoint delays only
 * its own subscription. If fan-out and delivery were the same job, a company
 * with three subscriptions and one dead one would have all three retrying on
 * the dead one's schedule.
 *
 * Safe to run twice. `unique(event, subscription)` makes the second run's
 * inserts no-ops via `firstOrCreate`, so the crash-recovery sweeper can
 * re-dispatch an event without producing a second delivery — the property that
 * lets the outbox be at-least-once at the queue and still exactly-once per
 * (event, subscription) pair.
 */
class FanOutPartnerWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $eventRecordId) {}

    public function handle(): void
    {
        $record = PartnerWebhookEventRecord::find($this->eventRecordId);

        if ($record === null) {
            return;
        }

        foreach ($this->audienceFor($record) as $subscription) {
            $delivery = PartnerWebhookDelivery::firstOrCreate(
                [
                    'partner_webhook_event_id' => $record->id,
                    'partner_webhook_subscription_id' => $subscription->id,
                ],
                [
                    'status' => PartnerWebhookDeliveryStatus::Pending,
                    'attempts' => 0,
                    'next_attempt_at' => now(),
                ],
            );

            // wasRecentlyCreated guards the re-run case: a delivery this job
            // already created and queued must not be queued a second time, or
            // the partner gets the same event twice within seconds.
            if ($delivery->wasRecentlyCreated) {
                DeliverPartnerWebhook::dispatch($delivery->id)
                    ->onQueue((string) config('partner_api.webhooks.queue', 'webhooks'));
            }
        }

        $record->forceFill(['dispatched_at' => now()])->save();
    }

    /**
     * Active subscriptions belonging to active clients of the event's company,
     * that asked for this type.
     *
     * The company filter is the whole of cross-company isolation on this path:
     * a subscription is reachable only through its client, and a client belongs
     * to exactly one company. There is no code path that hands a delivery to a
     * subscription outside `$record->b2b_id`.
     *
     * @return Collection<int, PartnerWebhookSubscription>
     */
    private function audienceFor(PartnerWebhookEventRecord $record)
    {
        return PartnerWebhookSubscription::query()
            ->where('is_active', true)
            ->whereIn(
                'partner_integration_client_id',
                DB::table('partner_integration_clients')
                    ->where('b2b_id', $record->b2b_id)
                    ->where('is_active', true)
                    ->select('id'),
            )
            ->get()
            ->filter(fn (PartnerWebhookSubscription $subscription) => $subscription->subscribesTo($record->type))
            ->values();
    }
}
