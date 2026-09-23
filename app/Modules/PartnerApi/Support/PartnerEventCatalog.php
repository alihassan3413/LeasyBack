<?php

namespace App\Modules\PartnerApi\Support;

use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Services\PartnerWebhookSigner;

/**
 * The webhook event catalogue, in a form a machine can check itself against.
 *
 * A partner integrating against 18 event types needs to know two things before
 * they write a handler: which types exist, and what each one's `data` object
 * contains. Prose answers both badly — it goes stale, and it cannot be asserted
 * against in the partner's own CI. This class is the answer instead: it is
 * rendered into the reference documentation *and* written out as
 * `events.json`, so a partner can diff their handler map against ours on every
 * release rather than reading a page.
 *
 * The type list is derived from PartnerWebhookEvent, never restated. A case
 * added to that enum with no shape described here fails
 * `PartnerApiDocumentationTest`, which is the only way this file can be kept
 * honest: an event we send and never documented is exactly the failure a
 * generated catalogue exists to prevent.
 *
 * Every shape below mirrors what PartnerWebhookEvents actually assembles.
 * Absent by design, everywhere: internal notes, storage paths, workshop
 * quotations, gross amounts and billing figures — see that class's docblock.
 */
final class PartnerEventCatalog
{
    /**
     * Reusable payload fragments, so a partner reads `order` once and not
     * eleven times.
     *
     * @return array<string, array<string, string>>
     */
    public static function objects(): array
    {
        return [
            'vehicle' => [
                'id' => 'uuid — the vehicle id used by every /vehicles endpoint',
                'license_plate' => 'string',
                'vin' => 'string|null',
                'make' => 'string|null',
                'model' => 'string|null',
                'leasing_end_date' => 'date (YYYY-MM-DD)|null',
            ],
            'order_ref' => [
                'id' => 'uuid — the order id used by every /orders endpoint',
                'reference' => 'string — the LeasyBack order number (auftragsnummer)',
                'status' => 'string — the order status after the change',
                'vehicle.id' => 'uuid|null',
                'vehicle.license_plate' => 'string|null',
            ],
            'collection' => [
                'confirmed_date' => 'date (YYYY-MM-DD)|null — the agreed collection date',
                'previous_date' => 'date (YYYY-MM-DD)|null — only on order.collection_rescheduled',
            ],
            'billing' => [
                'status' => 'string — always "processed"',
                'processed_at' => 'ISO-8601 UTC timestamp',
            ],
            'document' => [
                'id' => 'uuid',
                'source' => 'string — "report" (LeasyBack paperwork) or "vehicle" (your own)',
                'type' => 'string — e.g. gutachten, nachgutachten, rechnung',
                'type_label' => 'string — human label for `type`',
                'title' => 'string|null',
                'filename' => 'string',
                'content_type' => 'string',
                'size_bytes' => 'integer|null',
                'vehicle.id' => 'uuid',
                'order' => 'object|null — {id, reference}',
                'created_at' => 'ISO-8601 timestamp',
                'updated_at' => 'ISO-8601 timestamp',
                'download_url' => 'string — the endpoint that mints a signed link, not the link itself',
            ],
            'offer' => [
                'id' => 'uuid',
                'order' => 'object — {id, reference}',
                'sequence' => 'integer — offers are numbered per order',
                'status' => 'string — published | selected | rejected',
                'is_accepted' => 'boolean — true when status is "selected"',
                'is_rejected' => 'boolean',
                'is_expired' => 'boolean',
                'valid_until' => 'date (YYYY-MM-DD)|null — good through the end of this day',
                'currency' => 'string — always "EUR"',
                'totals' => 'object — {appraisal_total_net, repair_total_net, saving_net} as decimal strings',
                'positions' => 'array — {component, damage_description, appraisal_amount_net, '
                    .'repair_amount_net, saving_net, repair_method, not_repairable}',
                'customer_note' => 'string|null',
                'customer_comment' => 'string|null',
                'published_at' => 'ISO-8601 timestamp|null',
                'accepted_at' => 'ISO-8601 timestamp|null',
                'rejected_at' => 'ISO-8601 timestamp|null',
                'presented_at' => 'ISO-8601 timestamp|null — when the snapshot was frozen',
            ],
            'external_ids' => [
                'vehicle' => 'string|null — your own id for the vehicle, if you registered one',
                'order' => 'string|null — your own id for the order, if you registered one',
            ],
        ];
    }

