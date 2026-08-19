<?php

namespace App\Modules\UserProfile\Offer\Services;

use App\Enums\NotificationType;
use App\Models\LeasybackOffer;
use App\Models\LeasybackOrder;
use App\Models\OfferAuditLog;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Modules\UserProfile\Order\Services\B2bOfferService;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Notifications\NotificationPayload;
use App\Services\Mail\OrderMailer;
use App\Services\Notifier;
use App\Support\OneSelectedOfferPerOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class OfferService
{
    public function __construct(
        private readonly VehicleScopeService $vehicleScope,
        private readonly Notifier $notifier,
        private readonly OrderMailer $orderMailer,
        private readonly B2bOfferService $b2bOfferService,
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

            // Freezes what a B2B customer is about to see (§10). No-op for
            // B2C, which has no b2b_offer_presentations row.
            $this->b2bOfferService->snapshotOnPublish($offer);

            $this->auditOffer($offer, 'published', ['offer_status' => 'draft'], ['offer_status' => 'published'], $user->id);

            $published = $offer->fresh();

            // After the snapshot, so the event carries the frozen lines rather
            // than the pre-publish draft. B2C emits nothing: no presentation
            // row, no event.
            $this->b2bOfferService->announceOffer('published', $published);

            return $published;
        });

        // A published offer is the moment the customer has something
        // actionable to review, so it gets its own "Reparaturangebot liegt
        // vor" email (OrderMailer::repairQuotationAvailable) rather than the
        // generic status-update copy.
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

            $cancelled = $offer->fresh();

            // `offer.updated` and not a withdrawal event of its own: what
            // changed is the status of an offer the customer has already been
            // shown, and there is no `offer.withdrawn` in the vocabulary
            // because there is no separate state. An offer cancelled while
            // still a draft was never presented and emits nothing.
            if ($oldStatus === 'published') {
                $this->b2bOfferService->announceOffer('updated', $cancelled);
            }

            return $cancelled;
        });
    }

    /**
     * Select a published offer, closing every other published sibling for
     * the same order. Ownership authorization is the caller's job
     * (OfferPolicy::select) — this assumes the caller is already allowed
     * to select $offer.
     *
     * At most one offer per order may ever hold `selected`, and that is
     * guaranteed by a partial unique index rather than by this method being
     * careful: the check the service makes and the constraint the database
     * makes answer the same question, so bypassing the service cannot produce
     * a second accepted offer either.
     *
     * The returned `already_selected` marks a replay: the decision it
     * describes was made by an earlier request, and this call wrote nothing
     * and sent nothing.
     *
     * @param  bool  $onBehalfOfCustomer  True when an admin accepts for the
     *                                    customer. Only changes the audit
     *                                    trail — the state transition and its
     *                                    guards are identical either way, and
     *                                    `selected_by_user_id` records who
     *                                    actually clicked regardless.
     * @return array{offer: LeasybackOffer, closed_count: int, already_selected: bool}
     */
    public function selectOffer(LeasybackOffer $offer, User $user, bool $onBehalfOfCustomer = false): array
    {
        try {
            $result = DB::transaction(fn () => $this->decide($offer, $user, $onBehalfOfCustomer));
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), OneSelectedOfferPerOrder::INDEX)) {
                throw $e;
            }

            // Lost the race on the index rather than on the in-transaction
            // re-read — the other request committed in the window between the
            // two. The outcome is decided by whichever offer actually won, not
            // by who arrived second.
            $result = $this->settleLostRace($offer);
        }

        // After the commit, and only for the request that really made the
        // decision: a replay must not send a second acceptance mail.
        if (! $result['already_selected']) {
            $this->notifyOfferSelected($result['offer']);
        }

        return $result;
    }

    /**
     * The decision itself, always inside a transaction.
     *
     * Reading the order's current decision here rather than before the
     * transaction is what makes the operation idempotent and safe at once: a
     * replay of the same decision finds it already made and writes nothing, a
     * different decision is refused, and both answers are derived from the
     * same locked read that guards the write.
     *
     * @return array{offer: LeasybackOffer, closed_count: int, already_selected: bool}
     */
    private function decide(LeasybackOffer $offer, User $user, bool $onBehalfOfCustomer): array
    {
        /** @var LeasybackOffer $locked */
        $locked = LeasybackOffer::whereKey($offer->getKey())->lockForUpdate()->firstOrFail();

        $decided = LeasybackOffer::where('order_id', $locked->order_id)
            ->where('offer_status', 'selected')
            ->lockForUpdate()
            ->first();

        if ($decided !== null) {
            return $this->replayOrConflict($decided, $locked);
        }

        if ($locked->offer_status !== 'published') {
            $this->fail(400, 'This offer is no longer available');
        }

        // B2B offers may carry a validity date (§10). Enforced here rather
        // than in the controller so the customer route and Admin's
        // accept-on-behalf route are both covered by the one rule. A B2C
        // offer has no presentation row, so expiredOn() is always null for it
        // and this guard cannot change B2C behaviour.
        //
        // Checked after the decision read on purpose: an offer that was
        // accepted while still valid stays accepted, so a replay arriving
        // after the validity date is answered from the decision above rather
        // than refused here.
        $expiredOn = $this->b2bOfferService->expiredOn($locked);

        if ($expiredOn !== null) {
            $this->fail(422, sprintf(
                'Dieses Angebot war bis zum %s gültig und kann nicht mehr freigegeben werden. Bitte fordern Sie ein neues Angebot an.',
                $expiredOn->format('d.m.Y'),
            ));
        }

        $closedCount = 0;
        $offer = $locked;

        $offer->update([
            'offer_status' => 'selected',
            'selected_at' => now(),
            'selected_by_user_id' => $user->id,
        ]);

        $this->auditOffer(
            $offer,
            $onBehalfOfCustomer ? 'selected_by_admin_on_behalf' : 'selected_by_customer',
            ['offer_status' => 'published'],
            ['offer_status' => 'selected'],
            $user->id,
        );

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

                // A sibling the customer had been shown is no longer on
                // the table. Announced as `offer.updated` for the same
                // reason a withdrawal is: the offer changed, it was not
                // decided.
                $this->b2bOfferService->announceOffer(
                    'updated',
                    LeasybackOffer::where('offer_id', $siblingId)->first(),
                );
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

        $this->b2bOfferService->announceOffer('accepted', $offer->fresh());

        return [
            'offer' => $offer->fresh() ?? $offer,
            'closed_count' => $closedCount,
            'already_selected' => false,
        ];
    }

    /**
     * An offer on this order is already selected. Whether that is a replay or
     * a conflict is decided by *which* offer it is, never by who is asking:
     * the customer double-clicking, the customer retrying after a dropped
     * response and an admin accepting the same offer on their behalf all
     * describe one decision that has already been made.
     *
     * @return array{offer: LeasybackOffer, closed_count: int, already_selected: bool}
     */
    private function replayOrConflict(LeasybackOffer $decided, LeasybackOffer $requested): array
    {
        if ($decided->getKey() === $requested->getKey()) {
            return ['offer' => $decided, 'closed_count' => 0, 'already_selected' => true];
        }

        $this->fail(409, sprintf(
            'Für diesen Auftrag wurde bereits Angebot %d angenommen. Eine Entscheidung kann nicht geändert werden.',
            $decided->offer_sequence,
        ));
    }

    /**
     * The index refused the write, so another request decided this order
     * first. Re-read who won and answer exactly as the in-transaction check
     * would have: the same offer is a replay, a different one is a conflict.
     *
     * @return array{offer: LeasybackOffer, closed_count: int, already_selected: bool}
     */
    private function settleLostRace(LeasybackOffer $requested): array
    {
        $decided = LeasybackOffer::where('order_id', $requested->order_id)
            ->where('offer_status', 'selected')
            ->first();

        if ($decided === null) {
            // The winner disappeared between the violation and this read —
            // no state to report, and retrying blindly could double-decide.
            $this->fail(409, 'Die Angebotsauswahl konnte nicht abgeschlossen werden. Bitte laden Sie die Seite neu.');
        }

        return $this->replayOrConflict($decided, $requested);
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

        $this->orderMailer->repairQuotationAvailable($offer);
    }

    /**
     * Best-effort, never breaks the selection if the send fails.
     */
    private function notifyOfferSelected(LeasybackOffer $offer): void
    {
        $this->orderMailer->repairApprovalConfirmed($offer);
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
