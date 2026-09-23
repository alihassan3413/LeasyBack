<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Models\OfferAuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Models\WorkshopQuotationItem;
use App\Modules\UserProfile\Payment\Enums\FeeReason;
use App\Modules\UserProfile\Payment\Services\B2cFeeService;
use App\Support\OfferPricingPolicy;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The customer-facing repair offer, built from a workshop quotation (§10). Both
 * channels.
 *
 * `leasyback_offers` is reused as the offer record so publishing, selection,
 * the timeline stages and the audit trail keep working untouched; everything
 * the quotation-backed presentation adds lives on the 1:1
 * `b2b_offer_presentations` row.
 *
 * This was `B2bOfferService`, and it was doing three jobs at once: building an
 * offer from a quotation, presenting and expiring it, and announcing it to
 * partner webhooks. Only the third was ever channel-specific, and it now lives
 * in PartnerOfferAnnouncer. What is left is shared, which is why the name no
 * longer says B2B.
 *
 * Two rules this file exists to enforce:
 * - the presented lines, totals, VAT rate and workshop identity are
 *   **snapshotted** at publish, so §10's "Admin must always see exactly what
 *   was presented" survives later edits to positions, quotations or config;
 * - the service fee (§13) is never part of an offer, and whether a gross amount
 *   exists at all is OfferPricingPolicy's decision, not this class's.
 */
