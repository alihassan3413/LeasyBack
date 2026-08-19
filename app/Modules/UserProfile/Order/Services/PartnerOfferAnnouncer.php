<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\LeasybackOffer;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;

/**
 * Partner webhook fan-out for offer events. Genuinely B2B — the Partner API is
 * a company integration and `PartnerWebhookEvents` refuses anything whose
 * vehicle is not B2B anyway.
 *
 * Extracted from the offer service because it was the one channel-specific
 * thing inside it, and because leaving it there made "is this offer logic
 * generic?" impossible to answer by reading one class. Offer construction,
 * snapshotting and validity are shared by both channels; telling a partner
 * about it is not.
 *
 * Every offer webhook goes through here rather than being emitted at each call
 * site, for one reason: a call site that forgets the channel check would leak a
 * B2C offer to a partner feed. Here the check is structural and cannot be
 * forgotten — the guard below is also what keeps B2C quiet now that B2C offers
 * have presentation rows of their own.
 */
class PartnerOfferAnnouncer
{
    public function __construct(private readonly PartnerWebhookEvents $webhooks) {}

    /**
     * Announce something that happened to a *presented* offer.
     *
     * The snapshot handed to the payload is the presentation's own frozen
     * `lines`, which is why an accepted offer's webhook stays a record of what
     * was accepted even after the underlying appraisal positions are edited.
     *
     * @param  'published'|'updated'|'accepted'|'rejected'|'expired'  $what
     */
    public function announce(string $what, ?LeasybackOffer $offer): void
    {
        if ($offer === null) {
            return;
        }

        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();

        if ($presentation === null) {
            return;
        }

        $order = LeasybackOrder::where('id', $offer->order_id)->first();

        if ($order === null || ! $this->isPartnerVisible($order)) {
            return;
        }

        match ($what) {
            'published' => $this->webhooks->offerPublished($offer, $presentation, $order),
            'updated' => $this->webhooks->offerUpdated($offer, $presentation, $order),
            'accepted' => $this->webhooks->offerAccepted($offer, $presentation, $order),
            'rejected' => $this->webhooks->offerRejected($offer, $presentation, $order),
            'expired' => $this->webhooks->offerExpired($offer, $presentation, $order),
        };
    }

    /**
     * Until B2C offers existed, "has a presentation row" *was* "is B2B", and
     * that coincidence carried the channel check. It no longer holds, so the
     * check is now made explicitly rather than inferred.
     *
     * PartnerWebhookEvents drops non-B2B orders on its own, so this is defence
     * in depth — but it is the layer that states the intent, and it keeps a B2C
     * offer from walking through the fan-out at all.
     */
    private function isPartnerVisible(LeasybackOrder $order): bool
    {
        return TransitionOrderStatus::isB2bOrder($order);
    }
}
