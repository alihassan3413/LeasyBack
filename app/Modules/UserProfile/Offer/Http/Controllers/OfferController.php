<?php

namespace App\Modules\UserProfile\Offer\Http\Controllers;

use App\Models\LeasybackOffer;
use App\Models\LeasybackOrder;
use App\Modules\UserProfile\Offer\Services\OfferService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OfferController extends Controller
{
    public function __construct(private readonly OfferService $offerService) {}

    /**
     * POST /admin/offers/create/{auftragsnummer}
     */
    public function create(Request $request, string $auftragsnummer): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('create', LeasybackOffer::class)) {
            return response()->json(['error' => 'Only admin can create offers'], 403);
        }

        $order = LeasybackOrder::where('auftragsnummer', $auftragsnummer)->first();
        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $validated = $request->validate([
            'repair_cost_net' => 'required|numeric|min:0',
            'repair_cost_gross' => 'required|numeric|min:0',
            'depreciation_value_net' => 'required|numeric|min:0',
            'depreciation_value_gross' => 'required|numeric|min:0',
            'workshop_repair_quote_net' => 'required|numeric|min:0',
            'workshop_repair_quote_gross' => 'required|numeric|min:0',
            'missing_parts_cost_net' => 'required|numeric|min:0',
            'missing_parts_cost_gross' => 'required|numeric|min:0',
            'additional_notes' => 'nullable|string',
        ]);

        $offer = $this->offerService->createOffer($order, $validated, $user);

        return response()->json($offer, 201);
    }

    /**
     * POST /admin/offers/publish/{offerId}
     */
    public function publish(Request $request, string $offerId): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('publish', LeasybackOffer::class)) {
            return response()->json(['error' => 'Only admin can publish offers'], 403);
        }

        $offer = LeasybackOffer::find($offerId);
        if (! $offer) {
            return response()->json(['error' => 'Offer not found'], 404);
        }

        try {
            $offer = $this->offerService->publishOffer($offer, $user);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json($offer);
    }

    /**
     * POST /admin/offers/cancel/{offerId}
     */
    public function cancel(Request $request, string $offerId): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('cancel', LeasybackOffer::class)) {
            return response()->json(['error' => 'Only admin can cancel offers'], 403);
        }

        $validated = $request->validate([
            'cancellation_reason' => 'nullable|string',
        ]);

        $offer = LeasybackOffer::find($offerId);
        if (! $offer) {
            return response()->json(['error' => 'Offer not found'], 404);
        }

        $offer = $this->offerService->cancelOffer($offer, $validated['cancellation_reason'] ?? null, $user);

        return response()->json($offer);
    }

    /**
     * GET /admin/offers/list/{auftragsnummer}
     */
    public function adminList(Request $request, string $auftragsnummer): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('viewAny', LeasybackOffer::class)) {
            return response()->json(['error' => 'Only admin can list all offers'], 403);
        }

        $offers = LeasybackOffer::where('auftragsnummer', $auftragsnummer)
            ->orderBy('offer_sequence')
            ->get();

        return response()->json([
            'auftragsnummer' => $auftragsnummer,
            'offers' => $offers,
        ]);
    }

    /**
     * GET /vehicle/offers/customer/list/{auftragsnummer}
     */
    public function customerList(Request $request, string $auftragsnummer): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type->value === 'Admin') {
            return response()->json(['error' => 'Admin cannot use customer offer endpoint'], 400);
        }

        // Only show published/selected offers for vehicles the user owns
        $offers = LeasybackOffer::where('auftragsnummer', $auftragsnummer)
            ->whereIn('offer_status', ['published', 'selected'])
            ->whereHas('order', function ($q) use ($user) {
                $q->whereHas('vehicle', function ($vq) use ($user) {
                    $vq->where(function ($inner) use ($user) {
                        $inner->where('b2c_user_id', $user->id)
                            ->orWhereHas('b2b', function ($b2bq) use ($user) {
                                $b2bq->whereHas('users', function ($uq) use ($user) {
                                    $uq->where('users.id', $user->id);
                                });
                            });
                    });
                });
            })
            ->orderBy('offer_sequence')
            ->get();

        return response()->json([
            'auftragsnummer' => $auftragsnummer,
            'offers' => $offers,
        ]);
    }

    /**
     * POST /vehicle/offers/customer/select/{offerId}
     *
     * Fixed BOLA: this used to have no ownership check at all — any
     * authenticated user could select (and thereby close out every
     * competing offer for) any order by guessing its offer id. Now
     * authorized via OfferPolicy::select, which requires the caller to
     * actually own the vehicle behind the offer's order. The 404 message
     * is unchanged so a non-owner can't distinguish "doesn't exist" from
     * "exists but isn't yours".
     */
    public function customerSelect(Request $request, string $offerId): JsonResponse
    {
        $user = $request->user();

        $offer = LeasybackOffer::find($offerId);
        if (! $offer || ! $user->can('select', $offer)) {
            return response()->json(['error' => 'Offer not found or not accessible'], 404);
        }

        try {
            $result = $this->offerService->selectOffer($offer, $user);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json([
            'message' => 'Offer selected successfully',
            'selected_offer' => $result['offer'],
            'other_offers_closed' => $result['closed_count'],
        ]);
    }
}
