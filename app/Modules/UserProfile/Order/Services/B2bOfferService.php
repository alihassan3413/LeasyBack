<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\LeasybackOffer;
use App\Models\OfferAuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Models\WorkshopQuotationItem;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The customer-facing B2B repair offer (§10).
 *
 * `leasyback_offers` is reused as the offer record so publishing, selection,
 * the timeline stages and the audit trail keep working untouched; everything
 * B2B-specific lives on the 1:1 `b2b_offer_presentations` row.
 *
 * Two rules this file exists to enforce:
 * - the presented lines are **snapshotted** at publish, so §10's "Admin must
 *   always see exactly what was presented" survives later position edits;
 * - the service fee (§13) is never part of an offer, and gross amounts (§9)
 *   are never computed or stored here.
 */
class B2bOfferService
{
    public const STATUS_REJECTED = 'rejected';

    /**
     * @return array<string, mixed>
     */
    public static function createRules(): array
    {
        return [
            'workshop_quotation_id' => ['required', 'uuid'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rejectRules(): array
    {
        return [
            'customer_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Build a draft offer from a submitted workshop quotation. The customer
     * sees nothing until it is published.
     *
     * @param  array<string, mixed>  $validated
     */
    public function createFromQuotation(LeasybackOrder $order, Vehicle $vehicle, User $user, array $validated): LeasybackOffer
    {
        $this->assertB2b($vehicle);

        $quotation = WorkshopQuotation::where('id', $validated['workshop_quotation_id'])
            ->where('order_id', $order->id)
            ->first();

        if ($quotation === null || $quotation->status() !== 'submitted') {
            $this->fail(422, 'Nur eingegangene Werkstattangebote können als Kundenangebot verwendet werden.');
        }

        $lines = $this->buildLines($order->id, $quotation);
        $totals = $this->totals($lines);

        return DB::transaction(function () use ($order, $user, $validated, $quotation, $lines, $totals) {
            $sequence = (LeasybackOffer::where('order_id', $order->id)->max('offer_sequence') ?? 0) + 1;

            // LeasybackOffer::saving() derives final_total_net by summing all
            // four net columns, so exactly one of them may carry the B2B
            // repair total or it would be counted twice. Every other amount
            // is set to '0' explicitly rather than left null: the same hook
            // runs bcadd() over them before the DB defaults could apply.
            $offer = LeasybackOffer::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'offer_sequence' => $sequence,
                'offer_status' => 'draft',
                'repair_cost_net' => $totals['repair_total_net'],
                'depreciation_value_net' => '0',
                'workshop_repair_quote_net' => '0',
                'missing_parts_cost_net' => '0',
                'repair_cost_gross' => '0',
                'depreciation_value_gross' => '0',
                'workshop_repair_quote_gross' => '0',
                'missing_parts_cost_gross' => '0',
                'created_by_user_id' => $user->id,
            ]);

            B2bOfferPresentation::create([
                'offer_id' => $offer->offer_id,
                'order_id' => $order->id,
                'workshop_quotation_id' => $quotation->id,
                'lines' => $lines,
                ...$totals,
                'valid_until' => $validated['valid_until'] ?? null,
                'customer_note' => $this->trimToNull($validated['customer_note'] ?? null),
                'created_by_user_id' => $user->id,
            ]);

            return $offer;
        });
    }

    /**
     * Freeze what the customer is about to see. Called when the offer moves to
     * `published`; from here the snapshot is never rewritten, so correcting a
     * position afterwards cannot rewrite history.
     */
    public function snapshotOnPublish(LeasybackOffer $offer): void
    {
        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();

        if ($presentation === null || $presentation->presented_at !== null) {
            return;
        }

        $quotation = $presentation->workshop_quotation_id === null
            ? null
            : WorkshopQuotation::find($presentation->workshop_quotation_id);

        $lines = $quotation === null ? ($presentation->lines ?? []) : $this->buildLines($presentation->order_id, $quotation);

        $presentation->update([
            'lines' => $lines,
            ...$this->totals($lines),
            'presented_at' => now(),
        ]);
    }

    /**
     * Reject a published offer. Deliberately does **not** touch `order_status`:
     * OfferService already establishes that offer selection never moves the
     * order, and a rejection is the same kind of event. The order stays where
     * it is and OrderTaskResolver re-opens the offer task on its own.
     *
     * @param  array<string, mixed>  $validated
     */
    public function reject(LeasybackOffer $offer, User $user, array $validated): void
    {
        if ($offer->offer_status !== 'published') {
            $this->fail(400, 'Nur veröffentlichte Angebote können abgelehnt werden.');
        }

        DB::transaction(function () use ($offer, $user, $validated) {
            $locked = LeasybackOffer::whereKey($offer->offer_id)->lockForUpdate()->firstOrFail();

            if ($locked->offer_status !== 'published') {
                $this->fail(400, 'Nur veröffentlichte Angebote können abgelehnt werden.');
            }

            $locked->update(['offer_status' => self::STATUS_REJECTED]);

            B2bOfferPresentation::where('offer_id', $locked->offer_id)->update([
                'rejected_at' => now(),
                'rejected_by_user_id' => $user->id,
                'customer_comment' => $this->trimToNull($validated['customer_comment'] ?? null),
            ]);

            OfferAuditLog::create([
                'auftragsnummer' => $locked->auftragsnummer,
                'offer_id' => $locked->offer_id,
                'order_id' => $locked->order_id,
                'action' => 'rejected_by_customer',
                'old_values' => ['offer_status' => 'published'],
                'new_values' => ['offer_status' => self::STATUS_REJECTED],
                'changed_by_user_id' => $user->id,
            ]);
        });
    }

    /**
     * Offers still genuinely awaiting a customer decision and due a §18
     * reminder. Every stop condition is expressed here as an exclusion, so a
     * reminder can only be produced by an offer that is still actionable:
     *
     * - **accepted** → `offer_status` becomes `selected`, so the
     *   `published` filter drops it;
     * - **rejected** → becomes `rejected`, likewise dropped;
     * - **cancelled** → the offer's own `cancelled` status is dropped, and a
     *   cancelled/discarded *order* is excluded explicitly;
     * - **expired** → `valid_until` before today is excluded.
     *
     * Spacing: the first reminder falls due 24 h after the offer was
     * presented, and each later one 24 h after the previous send. Two runs in
     * the same day therefore cannot both send.
     *
     * @return array<int, LeasybackOffer>
     */
    public function offersDueForReminder(?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        $cutoff = $now->copy()->subDay();

        $presentations = B2bOfferPresentation::query()
            ->whereNotNull('presented_at')
            ->whereNull('rejected_at')
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $now->toDateString());
            })
            ->where(function ($query) use ($cutoff) {
                $query->where('last_reminder_sent_at', '<=', $cutoff)
                    ->orWhere(fn ($inner) => $inner->whereNull('last_reminder_sent_at')->where('presented_at', '<=', $cutoff));
            })
            ->get();

        if ($presentations->isEmpty()) {
            return [];
        }

        $offers = LeasybackOffer::whereIn('offer_id', $presentations->pluck('offer_id')->all())
            ->where('offer_status', 'published')
            ->get()
            ->keyBy('offer_id');

        $liveOrderIds = LeasybackOrder::whereIn('id', $offers->pluck('order_id')->unique()->all())
            ->whereNotIn('order_status', ['cancelled', 'discarded', 'completed'])
            ->pluck('id')
            ->all();

        return $offers
            ->filter(fn (LeasybackOffer $offer) => in_array($offer->order_id, $liveOrderIds, true))
            ->values()
            ->all();
    }

