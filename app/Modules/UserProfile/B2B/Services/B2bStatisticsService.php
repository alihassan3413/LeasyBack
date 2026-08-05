<?php

namespace App\Modules\UserProfile\B2B\Services;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use App\Support\OrderStatusLabel;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Company-level B2B return statistics (b2b.txt §17) and the underlying rows
 * the Excel export is built from.
 *
 * Three rules the whole file is arranged around:
 *
 * 1. **Saving = chargeable appraisal − accepted repair.** Both numbers are read
 *    from the `b2b_offer_presentations` snapshot of the offer the customer
 *    actually accepted, never from the live `b2b_appraisal_positions` rows.
 *    Positions stay editable after publication by design (§8), so recomputing
 *    from them would let a later correction silently rewrite a figure the
 *    customer was already shown.
 * 2. **The final appraisal never enters the calculation.** It is structurally
 *    impossible here: a Nachgutachten is a document in `vehicle_report_documents`
 *    with no amounts, and `b2b_appraisal_positions` holds initial-appraisal
 *    positions only (see AppraisalPosition's docblock). Nothing this service
 *    reads can carry a final-appraisal amount.
 * 3. **Company scoping is a query fact, not a filter the caller may forget.**
 *    Every read starts from scopedOrders(), which joins `vehicles` and pins
 *    both `vehicle_belongs = 'B2B'` and the company id. The channel therefore
 *    comes from the persisted vehicle, never from the request.
 *
 * All amounts are net (§9). No authorization decision is made here — the caller
 * has already established the actor may view analytics for this company.
 */
class B2bStatisticsService
{
    /** How many months of history the volume chart covers, including the current one. */
    private const VOLUME_MONTHS = 12;

    /** The offer status that means the customer authorised the repair (OfferService::selectOffer). */
    private const ACCEPTED_OFFER_STATUS = 'selected';

    /**
     * @return array{
     *     orders: array<string, int>,
     *     savings: array<string, string|int|null>,
     *     processing_time: array{average_days: float|null, measured_orders: int},
     *     status_distribution: list<array{status: string, label: string, count: int}>,
     *     monthly_volume: list<array{month: string, label: string, count: int}>,
     *     scope: array{company_wide: bool}
     * }
     */
    public function summary(B2bMembership $membership, int $userId): array
    {
        $statusCounts = $this->statusCounts($membership, $userId);
        $accepted = $this->acceptedOfferRows($membership, $userId);

        return [
            'orders' => $this->orderTotals($statusCounts),
            'savings' => $this->savings($accepted),
            'processing_time' => $this->processingTime($membership, $userId),
            'status_distribution' => $this->statusDistribution($statusCounts),
            'monthly_volume' => $this->monthlyVolume($membership, $userId),
            'scope' => ['company_wide' => ! $membership->seesOwnVehiclesOnly()],
        ];
    }

    /**
     * One row per order the caller may see, carrying the same figures the
     * statistics are aggregated from. Deliberately free of internal notes (§16)
     * and of any gross amount (§9).
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(B2bMembership $membership, int $userId): array
    {
        $accepted = $this->acceptedOfferRows($membership, $userId);
        $acceptedByOrder = [];

        foreach ($accepted as $row) {
            $acceptedByOrder[$row->order_id] = $row;
        }

        $completedAt = $this->completionTimestamps($membership, $userId);

        return $this->scopedOrders($membership, $userId)
            ->orderByDesc('lo.created_at')
            ->get([
                'lo.id',
                'lo.auftragsnummer',
                'lo.order_status',
                'lo.created_at',
                'v.license_plate',
                'v.vin',
                'v.make',
                'v.model',
                'v.contract_number',
                'v.cost_centre',
                'v.leasinggeber',
            ])
            ->map(function (object $order) use ($acceptedByOrder, $completedAt) {
                $offer = $acceptedByOrder[$order->id] ?? null;
                $completed = $completedAt[$order->id] ?? null;
                $createdAt = $this->toDate($order->created_at);

                return [
                    'auftragsnummer' => (string) $order->auftragsnummer,
                    'license_plate' => (string) ($order->license_plate ?? ''),
                    'vin' => (string) ($order->vin ?? ''),
                    'make' => (string) ($order->make ?? ''),
                    'model' => (string) ($order->model ?? ''),
                    'leasinggeber' => (string) ($order->leasinggeber ?? ''),
                    'contract_number' => (string) ($order->contract_number ?? ''),
                    'cost_centre' => (string) ($order->cost_centre ?? ''),
                    'order_status' => (string) $order->order_status,
                    'order_status_label' => OrderStatusLabel::for($order->order_status),
                    'created_at' => $createdAt?->toDateString(),
                    'completed_at' => $completed?->toDateString(),
                    'processing_days' => $createdAt !== null && $completed !== null
                        ? (int) $createdAt->diffInDays($completed)
                        : null,
                    'appraisal_total_net' => $offer === null ? null : (float) $offer->appraisal_total_net,
                    'repair_total_net' => $offer === null ? null : (float) $offer->repair_total_net,
                    'saving_net' => $offer === null ? null : (float) $offer->saving_net,
                    'accepted_at' => $offer === null ? null : $this->toDate($offer->selected_at)?->toDateString(),
                ];
            })
            ->all();
    }

    /**
     * The scoping boundary every read in this class goes through.
     *
     * `vehicle_belongs = 'B2B'` is what keeps B2C out: a B2C vehicle has no
     * `b2b_id`, so it could never match anyway, but pinning the channel makes
     * that a stated rule rather than a side effect of the data.
     *
     * A member whose vehicle scope is `own` sees exactly the vehicles they
     * created, the same narrowing VehicleScopeService::scopeQuery() applies to
     * the dashboard — so the export can never hand them a row the fleet table
     * would have hidden.
     */
    private function scopedOrders(B2bMembership $membership, int $userId): Builder
    {
        $query = DB::table('leasyback_orders as lo')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'lo.vehicle_id')
            ->where('v.vehicle_belongs', 'B2B')
            ->where('v.b2b_id', $membership->b2bId);

        if ($membership->seesOwnVehiclesOnly()) {
            $query->where('v.created_by_user_id', $userId);
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(B2bMembership $membership, int $userId): array
    {
        return $this->scopedOrders($membership, $userId)
            ->select('lo.order_status', DB::raw('count(*) as total'))
            ->groupBy('lo.order_status')
            ->pluck('total', 'order_status')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @param  array<string, int>  $statusCounts
     * @return array<string, int>
     */
    private function orderTotals(array $statusCounts): array
    {
        $active = 0;
        $completed = 0;
        $cancelled = 0;

        foreach ($statusCounts as $status => $count) {
            if (in_array($status, OrderStatus::completedValues(), true)) {
                $completed += $count;
            } elseif (in_array($status, $this->cancelledValues(), true)) {
                $cancelled += $count;
            } else {
                $active += $count;
            }
        }

        return [
            'active' => $active,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'total' => $active + $completed + $cancelled,
        ];
    }

    /**
     * The orders that contribute to the saving figures: those where the
     * customer accepted a presented offer, and the order was not later
     * cancelled or discarded.
     *
     * Everything else contributes nothing at all — not a zero. An order with no
     * offer yet, a rejected offer or an expired one has no "accepted repair
     * amount", so §17's subtraction is undefined for it; counting it as a zero
     * saving would drag the average down with orders that were never in scope.
     * A cancelled order is dropped even if an offer had been accepted, because
     * the repair it priced never happened.
     *
     * @return list<object>
     */
    private function acceptedOfferRows(B2bMembership $membership, int $userId): array
    {
        return $this->scopedOrders($membership, $userId)
            ->join('leasyback_offers as o', 'o.order_id', '=', 'lo.id')
            ->join('b2b_offer_presentations as p', 'p.offer_id', '=', 'o.offer_id')
            ->where('o.offer_status', self::ACCEPTED_OFFER_STATUS)
            ->whereNotNull('p.presented_at')
            ->whereNotIn('lo.order_status', $this->cancelledValues())
            ->get([
                'lo.id as order_id',
                'lo.vehicle_id',
                'o.selected_at',
                'p.appraisal_total_net',
                'p.repair_total_net',
                'p.saving_net',
            ])
            ->all();
    }

    /**
     * @param  list<object>  $accepted
     * @return array<string, string|int|null>
     */
    private function savings(array $accepted): array
    {
        $appraisal = '0.00';
        $repair = '0.00';
        $saving = '0.00';
        $vehicleIds = [];

        foreach ($accepted as $row) {
            $appraisal = bcadd($appraisal, (string) $row->appraisal_total_net, 2);
            $repair = bcadd($repair, (string) $row->repair_total_net, 2);
            $saving = bcadd($saving, (string) $row->saving_net, 2);
            $vehicleIds[(string) $row->vehicle_id] = true;
        }

        $vehicles = count($vehicleIds);

        return [
            'orders_counted' => count($accepted),
            'vehicles_counted' => $vehicles,
            'appraisal_total_net' => $appraisal,
            'repair_total_net' => $repair,
            'saving_total_net' => $saving,
            'average_saving_per_vehicle_net' => $vehicles === 0 ? null : bcdiv($saving, (string) $vehicles, 2),
            'saving_percentage' => bccomp($appraisal, '0.00', 2) === 0
                ? null
                : bcdiv(bcmul($saving, '100', 4), $appraisal, 2),
        ];
    }

    /**
     * Average calendar days from an order being created to it first reaching a
     * completed status. Orders that have not completed are simply not measured,
     * rather than counted as "0 days so far" — an in-flight order has no
     * processing time yet, and averaging one in would understate the figure.
     *
     * @return array{average_days: float|null, measured_orders: int}
     */
    private function processingTime(B2bMembership $membership, int $userId): array
    {
        $completedAt = $this->completionTimestamps($membership, $userId);

        if ($completedAt === []) {
            return ['average_days' => null, 'measured_orders' => 0];
        }

        $createdAt = $this->scopedOrders($membership, $userId)
            ->whereIn('lo.id', array_keys($completedAt))
            ->pluck('lo.created_at', 'lo.id');

        $totalDays = 0.0;
        $measured = 0;

        foreach ($completedAt as $orderId => $completed) {
            $created = $this->toDate($createdAt[$orderId] ?? null);

            if ($created === null || $completed->lt($created)) {
                continue;
            }

            $totalDays += $created->diffInDays($completed);
            $measured++;
        }

        return [
            'average_days' => $measured === 0 ? null : round($totalDays / $measured, 1),
            'measured_orders' => $measured,
        ];
    }

    /**
     * When each completed order first reached a completed status, taken from
     * the audit trail rather than `updated_at` — a later edit moves
     * `updated_at` and would quietly stretch the processing time.
     *
     * @return array<string, Carbon>
     */
    private function completionTimestamps(B2bMembership $membership, int $userId): array
    {
        $rows = $this->scopedOrders($membership, $userId)
            ->join('leasyback_order_status_updates as su', 'su.auftragsnummer', '=', 'lo.auftragsnummer')
            ->whereIn('su.new_status', OrderStatus::completedValues())
            ->groupBy('lo.id')
            ->select('lo.id', DB::raw('min(su.created_at) as completed_at'))
            ->get();

        $timestamps = [];

        foreach ($rows as $row) {
            $completed = $this->toDate($row->completed_at);

            if ($completed !== null) {
                $timestamps[(string) $row->id] = $completed;
            }
        }

        return $timestamps;
    }

    /**
     * @param  array<string, int>  $statusCounts
     * @return list<array{status: string, label: string, count: int}>
     */
    private function statusDistribution(array $statusCounts): array
    {
        $distribution = [];

        foreach (OrderStatus::values() as $status) {
            $count = $statusCounts[$status] ?? 0;

            if ($count > 0) {
                $distribution[] = [
                    'status' => $status,
                    'label' => OrderStatusLabel::for($status),
                    'count' => $count,
                ];
            }
        }

        return $distribution;
    }

    /**
     * Orders created per month over the last year, bucketed in PHP rather than
     * with a database date function so SQLite and Postgres produce identical
     * buckets. Months with no orders are kept so the chart keeps an even axis.
     *
     * @return list<array{month: string, label: string, count: int}>
     */
    private function monthlyVolume(B2bMembership $membership, int $userId): array
    {
        $start = now()->startOfMonth()->subMonths(self::VOLUME_MONTHS - 1);

        $buckets = [];

        for ($offset = 0; $offset < self::VOLUME_MONTHS; $offset++) {
            $month = $start->copy()->addMonths($offset);
            $buckets[$month->format('Y-m')] = 0;
        }

        $created = $this->scopedOrders($membership, $userId)
            ->where('lo.created_at', '>=', $start)
            ->pluck('lo.created_at');

        foreach ($created as $value) {
            $date = $this->toDate($value);

            if ($date === null) {
                continue;
            }

            $key = $date->format('Y-m');

            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        $volume = [];

        foreach ($buckets as $month => $count) {
            $volume[] = [
                'month' => $month,
                'label' => Carbon::createFromFormat('Y-m-d', $month.'-01')->format('m/Y'),
                'count' => $count,
            ];
        }

        return $volume;
    }

    /**
     * The statuses that mean the return never happened. Derived rather than
     * listed, so adding a closed status to the enum cannot leave this behind.
     *
     * @return list<string>
     */
    private function cancelledValues(): array
    {
        return array_values(array_diff(OrderStatus::closedValues(), OrderStatus::completedValues()));
    }

    private function toDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse((string) $value);
    }
}