    /**
     * Every event type, with the keys its `data` object carries.
     *
     * @return list<array{type: string, summary: string, ability: string, data: list<string>, notes: string}>
     */
    public static function events(): array
    {
        $shapes = self::shapes();

        return array_map(fn (PartnerWebhookEvent $event) => [
            'type' => $event->value,
            'summary' => $event->label(),
            'ability' => self::abilityFor($event),
            'data' => $shapes[$event->value]['data'],
            'notes' => $shapes[$event->value]['notes'],
        ], PartnerWebhookEvent::cases());
    }

    /**
     * The `webhook.test` event, which is not a business event and is therefore
     * not a case on the enum — but is the first thing every partner receives,
     * so it belongs in the catalogue.
     *
     * @return array{type: string, summary: string, ability: string, data: list<string>, notes: string}
     */
    public static function testEvent(): array
    {
        return [
            'type' => PartnerWebhookEvent::TEST_TYPE,
            'summary' => 'A test event you asked us to send',
            'ability' => 'webhooks.manage',
            'data' => ['message', 'subscription'],
            'notes' => 'Sent by POST /webhooks/{id}/test through the identical signing and delivery '
                .'path as a real event, regardless of which types the subscription selected. It '
                .'carries no business data.',
        ];
    }

    /**
     * The full artefact: what `events.json` contains and what the reference
     * documentation renders.
     *
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'api_version' => (string) config('partner_api.webhooks.api_version', 'v1'),
            'envelope' => [
                'id' => 'string — evt_{32 hex}. Stable across every retry and replay: '
                    .'deduplicate on this.',
                'type' => 'string — one of `events[].type` below',
                'api_version' => 'string — matches `api_version` above',
                'occurred_at' => 'ISO-8601 UTC timestamp (Zulu)',
                'data' => 'object — shape depends on `type`',
                'data.external_ids' => 'object — present only when you registered your own ids '
                    .'for the records involved',
            ],
            'headers' => [
                PartnerWebhookSigner::HEADER_EVENT_ID => 'evt_{32 hex} — the deduplication key',
                PartnerWebhookSigner::HEADER_TIMESTAMP => 'Unix seconds, covered by the signature',
                PartnerWebhookSigner::HEADER_SIGNATURE => 'v1={hex hmac}',
                'Content-Type' => 'application/json',
                'User-Agent' => 'LeasyBack-Webhooks/1.0',
            ],
            'signature' => [
                'algorithm' => 'HMAC-SHA256',
                'signed_payload' => '{timestamp}.{raw_request_body}',
                'signature_format' => 'v1={hex digest}',
                'secret_format' => 'whsec_{64 hex}',
                'replay_tolerance_seconds' => (int) config('partner_api.webhooks.replay_tolerance_seconds', 300),
                'secret_rotation_grace_minutes' => (int) config('partner_api.webhooks.secret_rotation_grace_minutes', 60),
                'note' => 'Sign the raw body exactly as received. Re-serialising the parsed JSON '
                    .'will not match: key order and unicode escaping are ours, not yours.',
            ],
            'delivery' => [
                'method' => 'POST',
                'expected_response' => 'Any 2xx. Anything else, including a 3xx, counts as a failure.',
                'redirects_followed' => false,
                'timeout_seconds' => (int) config('partner_api.webhooks.timeout_seconds', 10),
                'connect_timeout_seconds' => (int) config('partner_api.webhooks.connect_timeout_seconds', 5),
                'backoff_seconds' => array_values((array) config('partner_api.webhooks.backoff_seconds', [])),
                'max_attempts' => count((array) config('partner_api.webhooks.backoff_seconds', [])) + 1,
                'auto_disable_after_consecutive_failed_deliveries' => (int) config(
                    'partner_api.webhooks.auto_disable_after_failures',
                    20,
                ),
            ],
            'objects' => self::objects(),
            'events' => [self::testEvent(), ...self::events()],
        ];
    }

    /**
     * The data shape of each business event, keyed by type.
     *
     * Kept as one table rather than a method per event so a missing entry is a
     * missing array key, which the test can see.
     *
     * @return array<string, array{data: list<string>, notes: string}>
     */
    private static function shapes(): array
    {
        $order = ['order'];
        $offer = ['offer'];

        return [
            PartnerWebhookEvent::VehicleCreated->value => [
                'data' => ['vehicle'],
                'notes' => 'Fires for vehicles created through this API and through the portal alike.',
            ],
            PartnerWebhookEvent::VehicleUpdated->value => [
                'data' => ['vehicle'],
                'notes' => 'Master data only. Order progress does not update a vehicle.',
            ],
            PartnerWebhookEvent::OrderCreated->value => [
                'data' => $order,
                'notes' => 'The return order exists. `order.reference` is the number every document '
                    .'and every human reference uses.',
            ],
            PartnerWebhookEvent::OrderStatusChanged->value => [
                'data' => ['order', 'previous_status'],
                'notes' => 'Fires on every real transition. Four of them also fire a narrower event '
                    .'— you will receive both, by design.',
            ],
            PartnerWebhookEvent::OrderAppraisalCompleted->value => [
                'data' => ['order', 'previous_status'],
                'notes' => 'Also fires order.status_changed. Status is `inspected`.',
            ],
            PartnerWebhookEvent::OrderRepairStarted->value => [
                'data' => ['order', 'previous_status'],
                'notes' => 'Also fires order.status_changed. Status is `workshop`.',
            ],
            PartnerWebhookEvent::OrderFinalAppraisalCompleted->value => [
                'data' => ['order', 'previous_status'],
                'notes' => 'Also fires order.status_changed. Status is `reinspection`.',
            ],
            PartnerWebhookEvent::OrderCompleted->value => [
                'data' => ['order', 'previous_status'],
                'notes' => 'Also fires order.status_changed. Status is `completed`; the order is closed.',
            ],
            PartnerWebhookEvent::OrderCollectionConfirmed->value => [
                'data' => ['order', 'collection'],
                'notes' => 'The first time a collection date is confirmed.',
            ],
            PartnerWebhookEvent::OrderCollectionRescheduled->value => [
                'data' => ['order', 'collection'],
                'notes' => 'A confirmed collection date moved. `collection.previous_date` is the '
                    .'date it moved from.',
            ],
            PartnerWebhookEvent::OrderBillingCompleted->value => [
                'data' => ['order', 'billing'],
                'notes' => 'Billing state only, with no figures: this API serves no billing endpoint, '
                    .'and a webhook is not a side door into data no endpoint serves.',
            ],
            PartnerWebhookEvent::DocumentAvailable->value => [
                'data' => ['document', 'order'],
                'notes' => 'Fires on publication, not upload. `order` is absent for a document that '
                    .'hangs off the vehicle rather than an order. Fetch the bytes with '
                    .'GET /documents/{id}/download.',
            ],
            PartnerWebhookEvent::DocumentReplaced->value => [
                'data' => ['document', 'order', 'reason'],
                'notes' => 'A document that was available no longer is — unpublished, or superseded '
                    .'by a newer file for the same order and type. `reason` says which.',
            ],
            PartnerWebhookEvent::OfferPublished->value => [
                'data' => $offer,
                'notes' => 'A repair offer was presented to the customer. The payload is the frozen '
                    .'snapshot, so a retry six hours later sends what was presented.',
            ],
            PartnerWebhookEvent::OfferUpdated->value => [
                'data' => $offer,
                'notes' => 'A presented offer was withdrawn or superseded by a sibling being '
                    .'accepted. Read `offer.status` to tell them apart: `cancelled` versus `closed`.',
            ],
            PartnerWebhookEvent::OfferAccepted->value => [
                'data' => $offer,
                'notes' => 'The customer accepted. `offer.is_accepted` is true.',
            ],
            PartnerWebhookEvent::OfferRejected->value => [
                'data' => $offer,
                'notes' => 'The customer rejected. `offer.is_rejected` is true.',
            ],
            PartnerWebhookEvent::OfferExpired->value => [
                'data' => $offer,
                'notes' => 'The offer passed its `valid_until` date without a decision. Emitted once '
                    .'per offer by a daily job, never twice.',
            ],
        ];
    }

    /**
     * The read ability a partner needs to follow an event up over the API.
     *
     * A subscription itself needs only `webhooks.manage`; this is what the
     * event points them *at*, which is the question a partner actually asks.
     */
    private static function abilityFor(PartnerWebhookEvent $event): string
    {
        return match (true) {
            str_starts_with($event->value, 'vehicle.') => 'vehicles.read',
            str_starts_with($event->value, 'document.') => 'documents.read',
            str_starts_with($event->value, 'offer.') => 'offers.read',
            default => 'orders.read',
        };
    }
}
