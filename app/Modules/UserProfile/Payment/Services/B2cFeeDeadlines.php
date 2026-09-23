<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Enums\OrderStatus;
use App\Models\LeasybackOrder as OrderRecord;
use App\Modules\UserProfile\Payment\Enums\FeeReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The two delayed fee triggers, expressed as queries over existing state.
 *
 * Both are derived on every run rather than scheduled ahead: a customer who
 * accepts, rejects or proceeds simply stops matching, so nothing has to be
 * cancelled and a repeated run cannot fire a timer twice.
 */
class B2cFeeDeadlines
{
    public const DAYS = 14;

    public function __construct(private readonly B2cFeeService $fees) {}

    public function process(?CarbonImmutable $now = null): int
    {
        $now = $now ?? CarbonImmutable::now();
        $triggered = 0;

        foreach ($this->offersWithoutResponse($now) as $row) {
            $order = OrderRecord::find($row->order_id);

            if ($order === null) {
                continue;
            }

            $fee = $this->fees->trigger($order, FeeReason::RepairOfferNoResponse, [
                'offer_id' => $row->offer_id,
                'offer_sent_at' => (string) $row->published_at,
                'deadline_days' => self::DAYS,
            ]);

            $triggered += $fee !== null ? 1 : 0;
        }

        foreach ($this->stalledRepairs($now) as $row) {
            $order = OrderRecord::find($row->order_id);

            if ($order === null) {
                continue;
            }

            $fee = $this->fees->trigger($order, FeeReason::RepairInactivity, [
                'missed_workshop_appointment' => (string) $row->confirmed_repair_start_date,
                'last_customer_contact_at' => $row->last_contact_at === null ? null : (string) $row->last_contact_at,
                'deadline_days' => self::DAYS,
            ]);

            $triggered += $fee !== null ? 1 : 0;
        }

        return $triggered;
    }

    /**
     * A published B2C offer whose response window has run out.
     *
     * Acceptance moves the offer to `selected` and rejection to `rejected`, so
     * either decision drops the row out of this query — the timer needs no
     * separate cancellation.
     *
     * @return Collection<int, object>
     */
    public function offersWithoutResponse(CarbonImmutable $now): Collection
    {
        $cutoff = $now->subDays(self::DAYS);

        return DB::table('leasyback_offers as o')
            ->join('leasyback_orders as lo', 'lo.id', '=', 'o.order_id')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'lo.vehicle_id')
            ->leftJoin('order_payments as p', function ($join) {
                $join->on('p.order_id', '=', 'lo.id')->where('p.purpose', '=', 'cancellation_fee');
            })
            ->where('v.vehicle_belongs', '!=', 'B2B')
            ->where('o.offer_status', 'published')
            ->whereNotNull('o.published_at')
            ->where('o.published_at', '<=', $cutoff)
            ->whereNotIn('lo.order_status', OrderStatus::closedValues())
            ->whereNull('p.id')
            ->select('lo.id as order_id', 'o.offer_id', 'o.published_at')
            ->get();
    }

    /**
     * An accepted repair that never started, and where the customer has since
     * gone quiet.
     *
     * The window runs from the missed workshop appointment — the agreed start
     * date, passed without the order moving to `workshop` — and is pushed back
     * by anything the customer says on the order thread afterwards. That is the
     * concrete "responded" event the domain already records; an admin's own
     * message does not reset it, or chasing a silent customer would keep the
     * timer alive forever.
     *
     * @return Collection<int, object>
     */
    public function stalledRepairs(CarbonImmutable $now): Collection
    {
        $cutoff = $now->subDays(self::DAYS);

        $lastCustomerContact = DB::table('order_messages')
            ->select('order_id', DB::raw('MAX(created_at) as last_contact_at'))
            ->where('sender_is_admin', false)
            ->groupBy('order_id');

        return DB::table('leasyback_order_logistics as l')
            ->join('leasyback_orders as lo', 'lo.auftragsnummer', '=', 'l.auftragsnummer')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'lo.vehicle_id')
            ->leftJoinSub($lastCustomerContact, 'm', 'm.order_id', '=', 'lo.id')
            ->leftJoin('order_payments as p', function ($join) {
                $join->on('p.order_id', '=', 'lo.id')->where('p.purpose', '=', 'cancellation_fee');
            })
            ->where('v.vehicle_belongs', '!=', 'B2B')
            ->whereNotNull('l.confirmed_repair_start_date')
            ->where('l.confirmed_repair_start_date', '<=', $cutoff->toDateString())
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('m.last_contact_at')->orWhere('m.last_contact_at', '<=', $cutoff);
            })
            ->where('lo.order_status', OrderStatus::WorkshopCommissioned->value)
            ->whereNull('p.id')
            ->select('lo.id as order_id', 'l.confirmed_repair_start_date', 'm.last_contact_at')
            ->get();
    }
}
