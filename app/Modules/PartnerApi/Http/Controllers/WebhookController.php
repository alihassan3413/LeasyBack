<?php

namespace App\Modules\PartnerApi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Http\Requests\StorePartnerWebhookRequest;
use App\Modules\PartnerApi\Http\Requests\UpdatePartnerWebhookRequest;
use App\Modules\PartnerApi\Http\Resources\PartnerWebhookDeliveryResource;
use App\Modules\PartnerApi\Http\Resources\PartnerWebhookResource;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Services\PartnerWebhookSubscriptionService;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use App\Modules\PartnerApi\Support\PartnerPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;

/**
 * Outbound webhooks: subscriptions, their secrets, and what happened to every
 * event we tried to hand over.
 *
 * A subscription belongs to the integration client the token was issued to, and
 * nothing here takes an owner from request input — the same rule the rest of
 * this API follows, and the reason another client's subscription id answers 404
 * rather than 403.
 *
 * There is deliberately **no** inbound endpoint. This API does not accept
 * webhooks; it sends them. Anything a partner wants to tell us goes through the
 * documented write endpoints, where it is validated and authorised.
 */
#[Group(name: 'Partner API')]
class WebhookController extends Controller
{
    public function __construct(private readonly PartnerWebhookSubscriptionService $subscriptions) {}

