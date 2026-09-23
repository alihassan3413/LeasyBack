<?php

namespace App\Modules\PartnerApi\Enums;

/**
 * Every event this API will ever send, and nothing else.
 *
 * Each case corresponds to a moment that already exists in the B2B workflow and
 * already has a writer — a status transition, an offer decision, a document
 * becoming visible. Nothing here is aspirational: an event with no emit point
 * would be a promise partners build retries around and never receive, which is
 * worse than not offering it.
 *
 * The vocabulary is `noun.past_tense`. A subscription names its types
 * explicitly, so adding a case below never changes what an existing
 * subscription receives.
 */
enum PartnerWebhookEvent: string
{
    case VehicleCreated = 'vehicle.created';
    case VehicleUpdated = 'vehicle.updated';

    case OrderCreated = 'order.created';
    case OrderStatusChanged = 'order.status_changed';
    case OrderCollectionConfirmed = 'order.collection_confirmed';
    case OrderCollectionRescheduled = 'order.collection_rescheduled';
    case OrderAppraisalCompleted = 'order.appraisal_completed';
    case OrderRepairStarted = 'order.repair_started';
    case OrderFinalAppraisalCompleted = 'order.final_appraisal_completed';
    case OrderBillingCompleted = 'order.billing_completed';
    case OrderCompleted = 'order.completed';

    case DocumentAvailable = 'document.available';
    case DocumentReplaced = 'document.replaced';

    case OfferPublished = 'offer.published';
    case OfferUpdated = 'offer.updated';
    case OfferAccepted = 'offer.accepted';
    case OfferRejected = 'offer.rejected';
    case OfferExpired = 'offer.expired';

    /**
     * The only event a subscription receives without subscribing to it:
     * `POST /webhooks/{id}/test` sends it so a partner can verify their
     * endpoint and their signature check before any real event exists. It is
     * never emitted by a business path, which is why it is not a case above.
     */
    public const TEST_TYPE = 'webhook.test';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The status transitions that additionally mean something specific enough
     * to deserve their own event.
     *
     * `order.status_changed` fires for every transition regardless; these are
     * the four a partner is likely to want a dedicated handler for, and pinning
     * them here rather than in the emitter keeps the mapping next to the
     * vocabulary it belongs to.
     *
     * @return array<string, self>
     */
    public static function forStatus(): array
    {
        return [
            'inspected' => self::OrderAppraisalCompleted,
            'workshop' => self::OrderRepairStarted,
            'reinspection' => self::OrderFinalAppraisalCompleted,
            'completed' => self::OrderCompleted,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::VehicleCreated => 'A vehicle was added to the fleet',
            self::VehicleUpdated => 'A vehicle’s master data changed',
            self::OrderCreated => 'A return order was created',
            self::OrderStatusChanged => 'An order moved to a new status',
            self::OrderCollectionConfirmed => 'A collection appointment was confirmed',
            self::OrderCollectionRescheduled => 'A confirmed collection appointment moved',
            self::OrderAppraisalCompleted => 'The initial appraisal is complete',
            self::OrderRepairStarted => 'The workshop started work',
            self::OrderFinalAppraisalCompleted => 'The final appraisal is complete',
            self::OrderBillingCompleted => 'Billing was marked processed',
            self::OrderCompleted => 'The order is closed',
            self::DocumentAvailable => 'A document became available to the customer',
            self::DocumentReplaced => 'A document was superseded or withdrawn',
            self::OfferPublished => 'A repair offer was presented',
            self::OfferUpdated => 'A presented offer changed',
            self::OfferAccepted => 'An offer was accepted',
            self::OfferRejected => 'An offer was rejected',
            self::OfferExpired => 'An offer passed its validity date',
        };
    }
}
