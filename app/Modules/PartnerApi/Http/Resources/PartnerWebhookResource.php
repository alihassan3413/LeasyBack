<?php

namespace App\Modules\PartnerApi\Http\Resources;

use App\Modules\PartnerApi\Models\PartnerWebhookSubscription;
use App\Modules\PartnerApi\Services\PartnerWebhookSigner;

/**
 * A webhook subscription as its owner sees it.
 *
 * An explicit allow list, like every resource in this module — there is no
 * `$model->toArray()` anywhere near this class, so neither secret column can
 * reach a response by a column simply existing. `$hidden` on the model is the
 * second layer, not the first.
 *
 * `secret` appears here only when `create()` or `rotateSecret()` hands one in.
 * Every other path produces a payload without the key at all, rather than with
 * a null: a partner writing `if ('secret' in response)` gets the right answer.
 */
final class PartnerWebhookResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PartnerWebhookSubscription $subscription, ?string $plaintextSecret = null): array
    {
        $payload = [
            'id' => $subscription->id,
            'url' => $subscription->url,
            'description' => $subscription->description,
            'event_types' => $subscription->event_types ?? [],
            'is_active' => $subscription->is_active,
            // Why it is off, when we turned it off. Exposed because a partner
            // whose endpoint was suspended needs to be able to find that out
            // without a support ticket.
            'disabled_reason' => $subscription->disabled_reason,
            'disabled_at' => $subscription->disabled_at?->toIso8601String(),
            'consecutive_failures' => $subscription->consecutive_failures,
            'last_delivery_at' => $subscription->last_delivery_at?->toIso8601String(),
            'last_success_at' => $subscription->last_success_at?->toIso8601String(),
            'secret_rotated_at' => $subscription->secret_rotated_at?->toIso8601String(),
            // How long the pre-rotation secret still verifies. Null when there
            // is no open grace window.
            'previous_secret_expires_at' => $subscription->previous_secret_expires_at?->toIso8601String(),
            'created_at' => $subscription->created_at?->toIso8601String(),
            'updated_at' => $subscription->updated_at?->toIso8601String(),
        ];

        if ($plaintextSecret !== null) {
            $payload['secret'] = $plaintextSecret;
            $payload['signature'] = self::signatureRecipe();
        }

        return $payload;
    }

    /**
     * The verification recipe, returned alongside the one and only time a
     * partner sees the secret — the moment they are actually writing the code
     * that checks it.
     *
     * @return array<string, mixed>
     */
    public static function signatureRecipe(): array
    {
        return [
            'algorithm' => 'HMAC-SHA256',
            'signed_payload' => '{timestamp}.{raw_request_body}',
            'headers' => [
                'event_id' => PartnerWebhookSigner::HEADER_EVENT_ID,
                'timestamp' => PartnerWebhookSigner::HEADER_TIMESTAMP,
                'signature' => PartnerWebhookSigner::HEADER_SIGNATURE,
            ],
            'signature_format' => 'v1={hex digest}',
            'replay_tolerance_seconds' => (int) config('partner_api.webhooks.replay_tolerance_seconds', 300),
            'note' => 'Sign the raw request body exactly as received. Re-serialising the parsed '
                .'JSON will not match. Reject requests whose timestamp is outside the tolerance '
                .'window, and use the event id to discard events you have already processed.',
        ];
    }
}
