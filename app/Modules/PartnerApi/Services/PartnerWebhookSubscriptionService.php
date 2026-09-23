<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Exceptions\PartnerApiException;
use App\Modules\PartnerApi\Jobs\DeliverPartnerWebhook;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use App\Modules\PartnerApi\Models\PartnerWebhookSubscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything a partner can do to their own subscriptions.
 *
 * Scoping works the way it does everywhere else in this API: the query starts
 * from the token's integration client, so a subscription belonging to anyone
 * else is not narrowed out of the result — it was never in it. Not found and
 * not yours are the same 404, for the same reason as elsewhere (a 403 would
 * confirm the id exists).
 *
 * The secret is returned exactly twice in a subscription's life: once from
 * `create()` and once from `rotateSecret()`. It is an `encrypted` cast in the
 * database and is never included in any resource, so there is no third way to
 * read it — a partner who loses it rotates.
 */
class PartnerWebhookSubscriptionService
{
    public function __construct(
        private readonly PartnerContext $context,
        private readonly PartnerWebhookSigner $signer,
        private readonly PartnerWebhookUrlGuard $guard,
    ) {}

    /**
     * @return Builder<PartnerWebhookSubscription>
     */
    public function query(): Builder
    {
        return PartnerWebhookSubscription::query()
            ->where('partner_integration_client_id', $this->context->client()->id);
    }

    public function findOrFail(string $id): PartnerWebhookSubscription
    {
        $subscription = $this->query()->where('id', $id)->first();

        if ($subscription === null) {
            throw PartnerApiException::notFound(
                'webhook_not_found',
                'No webhook subscription with that id exists for this integration client.',
            );
        }

        return $subscription;
    }

    /**
     * @param  list<string>  $eventTypes
     * @return array{subscription: PartnerWebhookSubscription, secret: string}
     */
    public function create(string $url, array $eventTypes, ?string $description = null): array
    {
        $this->guard->assertAcceptable($url);
        $eventTypes = $this->normaliseEventTypes($eventTypes);

        $secret = $this->signer->generateSecret();

        $subscription = new PartnerWebhookSubscription([
            'url' => $url,
            'description' => $description,
            'event_types' => $eventTypes,
            'is_active' => true,
        ]);

        // Set outside the fillable list on purpose: this column is the
        // authorization boundary and comes from the token, never from input.
        $subscription->partner_integration_client_id = $this->context->client()->id;
        $subscription->secret = $secret;
        $subscription->save();

        return ['subscription' => $subscription->fresh(), 'secret' => $secret];
    }

    /**
     * @param  array{url?: string, event_types?: list<string>, description?: string|null, is_active?: bool}  $attributes
     */
    public function update(PartnerWebhookSubscription $subscription, array $attributes): PartnerWebhookSubscription
    {
        if (array_key_exists('url', $attributes)) {
            $this->guard->assertAcceptable($attributes['url']);
            $subscription->url = $attributes['url'];
        }

        if (array_key_exists('event_types', $attributes)) {
            $subscription->event_types = $this->normaliseEventTypes($attributes['event_types']);
        }

        if (array_key_exists('description', $attributes)) {
            $subscription->description = $attributes['description'];
        }

        if (array_key_exists('is_active', $attributes)) {
            $this->applyActiveState($subscription, (bool) $attributes['is_active']);
        }

        $subscription->save();

        return $subscription->fresh();
    }

    /**
     * Issue a new signing secret, keeping the old one valid for the configured
     * grace window.
     *
     * The window is what makes rotation a deploy rather than an outage: during
     * it, requests are signed with the new secret while a partner still
     * verifying against the old one keeps passing, because both are valid
     * material for the published recipe. A grace of 0 cuts over immediately.
     *
     * @return array{subscription: PartnerWebhookSubscription, secret: string}
     */
    public function rotateSecret(PartnerWebhookSubscription $subscription, ?int $graceMinutes = null): array
    {
        $grace = $graceMinutes ?? (int) config('partner_api.webhooks.secret_rotation_grace_minutes', 60);
        $secret = $this->signer->generateSecret();

        $subscription->previous_secret = $grace > 0 ? $subscription->secret : null;
        $subscription->previous_secret_expires_at = $grace > 0 ? now()->addMinutes($grace) : null;
        $subscription->secret = $secret;
        $subscription->secret_rotated_at = now();
        $subscription->save();

        return ['subscription' => $subscription->fresh(), 'secret' => $secret];
    }

    public function delete(PartnerWebhookSubscription $subscription): void
    {
        // Deliveries and attempts cascade. A partner deleting a subscription is
        // deleting their own delivery history, which is theirs to delete —
        // disabling is the reversible option and is one PATCH away.
        $subscription->delete();
    }