    /**
     * List webhook subscriptions.
     */
    #[Endpoint(
        title: 'List webhook subscriptions',
        description: 'Every subscription belonging to this integration client. Secrets are never '
            .'included — they are shown once, at creation and at rotation, and are unrecoverable '
            .'afterwards.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'webhooks' => [[
                    'id' => 'b3d1c2e4-5f6a-4b7c-8d9e-0f1a2b3c4d5e',
                    'url' => 'https://partner.example.com/hooks/leasyback',
                    'description' => 'Production fleet sync',
                    'event_types' => ['order.status_changed', 'offer.published'],
                    'is_active' => true,
                    'disabled_reason' => null,
                    'disabled_at' => null,
                    'consecutive_failures' => 0,
                    'last_delivery_at' => '2026-08-06T09:14:02+00:00',
                    'last_success_at' => '2026-08-06T09:14:02+00:00',
                    'secret_rotated_at' => null,
                    'previous_secret_expires_at' => null,
                    'created_at' => '2026-08-01T08:00:00+00:00',
                    'updated_at' => '2026-08-06T09:14:02+00:00',
                ]],
                'supported_event_types' => ['vehicle.created', 'order.status_changed', 'offer.published'],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'Every subscription this integration client owns. `supported_event_types` is '
            .'the full catalogue, abbreviated here — see the Webhooks section for all of it.'
    )]
    public function index(Request $request): JsonResponse
    {
        return PartnerApiResponse::success([
            'webhooks' => $this->subscriptions->query()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($subscription) => PartnerWebhookResource::toArray($subscription))
                ->all(),
            'supported_event_types' => PartnerWebhookEvent::values(),
        ]);
    }

    /**
     * Create a webhook subscription.
     */
    #[Endpoint(
        title: 'Create a webhook subscription',
        description: 'Requires an `Idempotency-Key`. The response carries the signing secret; this '
            .'is the only time it is returned. The target URL must be a public https endpoint — '
            .'loopback, private, link-local and cloud-metadata targets are refused here and '
            .'re-checked before every delivery.'
    )]
    #[Response(
        status: 201,
        content: [
            'data' => [
                'webhook' => [
                    'id' => 'b3d1c2e4-5f6a-4b7c-8d9e-0f1a2b3c4d5e',
                    'url' => 'https://partner.example.com/hooks/leasyback',
                    'description' => 'Production fleet sync',
                    'event_types' => ['order.status_changed', 'offer.published'],
                    'is_active' => true,
                    'disabled_reason' => null,
                    'disabled_at' => null,
                    'consecutive_failures' => 0,
                    'last_delivery_at' => null,
                    'last_success_at' => null,
                    'secret_rotated_at' => null,
                    'previous_secret_expires_at' => null,
                    'created_at' => '2026-08-06T09:14:02+00:00',
                    'updated_at' => '2026-08-06T09:14:02+00:00',
                    'secret' => 'whsec_{64 hex} — shown here and nowhere else. Store it now.',
                    'signature' => [
                        'algorithm' => 'HMAC-SHA256',
                        'signed_payload' => '{timestamp}.{raw_request_body}',
                        'headers' => [
                            'event_id' => 'X-LeasyBack-Event-ID',
                            'timestamp' => 'X-LeasyBack-Timestamp',
                            'signature' => 'X-LeasyBack-Signature',
                        ],
                        'signature_format' => 'v1={hex digest}',
                        'replay_tolerance_seconds' => 300,
                        'note' => 'Sign the raw request body exactly as received.',
                    ],
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'The subscription, and the only response that ever carries `secret`. The '
            .'`signature` block is the verification recipe, returned at the moment you are '
            .'writing the check.'
    )]
    #[Response(
        status: 400,
        content: [
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'webhook_url_not_allowed',
                'message' => 'Webhook URLs must use https. The scheme \'http\' is not accepted.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function store(StorePartnerWebhookRequest $request): JsonResponse
    {
        $created = $this->subscriptions->create(
            $request->targetUrl(),
            $request->eventTypes(),
            $request->description(),
        );

        return PartnerApiResponse::success([
            'webhook' => PartnerWebhookResource::toArray($created['subscription'], $created['secret']),
        ], 201);
    }

    /**
     * Retrieve one webhook subscription.
     */
    #[Endpoint(
        title: 'Get a webhook subscription',
        description: 'Current configuration and delivery health. `disabled_reason` is where a '
            .'partner finds out we suspended their endpoint, and why, without a support ticket.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'webhook' => [
                    'id' => 'b3d1c2e4-5f6a-4b7c-8d9e-0f1a2b3c4d5e',
                    'url' => 'https://partner.example.com/hooks/leasyback',
                    'description' => 'Production fleet sync',
                    'event_types' => ['order.status_changed', 'offer.published'],
                    'is_active' => false,
                    'disabled_reason' => 'Automatically suspended after 20 consecutive failed deliveries.',
                    'disabled_at' => '2026-08-06T09:14:02+00:00',
                    'consecutive_failures' => 20,
                    'last_delivery_at' => '2026-08-06T09:14:02+00:00',
                    'last_success_at' => '2026-08-04T16:02:11+00:00',
                    'secret_rotated_at' => null,
                    'previous_secret_expires_at' => null,
                    'created_at' => '2026-08-01T08:00:00+00:00',
                    'updated_at' => '2026-08-06T09:14:02+00:00',
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'A subscription we suspended. PATCH `is_active` back to true once the '
            .'endpoint is fixed; that also resets the failure counter.'
    )]
    #[Response(
        status: 404,
        content: [
            'error' => [
                'type' => 'not_found',
                'code' => 'webhook_not_found',
                'message' => 'No webhook subscription with that id exists for this integration.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'Another client’s subscription answers identically — there is no way to probe '
            .'for one you do not own.'
    )]
    public function show(Request $request, string $webhook): JsonResponse
    {
        return PartnerApiResponse::success([
            'webhook' => PartnerWebhookResource::toArray($this->subscriptions->findOrFail($webhook)),
        ]);
    }

    /**
     * Update a webhook subscription.
     */
    #[Endpoint(
        title: 'Update a webhook subscription',
        description: 'Change the target URL, the event list, the description, or turn the '
            .'subscription on and off. Re-enabling a subscription we suspended also clears the '
            .'consecutive-failure counter, so a fixed endpoint starts from zero.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'webhook' => [
                    'id' => 'b3d1c2e4-5f6a-4b7c-8d9e-0f1a2b3c4d5e',
                    'url' => 'https://partner.example.com/hooks/leasyback',
                    'description' => 'Production fleet sync',
                    'event_types' => ['order.status_changed', 'offer.published', 'document.available'],
                    'is_active' => true,
                    'disabled_reason' => null,
                    'disabled_at' => null,
                    'consecutive_failures' => 0,
                    'last_delivery_at' => '2026-08-06T09:14:02+00:00',
                    'last_success_at' => '2026-08-06T09:14:02+00:00',
                    'secret_rotated_at' => null,
                    'previous_secret_expires_at' => null,
                    'created_at' => '2026-08-01T08:00:00+00:00',
                    'updated_at' => '2026-08-06T10:31:44+00:00',
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'The subscription after the change. The secret is not returned here, or '
            .'anywhere except creation and rotation.'
    )]
    #[Response(
        status: 400,
        content: [
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'webhook_event_type_unknown',
                'message' => 'Unknown event type \'order.finished\'.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'An entry in `event_types` is not one of the published types.'
    )]
    public function update(UpdatePartnerWebhookRequest $request, string $webhook): JsonResponse
    {
        $subscription = $this->subscriptions->findOrFail($webhook);

        return PartnerApiResponse::success([
            'webhook' => PartnerWebhookResource::toArray(
                $this->subscriptions->update($subscription, $request->changes()),
            ),
        ]);
    }

    /**
     * Delete a webhook subscription.
     */
    #[Endpoint(
        title: 'Delete a webhook subscription',
        description: 'Permanent, and takes the delivery history with it. To stop deliveries '
            .'reversibly, PATCH `is_active` to false instead.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => ['deleted' => true],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function destroy(Request $request, string $webhook): JsonResponse
    {
        $this->subscriptions->delete($this->subscriptions->findOrFail($webhook));

        return PartnerApiResponse::success(['deleted' => true]);
    }

    /**
     * Rotate a subscription's signing secret.
     */
    #[Endpoint(
        title: 'Rotate a webhook signing secret',
        description: 'Returns the new secret — once. The previous secret keeps verifying for a '
            .'grace window (`previous_secret_expires_at`) so the new one can be deployed without a '
            .'hard cutover; during the window either secret validates a signature we sent.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'webhook' => [
                    'id' => 'b3d1c2e4-5f6a-4b7c-8d9e-0f1a2b3c4d5e',
                    'url' => 'https://partner.example.com/hooks/leasyback',
                    'event_types' => ['order.status_changed', 'offer.published'],
                    'is_active' => true,
                    'secret_rotated_at' => '2026-08-06T09:14:02+00:00',
                    'previous_secret_expires_at' => '2026-08-06T10:14:02+00:00',
                    'secret' => 'whsec_{64 hex} — the new one, shown here and nowhere else.',
                    'signature' => [
                        'algorithm' => 'HMAC-SHA256',
                        'signed_payload' => '{timestamp}.{raw_request_body}',
                        'signature_format' => 'v1={hex digest}',
                        'replay_tolerance_seconds' => 300,
                    ],
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'Until `previous_secret_expires_at`, a request we send verifies against '
            .'either secret. Accept both while you deploy.'
    )]
    public function rotateSecret(Request $request, string $webhook): JsonResponse
    {
        $rotated = $this->subscriptions->rotateSecret($this->subscriptions->findOrFail($webhook));

        return PartnerApiResponse::success([
            'webhook' => PartnerWebhookResource::toArray($rotated['subscription'], $rotated['secret']),
        ]);
    }

    /**
     * List a subscription's deliveries.
     */
    #[Endpoint(
        title: 'List webhook deliveries',
        description: 'Newest first. One row per event we tried to hand to this subscription, with '
            .'every attempt and a bounded excerpt of what the endpoint returned. Pass '
            .'`status=failed` for the failures only.'
    )]
    #[QueryParam('status', 'string', 'Set to `failed` to return only failed and exhausted deliveries.', required: false)]
    #[QueryParam('event_type', 'string', 'Narrow to one event type.', required: false)]
    #[QueryParam('per_page', 'integer', 'Default 25, maximum 100.', required: false)]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'deliveries' => [[
                    'id' => '7c9e2b41-3a55-4de0-8f1b-2a6d4c8e0f33',
                    'event' => [
                        'id' => 'evt_4f1c2e4a4c1e4a9b9f0e2b1d5a7c3e11',
                        'type' => 'order.status_changed',
                        'api_version' => 'v1',
                        'occurred_at' => '2026-08-06T09:14:02+00:00',
                    ],
                    'status' => 'failed',
                    'attempts' => 2,
                    'last_status_code' => 500,
                    'last_error' => 'Endpoint responded 500.',
                    'last_response_excerpt' => 'Internal Server Error',
                    'last_attempt_at' => '2026-08-06T09:16:32+00:00',
                    'next_attempt_at' => '2026-08-06T09:26:32+00:00',
                    'delivered_at' => null,
                    'replayed_at' => null,
                    'created_at' => '2026-08-06T09:14:02+00:00',
                    'attempt_log' => [[
                        'attempt' => 1,
                        'status_code' => 500,
                        'duration_ms' => 412,
                        'error' => null,
                        'response_excerpt' => 'Internal Server Error',
                        'blocked' => false,
                        'attempted_at' => '2026-08-06T09:14:03+00:00',
                    ]],
                ]],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 25,
                    'total' => 1,
                    'from' => 1,
                    'to' => 1,
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'An attempt with `"blocked": true` and no status code never left our process: '
            .'the target failed the URL check at delivery time, which is almost always DNS.'
    )]
    public function deliveries(Request $request, string $webhook): JsonResponse
    {
        $subscription = $this->subscriptions->findOrFail($webhook);

        $paginator = $this->subscriptions->deliveries(
            $subscription,
            PartnerPagination::perPage($request),
            PartnerPagination::page($request),
            $request->query('status') === 'failed',
            $this->eventTypeFilter($request),
        );

        return PartnerApiResponse::success([
            'deliveries' => array_map(
                fn (PartnerWebhookDelivery $delivery) => PartnerWebhookDeliveryResource::toArray($delivery),
                $paginator->items(),
            ),
            'meta' => PartnerPagination::meta($paginator),
        ]);
    }

    /**
     * Retrieve one delivery.
     */
    #[Endpoint(
        title: 'Get a webhook delivery',
        description: 'Includes the event payload we sent, so a failing signature check can be '
            .'debugged against the exact data.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'delivery' => [
                    'id' => '7c9e2b41-3a55-4de0-8f1b-2a6d4c8e0f33',
                    'event' => [
                        'id' => 'evt_4f1c2e4a4c1e4a9b9f0e2b1d5a7c3e11',
                        'type' => 'order.status_changed',
                        'api_version' => 'v1',
                        'occurred_at' => '2026-08-06T09:14:02+00:00',
                        'data' => [
                            'order' => [
                                'id' => '4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2',
                                'reference' => 'BXY123260806',
                                'status' => 'inspected',
                                'vehicle' => [
                                    'id' => '9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f',
                                    'license_plate' => 'B-XY 1234',
                                ],
                            ],
                            'previous_status' => 'collected',
                        ],
                    ],
                    'status' => 'succeeded',
                    'attempts' => 1,
                    'last_status_code' => 200,
                    'last_error' => null,
                    'last_response_excerpt' => 'OK',
                    'last_attempt_at' => '2026-08-06T09:14:03+00:00',
                    'next_attempt_at' => null,
                    'delivered_at' => '2026-08-06T09:14:03+00:00',
                    'replayed_at' => null,
                    'created_at' => '2026-08-06T09:14:02+00:00',
                    'attempt_log' => [],
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: '`event.data` is the payload we sent. Note it is the envelope’s `data` — the '
            .'signed body also carries `id`, `type`, `api_version` and `occurred_at` around it.'
    )]
    #[Response(
        status: 404,
        content: [
            'error' => [
                'type' => 'not_found',
                'code' => 'webhook_delivery_not_found',
                'message' => 'No delivery with that id exists for this subscription.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function delivery(Request $request, string $webhook, string $delivery): JsonResponse
    {
        $subscription = $this->subscriptions->findOrFail($webhook);
        $found = $this->subscriptions->findDeliveryOrFail($subscription, $delivery);

        return PartnerApiResponse::success([
            'delivery' => PartnerWebhookDeliveryResource::toArray($found, withPayload: true),
        ]);
    }

    /**
     * Replay a failed delivery.
     */
    #[Endpoint(
        title: 'Replay a webhook delivery',
        description: 'Queues the same event, with the same event id and the same body, for another '
            .'attempt. A delivery that already succeeded is refused — replaying it would send a '
            .'duplicate. The attempt counter is not reset: it records how many times the endpoint '
            .'has been called with these bytes.'
    )]
    #[Response(
        status: 202,
        content: [
            'data' => [
                'delivery' => [
                    'id' => '7c9e2b41-3a55-4de0-8f1b-2a6d4c8e0f33',
                    'event' => [
                        'id' => 'evt_4f1c2e4a4c1e4a9b9f0e2b1d5a7c3e11',
                        'type' => 'order.status_changed',
                        'api_version' => 'v1',
                        'occurred_at' => '2026-08-06T09:14:02+00:00',
                    ],
                    'status' => 'pending',
                    'attempts' => 2,
                    'next_attempt_at' => '2026-08-06T11:02:00+00:00',
                    'delivered_at' => null,
                    'replayed_at' => '2026-08-06T11:02:00+00:00',
                    'attempt_log' => [],
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'Queued. The event id is unchanged, so your deduplication will recognise it '
            .'if you already processed it.'
    )]
    #[Response(
        status: 409,
        content: [
            'error' => [
                'type' => 'conflict',
                'code' => 'webhook_delivery_already_succeeded',
                'message' => 'This delivery already succeeded; replaying it would send a duplicate.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function replay(Request $request, string $webhook, string $delivery): JsonResponse
    {
        $subscription = $this->subscriptions->findOrFail($webhook);
        $found = $this->subscriptions->findDeliveryOrFail($subscription, $delivery);

        return PartnerApiResponse::success([
            'delivery' => PartnerWebhookDeliveryResource::toArray($this->subscriptions->replay($found)),
        ], 202);
    }

    /**
     * Send a test event.
     */
    #[Endpoint(
        title: 'Send a test webhook event',
        description: 'Queues a signed `webhook.test` event to this subscription through the same '
            .'delivery path as a real one, so a partner can verify their endpoint and their '
            .'signature check before any business event exists. It carries no business data and is '
            .'sent regardless of which event types the subscription selected.'
    )]
    #[Response(
        status: 202,
        content: [
            'data' => [
                'delivery' => [
                    'id' => '2a44f9c1-77b0-4e3d-9a11-5f0c8b2d6e40',
                    'event' => [
                        'id' => 'evt_9b2d1a7c3e114f1c2e4a4c1e4a9b9f0e',
                        'type' => 'webhook.test',
                        'api_version' => 'v1',
                        'occurred_at' => '2026-08-06T09:14:02+00:00',
                    ],
                    'status' => 'pending',
                    'attempts' => 0,
                    'last_status_code' => null,
                    'last_error' => null,
                    'next_attempt_at' => '2026-08-06T09:14:02+00:00',
                    'delivered_at' => null,
                    'attempt_log' => [],
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'Queued, not delivered — the response returns before the call is made. Poll '
            .'`GET /webhooks/{id}/deliveries` to see how it went.'
    )]
    public function test(Request $request, string $webhook): JsonResponse
    {
        $subscription = $this->subscriptions->findOrFail($webhook);

        return PartnerApiResponse::success([
            'delivery' => PartnerWebhookDeliveryResource::toArray(
                $this->subscriptions->sendTestEvent($subscription),
            ),
        ], 202);
    }

    /**
     * An unknown `event_type` filter is treated as "no such events" rather than
     * a validation error: a filter is a narrowing, and failing a poll loop over
     * one is worse than returning an empty page.
     */
    private function eventTypeFilter(Request $request): ?string
    {
        $type = $request->query('event_type');

        return is_string($type) && $type !== '' ? $type : null;
    }
}