class RepairOfferService
{
    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        private readonly PartnerOfferAnnouncer $announcer,
        private readonly AdminOfferDecisionAnnouncer $adminAnnouncer,
    ) {}

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
     * The quotation is re-resolved here against `$order->id` rather than taken
     * on trust from the request, so an id belonging to another order — or to
     * another vehicle's order — finds nothing and is refused as unusable
     * rather than quietly pricing this customer's car from someone else's
     * quote.
     *
     * @param  array<string, mixed>  $validated
     */
    public function createFromQuotation(LeasybackOrder $order, User $user, array $validated): LeasybackOffer
    {
        $quotation = WorkshopQuotation::where('id', $validated['workshop_quotation_id'])
            ->where('order_id', $order->id)
            ->first();

        if ($quotation === null || $quotation->status() !== 'submitted') {
            $this->fail(422, 'Nur eingegangene Werkstattangebote können als Kundenangebot verwendet werden.');
        }

        // Offers belong to the offer phase. Before it there is no appraisal to
        // compare against; after it — or on a closed order — there is no
        // decision left for a customer to make.
        if (LeasybackOrder::whereKey($order->id)->value('order_status') !== OrderStatus::Inspected->value) {
            $this->fail(422, 'Ein Kundenangebot kann nur in der Angebotsphase (Status „Begutachtet") erstellt werden.');
        }

        if (LeasybackOffer::where('order_id', $order->id)->where('offer_status', 'selected')->exists()) {
            $this->fail(422, 'Für diesen Auftrag wurde bereits ein Angebot angenommen. Ein weiteres Angebot kann nicht erstellt werden.');
        }

        $vatRate = OfferPricingPolicy::rateFor(TransitionOrderStatus::isB2bOrder($order));
        $lines = $this->buildLines($order->id, $quotation);
        $totals = $this->totals($lines);

        // A workshop answering "cannot repair for this amount", or pricing
        // nothing, is a real answer but not an offer: it would only produce a
        // 0,00 € draft that publishOffer() must refuse anyway.
        if (bccomp($totals['repair_total_net'], '0', 2) <= 0) {
            $this->fail(422, 'Dieses Werkstattangebot enthält keine Preise und kann nicht als Kundenangebot verwendet werden.');
        }

        return DB::transaction(function () use ($order, $user, $validated, $quotation, $lines, $totals, $vatRate) {
            // Serialises two admins — or two clicks — racing on the same
            // quotation, so the check below cannot be passed twice.
            WorkshopQuotation::whereKey($quotation->id)->lockForUpdate()->first();

            $live = $this->liveOfferFromQuotation($quotation->id);

            if ($live !== null) {
                $this->fail(422, sprintf(
                    'Aus diesem Werkstattangebot wurde bereits Angebot %s erstellt. Verwerfen Sie es zuerst, wenn Sie es erneut übernehmen möchten.',
                    str_pad((string) $live->offer_sequence, 2, '0', STR_PAD_LEFT),
                ));
            }

            $sequence = (LeasybackOffer::where('order_id', $order->id)->max('offer_sequence') ?? 0) + 1;

            $offer = LeasybackOffer::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'offer_sequence' => $sequence,
                'offer_status' => 'draft',
                ...$this->offerAmounts($totals['repair_total_net'], $vatRate),
                'created_by_user_id' => $user->id,
            ]);

            B2bOfferPresentation::create([
                'offer_id' => $offer->offer_id,
                'order_id' => $order->id,
                'workshop_quotation_id' => $quotation->id,
                'lines' => $lines,
                ...$totals,
                'vat_rate' => $vatRate,
                'workshop' => $this->workshopSnapshot($quotation),
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
     * position, a quotation or the configured VAT rate afterwards cannot
     * rewrite history.
     *
     * The re-derivation on this one pass is deliberate: between drafting and
     * publishing, an admin may still correct the positions, and publishing is
     * the moment those corrections stop counting.
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
        $totals = $this->totals($lines);

        $presentation->update([
            'lines' => $lines,
            ...$totals,
            'workshop' => $quotation === null ? $presentation->workshop : $this->workshopSnapshot($quotation),
            'presented_at' => now(),
        ]);

        // The offer's own headline amounts are re-derived from the frozen
        // totals for the same reason, and through the presentation's stored
        // rate rather than today's config.
        $offer->update($this->offerAmounts($totals['repair_total_net'], $presentation->vat_rate === null ? null : (string) $presentation->vat_rate));
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

    $rejectedOffer = DB::transaction(function () use ($offer, $user, $validated) {
        $locked = LeasybackOffer::whereKey($offer->offer_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->offer_status !== 'published') {
            $this->fail(400, 'Nur veröffentlichte Angebote können abgelehnt werden.');
        }

        $orderStatus = LeasybackOrder::whereKey($locked->order_id)
            ->value('order_status');

        if ($orderStatus === null || in_array($orderStatus, OrderStatus::closedValues(), true)) {
            $this->fail(422, 'Der Auftrag ist bereits abgeschlossen oder storniert.');
        }

        $expiredOn = $this->expiredOn($locked);

        if ($expiredOn !== null) {
            $this->fail(422, sprintf(
                'Dieses Angebot war bis zum %s gültig.',
                $expiredOn->format('d.m.Y'),
            ));
        }

        $locked->update([
            'offer_status' => self::STATUS_REJECTED,
        ]);

        B2bOfferPresentation::where('offer_id', $locked->offer_id)
            ->update([
                'rejected_at' => now(),
                'rejected_by_user_id' => $user->id,
                'customer_comment' => $this->trimToNull($validated['customer_comment'] ?? null),
            ]);

        OfferAuditLog::create([
            'auftragsnummer' => $locked->auftragsnummer,
            'offer_id' => $locked->offer_id,
            'order_id' => $locked->order_id,
            'action' => 'rejected_by_customer',
            'old_values' => [
                'offer_status' => 'published',
            ],
            'new_values' => [
                'offer_status' => self::STATUS_REJECTED,
            ],
            'changed_by_user_id' => $user->id,
        ]);

        return $locked->fresh();
    });

    // Notifications AFTER commit
    $this->announcer->announce('rejected', $rejectedOffer);

    $this->adminAnnouncer->rejected(
        $rejectedOffer,
        $validated['customer_comment'] ?? null
    );

    $order = LeasybackOrder::find($offer->order_id);

    if ($order !== null && ! TransitionOrderStatus::isB2bOrder($order)) {
        app(B2cFeeService::class)->trigger(
            $order,
            FeeReason::RepairOfferRejected,
            [
                'offer_id' => $offer->offer_id,
                'rejected_at' => now()->toIso8601String(),
            ]
        );
    }
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

        // B2B only (§10). Quotation-backed offers now exist in both channels,
        // and a private customer's offer follows the B2C communication, which
        // has no 24 h reminder.
        $liveOrderIds = LeasybackOrder::whereIn('id', $offers->pluck('order_id')->unique()->all())
            ->whereNotIn('order_status', ['cancelled', 'discarded', 'completed'])
            ->whereIn('vehicle_id', Vehicle::query()->where('vehicle_belongs', 'B2B')->select('vehicle_id'))
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
     * acceptable. A manually created offer has no presentation row and
     * therefore never expires through this path.
     */
    public function expiredOn(LeasybackOffer $offer): ?CarbonInterface
    {
        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();

        return $presentation !== null && $presentation->isExpired() ? $presentation->valid_until : null;
    }

    /**
     * The customer-visible shape. Carries no internal note and no service fee.
     *
     * Gross amounts appear only where the presentation carries a rate, which is
     * OfferPricingPolicy's decision recorded at creation — so a B2B payload has
     * no gross keys at all rather than gross keys holding null, and cannot
     * acquire any later.
     *
     * The workshop is reduced to its company name. A customer benefits from
     * knowing who will do the work; the contact person, email and phone are
     * operational data and stay in the Admin payload.
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
                    'workshop_name' => $presentation->workshopName(),
                    'lines' => $this->presentedLines($presentation),
                    'appraisal_total_net' => (string) $presentation->appraisal_total_net,
                    'repair_total_net' => (string) $presentation->repair_total_net,
                    'saving_net' => (string) $presentation->saving_net,
                    ...$this->grossTotals($presentation),
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
     * The workshop as Admin needs it — the full contact snapshot, so the order
     * page can show who is to be commissioned without re-reading a quotation
     * that may since have been revoked.
     *
     * @param  array<int, string>  $offerIds
     * @return array<string, array<string, mixed>|null>
     */
    public function workshopsForOffers(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        return B2bOfferPresentation::whereIn('offer_id', $offerIds)
            ->get()
            ->mapWithKeys(fn (B2bOfferPresentation $presentation) => [
                $presentation->offer_id => $presentation->workshop,
            ])
            ->all();
    }

    /**
     * The four net/gross column pairs on the offer row itself.
     *
     * LeasybackOffer::saving() derives final_total_net/gross by summing all
     * four pairs, so exactly one of them may carry the repair total or it would
     * be counted twice. Every other amount is set to '0' explicitly rather than
     * left null: the same hook runs bcadd() over them before the DB defaults
     * could apply.
     *
     * @return array<string, string>
     */
    private function offerAmounts(string $repairTotalNet, ?string $vatRate): array
    {
        return [
            'repair_cost_net' => $repairTotalNet,
            'repair_cost_gross' => OfferPricingPolicy::gross($repairTotalNet, $vatRate) ?? '0',
            'depreciation_value_net' => '0',
            'workshop_repair_quote_net' => '0',
            'missing_parts_cost_net' => '0',
            'depreciation_value_gross' => '0',
            'workshop_repair_quote_gross' => '0',
            'missing_parts_cost_gross' => '0',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function grossTotals(B2bOfferPresentation $presentation): array
    {
        $rate = $presentation->vat_rate === null ? null : (string) $presentation->vat_rate;

        if ($rate === null) {
            return [];
        }

        return [
            'vat_rate' => $rate,
            'appraisal_total_gross' => OfferPricingPolicy::gross((string) $presentation->appraisal_total_net, $rate),
            'repair_total_gross' => OfferPricingPolicy::gross((string) $presentation->repair_total_net, $rate),
            'saving_gross' => OfferPricingPolicy::gross((string) $presentation->saving_net, $rate),
        ];
    }

    /**
     * The frozen lines, with a gross amount added per line where the channel
     * shows gross. Derived from the row's own stored rate, never from config,
     * so a published offer's lines cannot change value.
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentedLines(B2bOfferPresentation $presentation): array
    {
        $rate = $presentation->vat_rate === null ? null : (string) $presentation->vat_rate;
        $lines = $presentation->lines ?? [];

        if ($rate === null) {
            return $lines;
        }

        return array_map(fn (array $line) => [
            ...$line,
            'appraisal_amount_gross' => OfferPricingPolicy::gross($line['appraisal_amount_net'] ?? null, $rate),
            'repair_amount_gross' => OfferPricingPolicy::gross($line['repair_amount_net'] ?? null, $rate),
            'saving_gross' => OfferPricingPolicy::gross($line['saving_net'] ?? null, $rate),
        ], $lines);
    }

    /**
     * Who quoted, as of now. Copied onto the presentation so the answer survives
     * the quotation row changing or being deleted.
     *
     * @return array<string, mixed>
     */
    private function workshopSnapshot(WorkshopQuotation $quotation): array
    {
        return [
            'quotation_id' => $quotation->id,
            'label' => $quotation->workshop_label,
            'company_name' => $quotation->company_name,
            'contact_person' => $quotation->contact_person,
            'contact_email' => $quotation->contact_email,
            'contact_phone' => $quotation->contact_phone,
            'earliest_repair_start' => $quotation->earliest_repair_start?->toDateString(),
            'processing_days' => $quotation->processing_days,
        ];
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

        $saving = '0';

        foreach ($lines as $line) {
            $appraisal = bcadd($appraisal, (string) $line['appraisal_amount_net'], 2);

            if ($line['repair_amount_net'] !== null) {
                $repair = bcadd($repair, (string) $line['repair_amount_net'], 2);
                $saving = bcadd($saving, bcsub((string) $line['appraisal_amount_net'], (string) $line['repair_amount_net'], 2), 2);
            }
        }

        // The saving only counts positions the workshop actually priced. A
        // position left unquoted or marked not repairable is still charged at
        // its appraisal amount, so it saves nothing — subtracting the repair
        // total from the *whole* appraisal used to count it as saved in full.
        return [
            'appraisal_total_net' => $appraisal,
            'repair_total_net' => $repair,
            'saving_net' => $saving,
        ];
    }

    private function trimToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        return trim($value) === '' ? null : trim($value);
    }

    /**
     * The customer offer this quotation already produced, if it is still one
     * an admin or a customer could act on.
     *
     * A discarded offer deliberately does not count: verwerfen is how a draft
     * built from the wrong quotation is undone, and taking the quotation again
     * afterwards is the whole point of undoing it.
     */
    private function liveOfferFromQuotation(string $quotationId): ?LeasybackOffer
    {
        return LeasybackOffer::query()
            ->whereIn('offer_status', ['draft', 'published', 'selected'])
            ->whereIn(
                'offer_id',
                B2bOfferPresentation::query()->where('workshop_quotation_id', $quotationId)->select('offer_id'),
            )
            ->orderBy('offer_sequence')
            ->first();
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
