<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Models\LeasybackOffer;
use App\Modules\UserProfile\Offer\Services\OfferService;
use App\Modules\UserProfile\Order\Services\B2bOfferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(
        private readonly OfferService $offerService,
        private readonly B2bOfferService $b2bOfferService,
    ) {}

    /**
     * Session-authenticated counterpart of the Sanctum API's
     * OfferController::customerSelect(). Same OfferPolicy::select
     * ownership check as the API (see Checkpoint 6 BOLA fix) — the 404 on
     * a missing/inaccessible offer is unchanged so a non-owner can't
     * distinguish "doesn't exist" from "exists but isn't yours".
     */
    public function select(Request $request, string $offerId): RedirectResponse
    {
        $user = $request->user();
        $offer = LeasybackOffer::find($offerId);

        if (! $offer || ! $user->can('select', $offer)) {
            abort(404);
        }

        return $this->withServiceErrorHandling(
            'offer',
            fn () => $this->offerService->selectOffer($offer, $user)
        ) ?? back()->with('success', 'Angebot wurde ausgewählt.');
    }

    /**
     * The reject half of §10's accept/reject pair, with an optional customer
     * comment. Same owner-only rule and same indistinguishable 404 as
     * select(). Rejecting deliberately leaves `order_status` alone.
     */
    public function reject(Request $request, string $offerId): RedirectResponse
    {
        $user = $request->user();
        $offer = LeasybackOffer::find($offerId);

        if (! $offer || ! $user->can('reject', $offer)) {
            abort(404);
        }

        $validated = $request->validate(B2bOfferService::rejectRules());

        return $this->withServiceErrorHandling(
            'offer',
            fn () => $this->b2bOfferService->reject($offer, $user, $validated)
        ) ?? back()->with('success', 'Angebot wurde abgelehnt.');
    }
}
