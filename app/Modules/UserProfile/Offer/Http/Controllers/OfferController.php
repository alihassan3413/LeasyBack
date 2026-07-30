<?php

namespace App\Modules\UserProfile\Offer\Http\Controllers;

use App\Models\LeasybackOffer;
use App\Models\LeasybackOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfferController extends Controller
{
    /**
     * POST /admin/offers/create/{auftragsnummer}
     */
    public function create(Request $request, string $auftragsnummer): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can create offers'], 403);
        }

        $order = LeasybackOrder::where('auftragsnummer', $auftragsnummer)->first();
        if (!$order) {
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

        // Get next sequence
        $maxSeq = LeasybackOffer::where('order_id', $order->id)->max('offer_sequence') ?? 0;

        $offer = LeasybackOffer::create([
            'order_id' => $order->id,
            'auftragsnummer' => $auftragsnummer,
            'offer_sequence' => $maxSeq + 1,
            'offer_status' => 'draft',
            ...$validated,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json($offer, 201);
    }

    /**
     * POST /admin/offers/publish/{offerId}
     */
    public function publish(Request $request, string $offerId): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can publish offers'], 403);
        }

        $offer = LeasybackOffer::find($offerId);
        if (!$offer) {
            return response()->json(['error' => 'Offer not found'], 404);
        }

        if ($offer->offer_status !== 'draft') {
            return response()->json(['error' => 'Only draft offers can be published'], 400);
        }

        $offer->update([
            'offer_status' => 'published',
            'published_at' => now(),
            'published_by_user_id' => $user->id,
        ]);

        return response()->json($offer->fresh());
    }

    /**
     * POST /admin/offers/cancel/{offerId}
     */
    public function cancel(Request $request, string $offerId): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can cancel offers'], 403);
        }

        $validated = $request->validate([
            'cancellation_reason' => 'nullable|string',
        ]);

        $offer = LeasybackOffer::find($offerId);
        if (!$offer) {
            return response()->json(['error' => 'Offer not found'], 404);
        }

        $offer->update([
            'offer_status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $user->id,
            'cancellation_reason' => $validated['cancellation_reason'] ?? null,
        ]);

        return response()->json($offer->fresh());
    }

    /**
     * GET /admin/offers/list/{auftragsnummer}
     */
    public function adminList(Request $request, string $auftragsnummer): JsonResponse
    {
        $user = $request->user();
        if ($user->user_type->value !== 'Admin') {
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
     */
    public function customerSelect(Request $request, string $offerId): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->user_type->value === 'Admin';

        $offer = LeasybackOffer::find($offerId);
        if (!$offer) {
            return response()->json(['error' => 'Offer not found or not accessible'], 404);
        }

        if ($offer->offer_status !== 'published') {
            return response()->json(['error' => 'This offer is no longer available'], 400);
        }

        // Check if an offer is already selected
        $alreadySelected = LeasybackOffer::where('order_id', $offer->order_id)
            ->where('offer_status', 'selected')
            ->exists();

        if ($alreadySelected) {
            return response()->json(['error' => 'An offer has already been selected for this order'], 400);
        }

        $closedCount = 0;

        DB::transaction(function () use ($offer, $user, &$closedCount) {
            // Select this offer
            $offer->update([
                'offer_status' => 'selected',
                'selected_at' => now(),
                'selected_by_user_id' => $user->id,
            ]);

            // Close all other published offers for the same order
            $closedCount = LeasybackOffer::where('order_id', $offer->order_id)
                ->where('offer_id', '!=', $offer->offer_id)
                ->where('offer_status', 'published')
                ->update([
                    'offer_status' => 'closed',
                    'closed_at' => now(),
                ]);
        });

        return response()->json([
            'message' => 'Offer selected successfully',
            'selected_offer' => $offer->fresh(),
            'other_offers_closed' => $closedCount,
        ]);
    }
}
