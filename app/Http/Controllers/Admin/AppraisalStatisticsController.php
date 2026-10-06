<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statistics for Gutachten orders (Vehicle Condition Appraisal brief): the
 * total and the counts for Requested, Scheduled and Completed, filterable by
 * date range, company, vehicle/registration number and status. No charts.
 *
 * The three brief statuses map onto the order statuses as:
 * REQUESTED = order_requested + order_placed, SCHEDULED = confirmed,
 * COMPLETED = completed. Total is every Gutachten order in the period.
 *
 * One order can cover several vehicles, so the vehicle filter matches an
 * order by any of them — the counts stay counts of orders.
 */
class AppraisalStatisticsController extends Controller
{
    private const SERVICE_TYPE = 'gutachten';

    private const STATUS_GROUPS = [
        'requested' => ['order_requested', 'order_placed'],
        'scheduled' => ['confirmed'],
        'completed' => ['completed'],
    ];

    /** GET admin/statistics/gutachten */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'company' => ['nullable', 'uuid'],
            'vehicle' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:requested,scheduled,completed'],
        ]);

        $filters = [
            'start_date' => $validated['start_date'] ?? '',
            'end_date' => $validated['end_date'] ?? '',
            'company' => $validated['company'] ?? '',
            'vehicle' => trim((string) ($validated['vehicle'] ?? '')),
            'status' => $validated['status'] ?? '',
        ];

        $base = $this->filtered($filters);

        return Inertia::render('Admin/Statistics/Appraisal', [
            'totals' => [
                'total' => (clone $base)->count(),
                'requested' => (clone $base)->whereIn('o.order_status', self::STATUS_GROUPS['requested'])->count(),
                'scheduled' => (clone $base)->whereIn('o.order_status', self::STATUS_GROUPS['scheduled'])->count(),
                'completed' => (clone $base)->whereIn('o.order_status', self::STATUS_GROUPS['completed'])->count(),
            ],
            'filters' => $filters,
            // Companies that have Gutachten orders, for the company filter.
            'companies' => DB::table('b2b as b')
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')
                    ->from('leasyback_orders as o')
                    ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')
                    ->whereColumn('v.b2b_id', 'b.b2b_id')
                    ->where('o.service_type', self::SERVICE_TYPE))
                ->orderBy('b.company_name')
                ->get(['b.b2b_id', 'b.company_name'])
                ->map(fn (object $company) => ['id' => $company->b2b_id, 'name' => $company->company_name])
                ->all(),
        ]);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filtered(array $filters): Builder
    {
        // Joined on the order's first vehicle: one row per order, and every
        // vehicle of an order belongs to the same company.
        $query = DB::table('leasyback_orders as o')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')
            ->where('o.service_type', self::SERVICE_TYPE);

        if ($filters['start_date'] !== '') {
            $query->where('o.created_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $filters['start_date'])->startOfDay());
        }

        if ($filters['end_date'] !== '') {
            $query->where('o.created_at', '<=', CarbonImmutable::createFromFormat('Y-m-d', $filters['end_date'])->endOfDay());
        }

        if ($filters['company'] !== '') {
            $query->where('v.b2b_id', $filters['company']);
        }

        if ($filters['vehicle'] !== '') {
            $term = '%'.addcslashes($filters['vehicle'], '%_\\').'%';

            $query->where(fn (Builder $q) => $q
                ->where('v.license_plate', 'like', $term)
                ->orWhere('v.vin', 'like', $term)
                ->orWhere('v.make', 'like', $term)
                ->orWhere('v.model', 'like', $term)
                // …or any further vehicle of the same order.
                ->orWhereExists(fn (Builder $linked) => $linked->selectRaw('1')
                    ->from('leasyback_order_vehicles as ov')
                    ->join('vehicles as lv', 'lv.vehicle_id', '=', 'ov.vehicle_id')
                    ->whereColumn('ov.order_id', 'o.id')
                    ->where(fn (Builder $match) => $match
                        ->where('lv.license_plate', 'like', $term)
                        ->orWhere('lv.vin', 'like', $term)
                        ->orWhere('lv.make', 'like', $term)
                        ->orWhere('lv.model', 'like', $term))));
        }

        if ($filters['status'] !== '') {
            $query->whereIn('o.order_status', self::STATUS_GROUPS[$filters['status']]);
        }

        return $query;
    }
}
