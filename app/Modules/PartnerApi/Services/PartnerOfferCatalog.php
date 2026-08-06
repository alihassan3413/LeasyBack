<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Exceptions\PartnerApiException;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\B2bOfferService;
use Illuminate\Support\Collection;

/**
 * The customer-facing B2B repair offers (§10), as a partner sees them.
 *
 * Two filters define "customer-facing" here, and both are load-bearing:
 *
 * 1. **Status.** Only `published`, `selected` and `rejected` — the same set
 *    `VehicleService::paginateVehiclesWithOrders()` and
 *    `OfferController::customerList()` use. A `draft` offer is Admin's work in
 *    progress and a `cancelled` one was withdrawn before anybody saw it;
 *    neither has ever been presented, so neither is history a partner is
 *    entitled to.
 * 2. **A presentation row must exist.** `b2b_offer_presentations` is what
 *    turns a `leasyback_offers` row into a B2B customer offer. Requiring it
 *    means a B2C-shaped offer — which never has one — cannot appear here even
 *    if one were somehow attached to a B2B order, and it is also where the
 *    immutable snapshot lives.
 *
 * The **snapshot** is the point of the presentation row: `lines` is frozen at
 * publish by `B2bOfferService::snapshotOnPublish()` and never rewritten, so an
 * accepted offer keeps reporting exactly the positions and totals the customer
 * accepted even after the underlying appraisal positions are edited. This
 * class therefore reads `presentation->lines`, never the live
 * `AppraisalPosition` rows.
 *
 * What never leaves: `workshop_quotation_id` and everything reachable through
 * it. The workshop comparison — which garages were asked, what each of them
 * quoted, which one won and by how much — is Leasyback's procurement, not the
 * partner's. Gross amounts are absent for the same reason they are absent
 * everywhere in the B2B flow (§9): the process is net-only.
 */
class PartnerOfferCatalog
{
    /** @var list<string> */
    private const CUSTOMER_FACING_STATUSES = ['published', 'selected', B2bOfferService::STATUS_REJECTED];

    public function __construct(private readonly PartnerResourceLocator $locator) {}

    /**
     * One order's customer-facing offers, oldest sequence first — the order
     * they were presented in.
     *
     * @return Collection<int, array{offer: LeasybackOffer, presentation: B2bOfferPresentation}>
     */
    public function forOrder(LeasybackOrder $order): Collection
    {
        $offers = LeasybackOffer::where('order_id', $order->id)
            ->whereIn('offer_status', self::CUSTOMER_FACING_STATUSES)
            ->orderBy('offer_sequence')
            ->get();

        if ($offers->isEmpty()) {
            return collect();
        }

        $presentations = B2bOfferPresentation::whereIn('offer_id', $offers->pluck('offer_id')->all())
            ->get()
            ->keyBy('offer_id');

        return $offers
            ->filter(fn (LeasybackOffer $offer) => $presentations->has($offer->offer_id))
            ->map(fn (LeasybackOffer $offer) => [
                'offer' => $offer,
                'presentation' => $presentations->get($offer->offer_id),
            ])
            ->values();
    }

    /**
     * One offer, resolved and authorised in the same lookup: it must belong to
     * an order this token's company owns, hold a customer-facing status and
     * have been presented. A draft, a cancelled offer, another company's
     * offer and a nonexistent id are all the same 404.
     *
     * @return array{offer: LeasybackOffer, presentation: B2bOfferPresentation, order: LeasybackOrder}
     */
    public function findOrFail(string $offerId): array
    {
        $offer = LeasybackOffer::where('offer_id', $offerId)
            ->whereIn('offer_status', self::CUSTOMER_FACING_STATUSES)
            ->whereIn('order_id', $this->locator->orderQuery()->select('id'))
            ->first();

        $presentation = $offer === null
            ? null
            : B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();

        $order = $offer === null
            ? null
            : $this->locator->orderQuery()->where('id', $offer->order_id)->first();

        if ($offer === null || $presentation === null || $order === null) {
            throw PartnerApiException::notFound(
                'offer_not_found',
                'No offer with that id exists in the company this token belongs to.',
            );
        }

        return ['offer' => $offer, 'presentation' => $presentation, 'order' => $order];
    }
}
