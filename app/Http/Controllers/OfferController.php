<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Models\LeasybackOffer;
use App\Modules\UserProfile\Offer\Services\OfferService;
use App\Modules\UserProfile\Order\Services\RepairOfferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(
        private readonly OfferService $offerService,
        private readonly RepairOfferService $repairOfferService,
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

        $result = null;

        $denied = $this->withServiceErrorHandling(
            'offer',
            function () use ($offer, $user, &$result) {
                $result = $this->offerService->selectOffer($offer, $user);
            }
        );

        if ($denied) {
            return $denied;
        }

        // A replay — double-click, browser retry, back-and-resubmit — reaches
        // the same successful state, and says so rather than claiming a second
        // acceptance just happened.
        return back()->with('success', $result['already_selected']
            ? 'Dieses Angebot ist bereits angenommen.'
            : 'Angebot wurde ausgewählt.');
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

        $validated = $request->validate(RepairOfferService::rejectRules());

        return $this->withServiceErrorHandling(
            'offer',
            fn () => $this->repairOfferService->reject($offer, $user, $validated)
        ) ?? back()->with('success', 'Angebot wurde abgelehnt.');
    }
}