    /**
     * Recorded immediately after a send so a second run in the same 24 h
     * window cannot produce a duplicate.
     */
    public function markReminderSent(LeasybackOffer $offer, ?CarbonInterface $now = null): void
    {
        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();

        if ($presentation === null) {
            return;
        }

        $presentation->update([
            'last_reminder_sent_at' => $now ?? now(),
            'reminder_count' => ($presentation->reminder_count ?? 0) + 1,
        ]);
    }

    /**
     * The date an offer's validity ran out, or null when it is still
     * acceptable. A B2C offer has no presentation row and therefore never
     * expires through this path, so B2C acceptance is unaffected.
     */
    public function expiredOn(LeasybackOffer $offer): ?CarbonInterface
    {
        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();

        return $presentation !== null && $presentation->isExpired() ? $presentation->valid_until : null;
    }

    /**
     * The customer-visible shape. Carries no internal note, no service fee and
     * no gross amount — see the class docblock.
     *
     * @param  array<int, string>  $offerIds
     * @return array<string, array<string, mixed>>
     */
    public function forOffers(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        return B2bOfferPresentation::whereIn('offer_id', $offerIds)
            ->get()
            ->mapWithKeys(fn (B2bOfferPresentation $presentation) => [
                $presentation->offer_id => [
                    'workshop_quotation_id' => $presentation->workshop_quotation_id,
                    'lines' => $presentation->lines ?? [],
                    'appraisal_total_net' => (string) $presentation->appraisal_total_net,
                    'repair_total_net' => (string) $presentation->repair_total_net,
                    'saving_net' => (string) $presentation->saving_net,
                    'valid_until' => $presentation->valid_until?->toDateString(),
                    'is_expired' => $presentation->isExpired(),
                    'customer_note' => $presentation->customer_note,
                    'presented_at' => $presentation->presented_at?->toISOString(),
                    'rejected_at' => $presentation->rejected_at?->toISOString(),
                    'customer_comment' => $presentation->customer_comment,
                ],
            ])
            ->all();
    }

