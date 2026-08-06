<?php

namespace App\Modules\UserProfile\B2B\Services;

use App\Models\User;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use Illuminate\Support\Facades\DB;

/**
 * Company-level numbers for the owner's dashboard: totals, and the same
 * figures broken down per member so an owner can see who is contributing
 * what. Read-only aggregation — no authorization decisions are made here,
 * the caller has already established the actor may view analytics for this
 * company.
 *
 * `totals` and `states` respect the viewer's vehicle scope, so an own-scope
 * member's figures describe the same set of vehicles their dashboard list
 * shows. Before this was applied the tiles counted the whole company fleet
 * while the table beneath them was narrowed — "12 Fahrzeuge" above a list of
 * three (unresolved item 32).
 */
class B2bAnalyticsService
{
    /**
     * Statuses that mean the return process is finished, matching the
     * customer-facing timeline's own definition (see lib/vehicleStatus.ts).
     */
    private const COMPLETED_STATUSES = ['delivered', 'completed'];

    private const CANCELLED_STATUSES = ['cancelled', 'discarded'];

    private const CLOSED_STATUSES = ['delivered', 'completed', 'cancelled', 'discarded'];

    public function __construct(private readonly VehicleScopeService $vehicleScope) {}

    /**
     * `$viewer` is **required**, not optional.
     *
     * Phase 17.1 made the equivalent parameter on VehicleService optional and
     * recorded the resulting trap — a caller that forgets it silently gets
     * company-wide data. With only two call sites here, both of which have the
     * request user to hand, that trap is closed rather than repeated.
     *
     * @return array{
     *     totals: array<string, int>,
     *     states: list<array<string, mixed>>,
     *     members: list<array<string, mixed>>
     * }
     */
    public function summary(string $b2bId, User $viewer): array
    {
        $restrictedTo = $this->vehicleScope->ownVehicleRestrictionFor($viewer);

        return [
            'totals' => $this->totals($b2bId, $restrictedTo),
            'states' => $this->vehicleStates($b2bId, $restrictedTo),
            // Deliberately NOT narrowed: this breakdown groups *by* member and
            // exists to answer "who is contributing what". Narrowing it to the
            // viewer would collapse it to a single row and destroy the panel's
            // purpose. It is gated on `members.view`, a separate permission.
            'members' => $this->memberBreakdown($b2bId),
        ];
    }

