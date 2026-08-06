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
                    'event_types' => ['order.status_changed', 'offer.published'],
                    'is_active' => true,
                    'secret' => 'whsec_2f1c…',
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
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
    #[Endpoint(title: 'Get a webhook subscription')]
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
