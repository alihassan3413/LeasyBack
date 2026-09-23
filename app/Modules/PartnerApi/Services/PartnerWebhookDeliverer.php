<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use App\Modules\PartnerApi\Jobs\DeliverPartnerWebhook;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookDeliveryAttempt;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use App\Modules\PartnerApi\Models\PartnerWebhookSubscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One HTTP attempt, and everything that follows from how it went.
 *
 * The delivery job is deliberately thin and this is where the behaviour lives,
 * because all of it — signing, the SSRF re-check, the excerpt bound, the
 * backoff table, the auto-disable counter — is testable without a queue.
 *
 * Two invariants worth stating outright:
 *
 * - **The event id never changes.** It is generated once, at emit, and every
 *   attempt of every delivery of that event carries it. That is what makes it
 *   a deduplication key on the partner's side rather than a per-request id.
 * - **The body is rebuilt deterministically, not stored.** The envelope comes
 *   from the frozen event payload plus this client's external-id mappings, and
 *   those mappings are immutable once registered, so attempt five signs exactly
 *   the bytes attempt one signed.
 */
class PartnerWebhookDeliverer
{
    public function __construct(
        private readonly PartnerWebhookSigner $signer,
        private readonly PartnerWebhookUrlGuard $guard,
        private readonly PartnerExternalReferenceRegistry $references,
    ) {}

    /**
     * Attempt this delivery once, then either finish it or schedule the next
     * attempt.
     */
    public function deliver(PartnerWebhookDelivery $delivery): void
    {
        $delivery->loadMissing(['event', 'subscription.client']);

        $event = $delivery->event;
        $subscription = $delivery->subscription;

        if ($event === null || $subscription === null) {
            return;
        }

        // Re-asked here and not only at fan-out: a partner who disables a
        // subscription while a retry is sitting on the queue has said stop, and
        // the queued job is the one thing that could still call them.
        if (! $subscription->is_active) {
            $this->finish($delivery, PartnerWebhookDeliveryStatus::Exhausted, 'Subscription is disabled.');

            return;
        }

        $attemptNumber = $delivery->attempts + 1;
        $body = $this->body($event, $subscription);
        $timestamp = (string) now()->getTimestamp();

        $started = microtime(true);

        try {
            $target = $this->guard->resolve($subscription->url);
        } catch (RuntimeException $e) {
            // Blocked before a packet left the process. Recorded as an attempt
            // so `/deliveries` can say why, and retried on the normal schedule
            // — a hostname that resolves inside the network today may be fixed
            // tomorrow, and refusing to ever look again would strand a partner
            // who corrected their DNS.
            $this->recordAttempt($delivery, $attemptNumber, null, null, $e->getMessage(), true, $started);
            $this->afterFailure($delivery, $subscription, $e->getMessage());

            return;
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'LeasyBack-Webhooks/1.0',
                ...$this->signer->headers($subscription->secret, $event->event_id, $timestamp, $body),
            ])
                ->timeout((int) config('partner_api.webhooks.timeout_seconds', 10))
                ->connectTimeout((int) config('partner_api.webhooks.connect_timeout_seconds', 5))
                // No redirects at all. A 30x is the cheapest way to turn a
                // validated public URL into a request against an internal one,
                // and no webhook receiver has a legitimate reason to issue one.
                ->withOptions([
                    'allow_redirects' => false,
                    // Pin the connection to the addresses the guard just
                    // approved, so a DNS answer that changes between the check
                    // and the connect cannot be reached.
                    'curl' => $this->resolveOverrides($subscription->url, $target),
                ])
                ->withBody($body, 'application/json')
                ->post($subscription->url);

            $excerpt = $this->excerpt($response->body());
            $status = $response->status();

            $this->recordAttempt($delivery, $attemptNumber, $status, $excerpt, null, false, $started);

            if ($status >= 200 && $status < 300) {
                $this->afterSuccess($delivery, $subscription, $status, $excerpt);

                return;
            }

