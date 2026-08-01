<?php

namespace App\Modules\UserProfile\Offer\Services;

use App\Models\LeasybackOffer;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class OfferService
{
    /**
     * Select a published offer, closing every other published sibling for
     * the same order. Ownership authorization is the caller's job
     * (OfferPolicy::select) — this assumes the caller is already allowed
     * to select $offer.
     *
     * @return array{offer: LeasybackOffer, closed_count: int}
     */
    public function selectOffer(LeasybackOffer $offer, User $user): array
    {
        if ($offer->offer_status !== 'published') {
            $this->fail(400, 'This offer is no longer available');
        }

        $alreadySelected = LeasybackOffer::where('order_id', $offer->order_id)
            ->where('offer_status', 'selected')
            ->exists();

        if ($alreadySelected) {
            $this->fail(400, 'An offer has already been selected for this order');
        }

        $closedCount = 0;

        DB::transaction(function () use ($offer, $user, &$closedCount) {
            $offer->update([
                'offer_status' => 'selected',
                'selected_at' => now(),
                'selected_by_user_id' => $user->id,
            ]);

            $closedCount = LeasybackOffer::where('order_id', $offer->order_id)
                ->where('offer_id', '!=', $offer->offer_id)
                ->where('offer_status', 'published')
                ->update([
                    'offer_status' => 'closed',
                    'closed_at' => now(),
                ]);
        });

        return ['offer' => $offer->fresh(), 'closed_count' => $closedCount];
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
