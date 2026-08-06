<?php

namespace App\Modules\PartnerApi\Http\Resources;

use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookDeliveryAttempt;

/**
 * One delivery and its attempts, as the partner who owns the subscription sees
 * it.
 *
 * This is the "did you get it" answer, so it carries what an integrator needs
 * to debug their own endpoint: the event id they should have deduplicated on,
 * every status code we saw, and a bounded excerpt of what their server said.
 *
 * `blocked` on an attempt means the call never left our process — the target
 * failed the SSRF check at delivery time. Surfaced deliberately: a partner
 * whose DNS started answering with a private address will otherwise see a
 * silent stall and no status code to explain it.
 *
 * The event payload is included, once, at the top. A partner debugging a
 * failure needs to see what we tried to send them, and it is their own
 * company's data by construction.
 */
final class PartnerWebhookDeliveryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PartnerWebhookDelivery $delivery, bool $withPayload = false): array
    {
        $event = $delivery->event;

        $payload = [
            'id' => $delivery->id,
            'event' => $event === null ? null : [
                'id' => $event->event_id,
                'type' => $event->type,
                'api_version' => $event->api_version,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ],
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'last_status_code' => $delivery->last_status_code,
            'last_error' => $delivery->last_error,
            'last_response_excerpt' => $delivery->last_response_excerpt,
            'last_attempt_at' => $delivery->last_attempt_at?->toIso8601String(),
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'replayed_at' => $delivery->replayed_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
            'attempt_log' => $delivery->relationLoaded('attemptLog')
                ? $delivery->attemptLog->map(fn (PartnerWebhookDeliveryAttempt $attempt) => self::attempt($attempt))->all()
                : [],
        ];

        if ($withPayload && $event !== null) {
            $payload['event']['data'] = $event->payload ?? [];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function attempt(PartnerWebhookDeliveryAttempt $attempt): array
    {
        return [
            'attempt' => $attempt->attempt,
            'status_code' => $attempt->status_code,
            'duration_ms' => $attempt->duration_ms,
            'error' => $attempt->error,
            'response_excerpt' => $attempt->response_excerpt,
            'blocked' => $attempt->blocked,
            'attempted_at' => $attempt->attempted_at?->toIso8601String(),
        ];
    }
}