    /**
     * The company's vehicles bucketed by where each one stands in its return
     * process — the same question the dashboard table answers row by row.
     *
     * Deliberately counted per *vehicle*, not per order: `totals` counts order
     * rows, and a vehicle can carry several over its life, so those numbers
     * can exceed the fleet size and cannot be read as parts of a whole. These
     * can, which is what lets the dashboard draw them as one bar.
     *
     * A vehicle's state is its latest order's status, resolved with the same
     * correlated subquery VehicleService::applyVehicleFilters() uses, so the
     * counts here always agree with what filtering the table actually shows.
     *
     * `filter` is the dashboard's `status` query value that isolates the
     * bucket, or null where none exists (cancelled orders are deliberately
     * absent from the filter list).
     *
     * @return list<array{key: string, label: string, count: int, filter: ?string}>
     */
    private function vehicleStates(string $b2bId, ?int $restrictedTo): array
    {
        $latestStatus = DB::table('leasyback_orders as lo')
            ->select('lo.order_status')
            ->whereColumn('lo.vehicle_id', 'v.vehicle_id')
            ->orderByDesc('lo.created_at')
            ->limit(1);

        $rows = DB::table('vehicles as v')
            ->where('v.b2b_id', $b2bId)
            ->when($restrictedTo !== null, fn ($query) => $query->where('v.created_by_user_id', $restrictedTo))
            ->select('v.vehicle_id')
            ->selectSub($latestStatus, 'current_order_status')
            ->get();

        $planned = 0;
        $inProgress = 0;
        $completed = 0;
        $cancelled = 0;

        foreach ($rows as $row) {
            $status = $row->current_order_status;

            if ($status === null) {
                $planned++;
            } elseif (in_array($status, self::COMPLETED_STATUSES, true)) {
                $completed++;
            } elseif (in_array($status, self::CANCELLED_STATUSES, true)) {
                $cancelled++;
            } else {
                $inProgress++;
            }
        }

        return [
            ['key' => 'planned', 'label' => 'Eingeplant', 'count' => $planned, 'filter' => 'none'],
            ['key' => 'in_progress', 'label' => 'Laufend', 'count' => $inProgress, 'filter' => 'open'],
            ['key' => 'completed', 'label' => 'Abgeschlossen', 'count' => $completed, 'filter' => 'delivered'],
            ['key' => 'cancelled', 'label' => 'Storniert', 'count' => $cancelled, 'filter' => null],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function totals(string $b2bId, ?int $restrictedTo): array
    {
        $vehicles = DB::table('vehicles')
            ->where('b2b_id', $b2bId)
            ->when($restrictedTo !== null, fn ($query) => $query->where('created_by_user_id', $restrictedTo))
            ->count();

        // Narrowed by the *vehicle's* creator, not the order's, so these
        // figures describe exactly the vehicles the viewer's dashboard list
        // shows — which is the agreement item 32 was about.
        $orderStatuses = DB::table('leasyback_orders as lo')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'lo.vehicle_id')
            ->where('v.b2b_id', $b2bId)
            ->when($restrictedTo !== null, fn ($query) => $query->where('v.created_by_user_id', $restrictedTo))
            ->select('lo.order_status', DB::raw('count(*) as total'))
            ->groupBy('lo.order_status')
            ->pluck('total', 'order_status');

        $open = 0;
        $completed = 0;

        foreach ($orderStatuses as $status => $count) {
            if (in_array($status, self::COMPLETED_STATUSES, true)) {
                $completed += (int) $count;
            } elseif (! in_array($status, self::CLOSED_STATUSES, true)) {
                $open += (int) $count;
            }
        }

        // Member and invitation counts deliberately absent: both are answered
        // by the team page from data it already loads, and nothing reads them
        // from here.
        return [
            'vehicles' => $vehicles,
            'open_orders' => $open,
            'completed_orders' => $completed,
        ];
    }

    /**
     * Per-member activity. Every active member appears, including those with
     * zero of everything — "who has never used the account" is exactly the
     * kind of thing an owner opens this panel to find out.
     *
     * @return list<array<string, mixed>>
     */
    private function memberBreakdown(string $b2bId): array
    {
        $members = DB::table('user_b2b as ub')
            ->join('users as u', 'u.id', '=', 'ub.user_id')
            ->where('ub.b2b_id', $b2bId)
            ->where('ub.status', 'active')
            ->orderByRaw("CASE WHEN ub.role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('u.id')
            ->get(['u.id as user_id', 'u.name', 'u.email', 'ub.role']);

        if ($members->isEmpty()) {
            return [];
        }

        $userIds = $members->pluck('user_id')->all();

        $vehicleCounts = DB::table('vehicles')
            ->where('b2b_id', $b2bId)
            ->whereIn('created_by_user_id', $userIds)
            ->groupBy('created_by_user_id')
            ->pluck(DB::raw('count(*)'), 'created_by_user_id');

        $orderRows = DB::table('leasyback_orders as lo')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'lo.vehicle_id')
            ->where('v.b2b_id', $b2bId)
            ->whereIn('lo.created_by_user_id', $userIds)
            ->select('lo.created_by_user_id', 'lo.order_status', DB::raw('count(*) as total'))
            ->groupBy('lo.created_by_user_id', 'lo.order_status')
            ->get();

        $openByUser = [];
        $completedByUser = [];

        foreach ($orderRows as $row) {
            $userId = (int) $row->created_by_user_id;

            if (in_array($row->order_status, self::COMPLETED_STATUSES, true)) {
                $completedByUser[$userId] = ($completedByUser[$userId] ?? 0) + (int) $row->total;
            } elseif (! in_array($row->order_status, self::CLOSED_STATUSES, true)) {
                $openByUser[$userId] = ($openByUser[$userId] ?? 0) + (int) $row->total;
            }
        }

        $lastActivity = DB::table('vehicles')
            ->where('b2b_id', $b2bId)
            ->whereIn('created_by_user_id', $userIds)
            ->groupBy('created_by_user_id')
            ->pluck(DB::raw('max(created_at)'), 'created_by_user_id');

        return $members->map(fn (object $member) => [
            'user_id' => (int) $member->user_id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $member->role,
            'vehicles' => (int) ($vehicleCounts[$member->user_id] ?? 0),
            'open_orders' => $openByUser[(int) $member->user_id] ?? 0,
            'completed_orders' => $completedByUser[(int) $member->user_id] ?? 0,
            'last_vehicle_at' => $lastActivity[$member->user_id] ?? null,
        ])->all();
    }
}