    /**
     * One line per appraisal position, priced from the quotation. Positions the
     * workshop marked not repairable are carried with a null repair amount so
     * the customer still sees them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLines(string $orderId, WorkshopQuotation $quotation): array
    {
        $items = WorkshopQuotationItem::where('quotation_id', $quotation->id)
            ->get()
            ->keyBy('appraisal_position_id');

        return AppraisalPosition::where('order_id', $orderId)
            ->orderBy('sort_order')
            ->get()
            ->map(function (AppraisalPosition $position) use ($items) {
                $item = $items->get($position->id);
                $appraisal = $position->effectiveAmountNet();
                $repair = $item?->not_repairable ? null : $item?->amount_net;

                return [
                    'appraisal_position_id' => $position->id,
                    'component' => $position->component,
                    'damage_description' => $position->damage_description,
                    'appraisal_amount_net' => $appraisal,
                    'repair_amount_net' => $repair === null ? null : (string) $repair,
                    'saving_net' => $repair === null ? null : bcsub($appraisal, (string) $repair, 2),
                    'repair_method' => $item?->repair_method ?? $position->repair_method,
                    'not_repairable' => (bool) ($item?->not_repairable ?? false),
                    'damage_image_document_ids' => $position->damage_image_document_ids ?? [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{appraisal_total_net: string, repair_total_net: string, saving_net: string}
     */
    private function totals(array $lines): array
    {
        $appraisal = '0';
        $repair = '0';

        foreach ($lines as $line) {
            $appraisal = bcadd($appraisal, (string) $line['appraisal_amount_net'], 2);

            if ($line['repair_amount_net'] !== null) {
                $repair = bcadd($repair, (string) $line['repair_amount_net'], 2);
            }
        }

        return [
            'appraisal_total_net' => $appraisal,
            'repair_total_net' => $repair,
            'saving_net' => bcsub($appraisal, $repair, 2),
        ];
    }

    private function assertB2b(Vehicle $vehicle): void
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            $this->fail(404, 'Not found');
        }
    }

    private function trimToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        return trim($value) === '' ? null : trim($value);
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
