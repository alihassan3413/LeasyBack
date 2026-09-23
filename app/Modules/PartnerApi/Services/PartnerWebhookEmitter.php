<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Jobs\FanOutPartnerWebhookEvent;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use App\Modules\PartnerApi\Models\PartnerWebhookSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The outbox writer — the one place a business change becomes a webhook event.
 *
 * Three properties this class exists to guarantee, in the order they matter:
 *
 * 1. **A rolled-back change is never announced.** The event row is written with
 *    the caller's own connection, so it is inside whatever transaction the
 *    caller is in. If that transaction rolls back, the event goes with it. This
 *    is why the row is written here and not from a queued job reading the
 *    change back afterwards.
 *
 * 2. **A committed change is never lost.** The fan-out job is dispatched
 *    `afterCommit()`, so it can never run against a row its transaction has not
 *    committed yet. If the process dies between the commit and the dispatch,
 *    the row survives with `dispatched_at` still null and
 *    `partner:webhooks:dispatch-pending` picks it up. The queue is the fast
 *    path, not the only path.
 *
 * 3. **A webhook problem never becomes a business problem.** Everything here is
 *    wrapped: a failure to record or dispatch an event is logged at error level
 *    and swallowed. A partner's integration must not be able to fail a status
 *    transition, and neither must a bug in this module.
 *
 * The short-circuit at the top is not an optimisation for its own sake: most
 * companies have no integration at all, and writing an outbox row for every
 * status change in the system to serve nobody would make this table the largest
 * in the database within a month. The race it accepts — a subscription created
 * microseconds after an event — costs that subscription one event it was never
 * going to be told about anyway, and is documented rather than hidden.
 */
class PartnerWebhookEmitter
{
    public function __construct(private readonly PartnerWebhookSigner $signer) {}

    /**
     * Record one event for one company.
     *
     * @param  array<string, mixed>  $data  the envelope's `data` object, already
     *                                      shaped for partners — see PartnerWebhookEvents
     * @return string|null the event id, or null when nothing was recorded
     */
    public function emit(
        PartnerWebhookEvent $type,
        ?string $companyId,
        array $data,
        ?string $orderId = null,
        ?string $vehicleId = null,
    ): ?string {
        if ($companyId === null) {
            return null;
        }

        try {
            if (! $this->hasAudience($companyId, $type)) {
                return null;
            }

            $record = PartnerWebhookEventRecord::create([
                'event_id' => $this->signer->generateEventId(),
                'type' => $type->value,
                'api_version' => (string) config('partner_api.webhooks.api_version', 'v1'),
                'b2b_id' => $companyId,
                'order_id' => $orderId,
                'vehicle_id' => $vehicleId,
                'payload' => $data,
                'occurred_at' => now(),
            ]);

            // afterCommit() rather than dispatch(): inside a transaction this
            // waits for the commit, and outside one it dispatches immediately.
            // Either way the job never sees a row that does not exist.
            FanOutPartnerWebhookEvent::dispatch($record->id)
                ->afterCommit()
                ->onQueue($this->queue());

            return $record->event_id;
        } catch (Throwable $e) {
            // Deliberately swallowed. The alternative — letting this bubble —
            // means a webhook bug rolls back a real status change, which is a
            // far worse failure than a missed notification.
            Log::error('Failed to record a partner webhook event.', [
                'type' => $type->value,
                'b2b_id' => $companyId,
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Is there anyone in this company who asked for this type?
     *
     * `whereJsonContains` is the one place this module leans on JSON support;
     * SQLite, MySQL and Postgres all implement it, and the fallback below keeps
     * a driver that does not from silently dropping every event.
     */
    private function hasAudience(string $companyId, PartnerWebhookEvent $type): bool
    {
        $query = PartnerWebhookSubscription::query()
            ->where('is_active', true)
            ->whereIn(
                'partner_integration_client_id',
                DB::table('partner_integration_clients')
                    ->where('b2b_id', $companyId)
                    ->where('is_active', true)
                    ->select('id'),
            );

        try {
            return (clone $query)->whereJsonContains('event_types', $type->value)->exists();
        } catch (Throwable) {
            return $query->get()
                ->contains(fn (PartnerWebhookSubscription $subscription) => $subscription->subscribesTo($type));
        }
    }

    private function queue(): string
    {
        return (string) config('partner_api.webhooks.queue', 'webhooks');
    }
}
