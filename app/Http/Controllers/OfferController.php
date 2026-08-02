<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Models\LeasybackOffer;
use App\Modules\UserProfile\Offer\Services\OfferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(private readonly OfferService $offerService) {}

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
}