            $this->afterFailure($delivery, $subscription, "Endpoint responded {$status}.", $status, $excerpt);
        } catch (ConnectionException $e) {
            $this->recordAttempt($delivery, $attemptNumber, null, null, $this->excerpt($e->getMessage()), false, $started);
            $this->afterFailure($delivery, $subscription, $this->excerpt($e->getMessage()));
        } catch (Throwable $e) {
            $this->recordAttempt($delivery, $attemptNumber, null, null, $this->excerpt($e->getMessage()), false, $started);
            $this->afterFailure($delivery, $subscription, $this->excerpt($e->getMessage()));
        }
    }

    /**
     * The exact bytes that get signed and sent.
     *
     * `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE` is pinned because the
     * partner verifies against the raw body: any change to how we serialise is
     * a change to every signature, so it is a decision, not a default.
     */
    public function body(PartnerWebhookEventRecord $event, PartnerWebhookSubscription $subscription): string
    {
        $envelope = $event->envelope();

        $externalIds = $this->externalIdsFor($event, $subscription);

        if ($externalIds !== []) {
            $envelope['data']['external_ids'] = $externalIds;
        }

        return (string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * This client's own ids for the records the event is about, when they have
     * assigned any.
     *
     * Resolved per subscription rather than stored on the event because the
     * mapping belongs to the integration client, not to the company: two
     * clients in one company can each have their own id for the same vehicle.
     * Mappings are immutable once registered, so this is still stable across
     * retries.
     *
     * @return array<string, string>
     */
    private function externalIdsFor(PartnerWebhookEventRecord $event, PartnerWebhookSubscription $subscription): array
    {
        $client = $subscription->client;

        if ($client === null) {
            return [];
        }

        $ids = [];

        if ($event->vehicle_id !== null) {
            $ids['vehicle'] = $this->references->externalId(
                $client,
                PartnerExternalReferenceRegistry::TYPE_VEHICLE,
                $event->vehicle_id,
            );
        }

        if ($event->order_id !== null) {
            $ids['order'] = $this->references->externalId(
                $client,
                PartnerExternalReferenceRegistry::TYPE_ORDER,
                $event->order_id,
            );
        }

        return array_filter($ids, fn (?string $id) => $id !== null);
    }

    private function afterSuccess(
        PartnerWebhookDelivery $delivery,
        PartnerWebhookSubscription $subscription,
        int $status,
        ?string $excerpt,
    ): void {
        $delivery->forceFill([
            'status' => PartnerWebhookDeliveryStatus::Succeeded,
            'attempts' => $delivery->attempts + 1,
            'last_attempt_at' => now(),
            'delivered_at' => now(),
            'last_status_code' => $status,
            'last_response_excerpt' => $excerpt,
            'last_error' => null,
            'next_attempt_at' => null,
        ])->save();

        // A success clears the auto-disable counter outright. The counter
        // measures "is this endpoint dead", not "has it ever failed".
        $subscription->forceFill([
            'consecutive_failures' => 0,
            'last_delivery_at' => now(),
            'last_success_at' => now(),
        ])->save();
    }

    private function afterFailure(
        PartnerWebhookDelivery $delivery,
        PartnerWebhookSubscription $subscription,
        string $error,
        ?int $status = null,
        ?string $excerpt = null,
    ): void {
        $attempts = $delivery->attempts + 1;
        $delay = $this->backoffFor($attempts);

        $delivery->forceFill([
            'status' => $delay === null
                ? PartnerWebhookDeliveryStatus::Exhausted
                : PartnerWebhookDeliveryStatus::Failed,
            'attempts' => $attempts,
            'last_attempt_at' => now(),
            'last_status_code' => $status,
            'last_response_excerpt' => $excerpt,
            'last_error' => Str::limit($error, 250),
            'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay),
        ])->save();

        $subscription->forceFill([
            'last_delivery_at' => now(),
            'consecutive_failures' => $subscription->consecutive_failures + 1,
        ])->save();

        $this->autoDisableIfDead($subscription);

        if ($delay !== null) {
            DeliverPartnerWebhook::dispatch($delivery->id)
                ->delay(now()->addSeconds($delay))
                ->onQueue((string) config('partner_api.webhooks.queue', 'webhooks'));
        }
    }

    /**
     * Seconds to wait before attempt `$attempts + 1`, or null when the budget
     * is spent.
     *
     * The table is config, not arithmetic, so the schedule can be quoted to a
     * partner exactly rather than described as "exponential-ish".
     */
    public function backoffFor(int $attemptsMade): ?int
    {
        /** @var list<int> $schedule */
        $schedule = config('partner_api.webhooks.backoff_seconds', []);

        return $schedule[$attemptsMade - 1] ?? null;
    }

    /**
     * A subscription whose endpoint has failed this many deliveries in a row is
     * not coming back on its own. Suspended rather than deleted: the partner's
     * configuration, their secret and their history all survive, and
     * re-enabling is one PATCH once they have fixed it.
     */
    private function autoDisableIfDead(PartnerWebhookSubscription $subscription): void
    {
        $ceiling = (int) config('partner_api.webhooks.auto_disable_after_failures', 20);

        if ($ceiling <= 0 || $subscription->consecutive_failures < $ceiling) {
            return;
        }

        $subscription->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => "Automatically suspended after {$subscription->consecutive_failures} "
                .'consecutive failed deliveries.',
        ])->save();
    }

    private function finish(PartnerWebhookDelivery $delivery, PartnerWebhookDeliveryStatus $status, string $error): void
    {
        $delivery->forceFill([
            'status' => $status,
            'last_error' => Str::limit($error, 250),
            'next_attempt_at' => null,
        ])->save();
    }

    private function recordAttempt(
        PartnerWebhookDelivery $delivery,
        int $attempt,
        ?int $statusCode,
        ?string $excerpt,
        ?string $error,
        bool $blocked,
        float $startedAt,
    ): void {
        PartnerWebhookDeliveryAttempt::create([
            'partner_webhook_delivery_id' => $delivery->id,
            'attempt' => $attempt,
            'status_code' => $statusCode,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'response_excerpt' => $excerpt,
            'error' => $error === null ? null : Str::limit($error, 250),
            'blocked' => $blocked,
            'attempted_at' => now(),
        ]);
    }

    /**
     * Bounded, and stripped of control characters. A partner's error page can be
     * megabytes of HTML and is not ours to store; what is useful is the first
     * few hundred characters, which is where a framework puts its message.
     */
    private function excerpt(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? $body;

        return Str::limit(trim($clean), (int) config('partner_api.webhooks.response_excerpt_bytes', 512));
    }

    /**
     * cURL's `--resolve`, one entry per approved address, so the connection
     * cannot land anywhere the guard did not sanction. A no-op under
     * `Http::fake()`, which is why the guard's own refusal path is the tested
     * one and this is defence in depth.
     *
     * @param  array{host: string, ips: list<string>}  $target
     * @return array<int, mixed>
     */
    private function resolveOverrides(string $url, array $target): array
    {
        $port = parse_url($url, PHP_URL_PORT)
            ?? (str_starts_with(strtolower($url), 'https://') ? 443 : 80);

        if ($target['ips'] === [] || ! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        return [
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $port, implode(',', $target['ips']))],
        ];
    }
}
