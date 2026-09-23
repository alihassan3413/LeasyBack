<?php

namespace App\Modules\UserProfile\Admin\Services;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\AppraisalPositionService;
use App\Modules\UserProfile\Order\Services\B2bBillingService;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use App\Modules\UserProfile\Order\Services\RepairOfferService;
use App\Modules\UserProfile\Order\Services\WorkshopCommissionService;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Support\PortalTimestamp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loads the order data OrderTaskResolver needs, for many orders at once.
 *
 * This is a *loading* class and nothing else. It decides no task, ranks no
 * priority and reads no status: it fills the same array shape
 * AdminQueryService::orderDetail() produces, and hands it to the same
 * resolvers. Every question about what the next task is stays where it was.
 *
 * ── Why it exists ─────────────────────────────────────────────────────────
 * orderDetail() answers for one order and fans out to six per-order services,
 * costing ~22 queries each. Resolving every active order that way is 22N —
 * fine for a handful, hopeless for a portal. Each of those services now has a
 * batch sibling that shares its presenter with the single-order method, so
 * this assembles the same values in a fixed number of queries however many
 * orders are asked about.
 *
 * ── Scope of the payload ──────────────────────────────────────────────────
 * Only the keys the task resolvers read are filled. The order *page* needs
 * more (transitions, notes, the Lexware invoice, the quotation comparison
 * matrix); loading those here would mean fetching every appraisal position in
 * the portal to answer a question nobody asked. AdminOpenTaskTest asserts the
 * tasks this produces are identical to orderDetail()'s across a matrix of
 * order states — that equivalence, not the key list, is what keeps the two
 * paths honest.
 */
class OrderTaskHydrator
{
    public function __construct(
        private readonly AdminQueryService $admin,
        private readonly OrderCollectionService $collections,
        private readonly WorkshopCommissionService $commissions,
        private readonly AppraisalPositionService $positions,
        private readonly WorkshopQuotationService $quotations,
        private readonly B2bBillingService $billing,
        private readonly RepairOfferService $offers,
    ) {}

    /**
     * Every order still in flight, hydrated for the task resolvers.
     *
     * Oldest first: a timed rule escalates with age, so this is the order the
     * work actually queues in.
     *
     * @return list<array<string, mixed>>
     */
    public function forActiveOrders(): array
    {
        $rows = DB::table('leasyback_orders as o')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')
            ->whereIn('o.order_status', OrderStatus::activeValues())
            ->orderBy('o.created_at')
            ->select([
                'o.id', 'o.vehicle_id', 'o.auftragsnummer', 'o.leasyback_partner',
                'o.order_status', 'o.sent_at', 'o.created_at', 'o.response_status', 'o.response_body',
                'v.license_plate', 'v.vin', 'v.make', 'v.model',
                'v.b2c_user_id', 'v.b2b_id', 'v.vehicle_belongs',
            ])
            ->get();

        return $this->hydrate($rows);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function hydrate($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        // Base shape, batched already: customer, confirmation date, documents.
        $orders = collect($this->admin->enrichOrders($rows))->keyBy('id');

        $orderIds = $rows->pluck('id')->all();
        $auftragsnummern = $rows->pluck('auftragsnummer')->filter()->unique()->values()->all();
        $b2bIds = $rows->where('vehicle_belongs', 'B2B')->pluck('id')->all();

        $offersByOrder = $this->offersByOrder($orderIds);
        $statusUpdates = $this->statusUpdatesByOrderNumber($auftragsnummern);
        $lastContact = $this->lastCustomerContactByOrder($orderIds);
        $collections = $this->collections->forOrders($auftragsnummern, true);
        $positions = $this->positions->forOrders($orderIds);
        $quotations = $this->quotations->statesForOrders($orderIds);
        $billing = $this->billing->forOrders($b2bIds);
        $payments = $this->admin->paymentSummaries($orderIds);
        $commissions = $this->commissions->statesFor(LeasybackOrder::whereIn('id', $orderIds)->get());

        $hydrated = [];

        foreach ($rows as $row) {
            $order = $orders->get($row->id);

            if ($order === null) {
                continue;
            }

            $isB2b = $row->vehicle_belongs === 'B2B';

            $order['vehicle_belongs'] = $row->vehicle_belongs;
            $order['offers'] = $offersByOrder[$row->id] ?? [];
            $order['status_updates'] = $statusUpdates[$row->auftragsnummer] ?? [];
            $order['collection'] = $collections[$row->auftragsnummer] ?? null;
            $order['workshop_commission'] = $commissions[$row->id] ?? [];
            $order['appraisal_positions'] = $positions[$row->id] ?? [];
            $order['workshop_quotations'] = $quotations[$row->id] ?? [];
            $order['billing'] = $isB2b ? ($billing[$row->id] ?? null) : null;
            // Both payment kinds are B2C-only, exactly as orderDetail has it:
            // a company is invoiced, never charged a card.
            $order['repair_payment'] = $isB2b ? null : ($payments[$row->id][PaymentPurpose::Repair->value] ?? null);
            $order['cancellation_fee'] = $isB2b ? null : ($payments[$row->id][PaymentPurpose::CancellationFee->value] ?? null);
            $order['last_customer_contact_at'] = $lastContact[$row->id] ?? null;

            $hydrated[] = $order;
        }

        return $hydrated;
    }

    /**
     * Offers with their presentation and workshop attached, as the order page
     * attaches them — `presentation` being null is how a manual offer is told
     * from a quotation-backed one, and the task tree reads `is_expired` off it.
     *
     * @param  array<int, string>  $orderIds
     * @return array<string, list<object>>
     */
    private function offersByOrder(array $orderIds): array
    {
        $rows = DB::table('leasyback_offers')
            ->whereIn('order_id', $orderIds)
            ->orderBy('offer_sequence')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $offerIds = $rows->pluck('offer_id')->all();
        $presentations = $this->offers->forOffers($offerIds);
        $workshops = $this->offers->workshopsForOffers($offerIds);

        $byOrder = [];

        foreach ($rows as $offer) {
            $offer->presentation = $presentations[$offer->offer_id] ?? null;
            $offer->workshop = $workshops[$offer->offer_id] ?? null;
            $offer->published_at = PortalTimestamp::iso($offer->published_at);
            $offer->selected_at = PortalTimestamp::iso($offer->selected_at);

            $byOrder[$offer->order_id][] = $offer;
        }

        return $byOrder;
    }

    /**
     * @param  array<int, string>  $auftragsnummern
     * @return array<string, list<array<string, mixed>>>
     */
    private function statusUpdatesByOrderNumber(array $auftragsnummern): array
    {
        $rows = DB::table('leasyback_order_status_updates')
            ->whereIn('auftragsnummer', $auftragsnummern)
            ->orderByDesc('created_at')
            ->get();

        $byNumber = [];

        foreach (PortalTimestamp::normalizeRows($rows, ['created_at']) as $update) {
            $update = (object) $update;
            $byNumber[$update->auftragsnummer][] = $update;
        }

        return $byNumber;
    }

    /**
     * When each order last heard from its customer — the fact the detached
     * follow-ups use to decide whether a call is still owed.
     *
     * @param  array<int, string>  $orderIds
     * @return array<string, string|null>
     */
    private function lastCustomerContactByOrder(array $orderIds): array
    {
        return DB::table('order_messages')
            ->whereIn('order_id', $orderIds)
            ->where('sender_is_admin', false)
            ->groupBy('order_id')
            ->pluck(DB::raw('max(created_at)'), 'order_id')
            ->map(fn ($at) => PortalTimestamp::iso($at))
            ->all();
    }
}
