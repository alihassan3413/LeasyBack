<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\UserProfile\Order\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statistics for Accident Damage Orders (Accident Damage brief, "Statistics
 * view"): the total and the counts for Requested, Scheduled and Completed,
 * filterable by date range, company, vehicle/registration number and status.
 * No charts.
 *
 * The three brief statuses map onto the order statuses as:
 * REQUESTED = order_requested + order_placed, SCHEDULED = confirmed,
 * COMPLETED = completed. Total is every Accident Damage Order in the period.
 */
class AccidentDamageStatisticsController extends Controller
{
    private const STATUS_GROUPS = [
        'requested' => ['order_requested', 'order_placed'],
        'scheduled' => ['confirmed'],
        'completed' => ['completed'],
    ];

    /** GET admin/statistics/unfallschaden */
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

        return Inertia::render('Admin/Statistics/AccidentDamage', [
            'totals' => [
                'total' => (clone $base)->count(),
                'requested' => (clone $base)->whereIn('o.order_status', self::STATUS_GROUPS['requested'])->count(),
                'scheduled' => (clone $base)->whereIn('o.order_status', self::STATUS_GROUPS['scheduled'])->count(),
                'completed' => (clone $base)->whereIn('o.order_status', self::STATUS_GROUPS['completed'])->count(),
            ],
            'filters' => $filters,
            // Companies that have Accident Damage Orders, for the company filter.
            'companies' => DB::table('b2b as b')
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')
                    ->from('leasyback_orders as o')
                    ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')
                    ->whereColumn('v.b2b_id', 'b.b2b_id')
                    ->where('o.service_type', OrderService::SERVICE_ACCIDENT_DAMAGE))
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
        $query = DB::table('leasyback_orders as o')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')
            ->where('o.service_type', OrderService::SERVICE_ACCIDENT_DAMAGE);

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
            $query->where(fn (Builder $q) => $q->where('v.license_plate', 'like', $term)
                ->orWhere('v.vin', 'like', $term)
                ->orWhere('v.make', 'like', $term)
                ->orWhere('v.model', 'like', $term));
        }

        if ($filters['status'] !== '') {
            $query->whereIn('o.order_status', self::STATUS_GROUPS[$filters['status']]);
        }

        return $query;
    }
}