    /**
     * Recent deliveries, newest first, optionally narrowed to the failures.
     *
     * @return LengthAwarePaginator<int, PartnerWebhookDelivery>
     */
    public function deliveries(
        PartnerWebhookSubscription $subscription,
        int $perPage,
        int $page,
        bool $failuresOnly = false,
        ?string $eventType = null,
    ): LengthAwarePaginator {
        $query = PartnerWebhookDelivery::query()
            ->with(['event', 'attemptLog'])
            ->where('partner_webhook_subscription_id', $subscription->id);

        if ($failuresOnly) {
            $query->whereIn('status', [
                PartnerWebhookDeliveryStatus::Failed->value,
                PartnerWebhookDeliveryStatus::Exhausted->value,
            ]);
        }

        if ($eventType !== null) {
            $query->whereIn(
                'partner_webhook_event_id',
                PartnerWebhookEventRecord::query()->where('type', $eventType)->select('id'),
            );
        }

        return $query->orderByDesc('created_at')->orderBy('id')->paginate(perPage: $perPage, page: $page);
    }

    public function findDeliveryOrFail(PartnerWebhookSubscription $subscription, string $deliveryId): PartnerWebhookDelivery
    {
        $delivery = PartnerWebhookDelivery::query()
            ->with(['event', 'attemptLog'])
            ->where('partner_webhook_subscription_id', $subscription->id)
            ->where('id', $deliveryId)
            ->first();

        if ($delivery === null) {
            throw PartnerApiException::notFound(
                'webhook_delivery_not_found',
                'No delivery with that id exists for this webhook subscription.',
            );
        }

        return $delivery;
    }

    /**
     * Try a delivery again, by hand.
     *
     * The attempt counter is *not* reset. A replay is another attempt at the
     * same event, and resetting it would hide from the partner how many times
     * their endpoint had already been called with these exact bytes. What the
     * replay does reset is the retry budget's effect: the delivery goes back to
     * pending and gets one more attempt, whatever state it had reached.
     */
    public function replay(PartnerWebhookDelivery $delivery): PartnerWebhookDelivery
    {
        if ($delivery->status === PartnerWebhookDeliveryStatus::Succeeded) {
            throw PartnerApiException::conflict(
                'webhook_delivery_already_succeeded',
                'That delivery already succeeded. Replaying it would send the same event a second time.',
            );
        }

        $delivery->forceFill([
            'status' => PartnerWebhookDeliveryStatus::Pending,
            'next_attempt_at' => now(),
            'replayed_at' => now(),
        ])->save();

        DeliverPartnerWebhook::dispatch($delivery->id)
            ->onQueue((string) config('partner_api.webhooks.queue', 'webhooks'));

        return $delivery->fresh(['event', 'attemptLog']);
    }

    /**
     * Send a signed `webhook.test` event so a partner can verify their endpoint
     * and their signature check before any real event exists.
     *
     * It goes through the same event row, delivery row and job as everything
     * else — a test that took a shortcut would prove the shortcut works.
     */
    public function sendTestEvent(PartnerWebhookSubscription $subscription): PartnerWebhookDelivery
    {
        $record = PartnerWebhookEventRecord::create([
            'event_id' => $this->signer->generateEventId(),
            'type' => PartnerWebhookEvent::TEST_TYPE,
            'api_version' => (string) config('partner_api.webhooks.api_version', 'v1'),
            'b2b_id' => $this->context->companyId(),
            'payload' => [
                'message' => 'This is a test event. No business data is attached.',
                'subscription' => ['id' => $subscription->id],
            ],
            'occurred_at' => now(),
            'dispatched_at' => now(),
        ]);

        $delivery = PartnerWebhookDelivery::create([
            'partner_webhook_event_id' => $record->id,
            'partner_webhook_subscription_id' => $subscription->id,
            'status' => PartnerWebhookDeliveryStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);

        DeliverPartnerWebhook::dispatch($delivery->id)
            ->onQueue((string) config('partner_api.webhooks.queue', 'webhooks'));

        return $delivery->fresh(['event']);
    }

    /**
     * Turning a subscription back on clears the auto-disable state as well as
     * the flag. Leaving the counter where it was would suspend the partner
     * again on their next single failure.
     */
    private function applyActiveState(PartnerWebhookSubscription $subscription, bool $active): void
    {
        if ($active === $subscription->is_active) {
            return;
        }

        $subscription->is_active = $active;
        $subscription->disabled_at = $active ? null : now();
        $subscription->disabled_reason = $active ? null : 'Disabled by the partner.';

        if ($active) {
            $subscription->consecutive_failures = 0;
        }
    }

    /**
     * @param  list<string>  $eventTypes
     * @return list<string>
     */
    private function normaliseEventTypes(array $eventTypes): array
    {
        $known = PartnerWebhookEvent::values();
        $unknown = array_values(array_diff($eventTypes, $known));

        // Refused rather than silently dropped: a partner who subscribed to a
        // misspelled type and got a 201 would wait forever for events that were
        // never going to arrive.
        if ($unknown !== []) {
            throw PartnerApiException::invalidRequest(
                'webhook_event_type_unknown',
                'Unknown event type(s): '.implode(', ', $unknown).'.',
                ['unknown' => $unknown, 'supported' => $known],
            );
        }

        $normalised = array_values(array_unique($eventTypes));

        if ($normalised === []) {
            throw PartnerApiException::invalidRequest(
                'webhook_event_types_required',
                'A subscription must name at least one event type.',
                ['supported' => $known],
            );
        }

        return $normalised;
    }
}
