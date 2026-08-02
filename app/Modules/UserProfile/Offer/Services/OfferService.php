<?php

namespace App\Modules\UserProfile\Offer\Services;

use App\Enums\NotificationType;
use App\Mail\StatusChangeNotification;
use App\Models\LeasybackOffer;
use App\Models\LeasybackOrder;
use App\Models\OfferAuditLog;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Notifications\NotificationPayload;
use App\Services\Notifier;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OfferService
{
    public function __construct(
        private readonly VehicleScopeService $vehicleScope,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Create the next-sequence draft offer for an order. Extracted from the
     * Sanctum OfferController so the new Admin web OfferController
     * (Checkpoint 11) can reuse it without duplicating the sequence logic.
     *
     * @param  array<string, mixed>  $validated
     */
    public function createOffer(LeasybackOrder $order, array $validated, User $user): LeasybackOffer
    {
        return DB::transaction(function () use ($order, $validated, $user) {
            $maxSeq = LeasybackOffer::where('order_id', $order->id)->max('offer_sequence') ?? 0;

            $offer = LeasybackOffer::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'offer_sequence' => $maxSeq + 1,
                'offer_status' => 'draft',
                ...$validated,
                'created_by_user_id' => $user->id,
            ]);

            $this->auditOffer($offer, 'created', null, ['offer_status' => 'draft'], $user->id);

            return $offer;
        });
    }

    public function publishOffer(LeasybackOffer $offer, User $user): LeasybackOffer
    {
        if ($offer->offer_status !== 'draft') {
            $this->fail(400, 'Only draft offers can be published');
        }

        $offer = DB::transaction(function () use ($offer, $user) {
            $offer->update([
                'offer_status' => 'published',
                'published_at' => now(),
                'published_by_user_id' => $user->id,
            ]);

            $this->auditOffer($offer, 'published', ['offer_status' => 'draft'], ['offer_status' => 'published'], $user->id);

            return $offer->fresh();
        });

        // A published offer is the moment the customer has something
        // actionable to review — the second of the two real notification
        // triggers this checkpoint wires up (order-created and
        // order-status-changed cover the rest; see OrderService/
        // TransitionOrderStatus). Reuses StatusChangeNotification's
        // existing generic "there's an update to your vehicle" copy rather
        // than inventing offer-specific content.
        $this->notifyOfferPublished($offer);

        return $offer;
    }

    public function cancelOffer(LeasybackOffer $offer, ?string $reason, User $user): LeasybackOffer
    {
        return DB::transaction(function () use ($offer, $reason, $user) {
            $oldStatus = $offer->offer_status;

            $offer->update([
                'offer_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            $this->auditOffer($offer, 'cancelled', ['offer_status' => $oldStatus], ['offer_status' => 'cancelled'], $user->id);

            return $offer->fresh();
        });
    }

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

            $this->auditOffer($offer, 'selected_by_customer', ['offer_status' => 'published'], ['offer_status' => 'selected'], $user->id);

            $siblingIds = LeasybackOffer::where('order_id', $offer->order_id)
                ->where('offer_id', '!=', $offer->offer_id)
                ->where('offer_status', 'published')
                ->pluck('offer_id');

            $closedCount = $siblingIds->count();

            if ($closedCount > 0) {
                LeasybackOffer::whereIn('offer_id', $siblingIds)->update([
                    'offer_status' => 'closed',
                    'closed_at' => now(),
                ]);

                foreach ($siblingIds as $siblingId) {
                    OfferAuditLog::create([
                        'auftragsnummer' => $offer->auftragsnummer,
                        'offer_id' => $siblingId,
                        'order_id' => $offer->order_id,
                        'action' => 'closed_after_customer_selection',
                        'old_values' => ['offer_status' => 'published'],
                        'new_values' => ['offer_status' => 'closed'],
                        'changed_by_user_id' => $user->id,
                    ]);
                }
            }

            // Offer selection deliberately never touches order_status
            // (Checkpoint 6/7 decisions: stays fully independent, no
            // confirmed product requirement to auto-transition). It's
            // still a real order-lifecycle touchpoint worth recording on
            // the order's own audit trail, per
            // docs/B2C_ADMIN_STATUS_MATRIX.md §6's "offer-related order
            // touchpoints" — otherwise nothing on the order itself shows
            // an offer was ever selected.
            OrderAuditLog::create([
                'order_id' => $offer->order_id,
                'vehicle_id' => $offer->order?->vehicle_id,
                'action' => 'OFFER_SELECTED',
                'old_values' => null,
                'new_values' => ['offer_id' => $offer->offer_id],
                'changed_by_user_id' => $user->id,
            ]);
        });

        return ['offer' => $offer->fresh(), 'closed_count' => $closedCount];
    }

    private function auditOffer(LeasybackOffer $offer, string $action, ?array $old, ?array $new, ?int $userId): void
    {
        OfferAuditLog::create([
            'auftragsnummer' => $offer->auftragsnummer,
            'offer_id' => $offer->offer_id,
            'order_id' => $offer->order_id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'changed_by_user_id' => $userId,
        ]);
    }

    /**
     * Best-effort, never breaks the publish action if the send fails.
     */
    private function notifyOfferPublished(LeasybackOffer $offer): void
    {
        $vehicle = $offer->order?->vehicle;
        if ($vehicle === null) {
            return;
        }

        $this->notifier->send(
            $this->vehicleScope->resolveOwnerUsers($vehicle),
            NotificationPayload::make(
                NotificationType::OfferPublished,
                'Neues Angebot verfügbar',
                sprintf('Für %s liegt ein neues Angebot vor.', $vehicle->license_plate),
                '/dashboard',
                ['auftragsnummer' => $offer->auftragsnummer, 'offer_id' => $offer->offer_id],
            ),
        );

        $owner = $this->vehicleScope->resolveOwnerContact($vehicle);
        if ($owner === null) {
            Log::warning('Could not resolve a vehicle owner contact — skipping offer-published notification', [
                'auftragsnummer' => $offer->auftragsnummer,
                'offer_id' => $offer->offer_id,
            ]);

            return;
        }

        try {
            Mail::to($owner['email'])->queue(new StatusChangeNotification(
                firstName: $owner['name'],
                licensePlate: $vehicle->license_plate,
                actionUrl: rtrim((string) config('app.frontend_url'), '/').'/dashboard',
            ));
        } catch (\Throwable $e) {
            Log::error('Offer-published notification failed', ['offer_id' => $offer->offer_id, 'error' => $e->getMessage()]);
        }
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
