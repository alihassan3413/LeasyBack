<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Models\InspectionStation;
use App\Models\User;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The fleet listing, shared by the two pages that render it.
 *
 * A Privatkunde's fleet *is* their dashboard and always has been, so
 * DashboardController renders it at `/dashboard` for them. A company's fleet
 * is its own page, because a Firmenkunde's dashboard is the service catalogue
 * instead. Both need byte-identical props, which is why the payload is built
 * here once rather than in each controller.
 *
 * Expects the using class to hold `$scope`, `$vehicleService`, `$analytics`
 * and `$b2bContext` — both controllers already inject all four.
 */
trait BuildsFleetPage
{
    private const VEHICLES_PER_PAGE = 10;

    /**
     * Where a customer who does not belong on a fleet surface should go
     * instead, or null when they do belong.
     */
    private function fleetAccessRedirect(User $user, ?B2bMembership $membership): ?RedirectResponse
    {
        // A customer surface only — admins have their own area (routes/admin.php).
        if ($user->isAdmin()) {
            return to_route('admin.dashboard');
        }

        // A Firmenkunde who belongs to no company can't have any vehicles yet
        // — send them to register one instead of an empty page. A Privatkunde
        // acting privately has their own dashboard and is left alone.
        if ($membership === null && $user->user_type === UserType::Firmenkunde) {
            return to_route('onboarding.b2b.show');
        }

        if ($membership !== null && ! $membership->can(B2bPermission::ViewVehicles)) {
            return to_route($this->firstReachableCompanyPage($membership));
        }

        return null;
    }

    /**
     * Where a member who may not see the fleet lands instead: the first page
     * their permissions actually open. Always sending them to the team page
     * looped a member without `members.view` into a 403 (dashboard → team →
     * refused). "Mein Konto" needs no company permission at all, so it is the
     * floor — every member can reach it.
     */
    private function firstReachableCompanyPage(B2bMembership $membership): string
    {
        return match (true) {
            $membership->can(B2bPermission::ViewMembers) => 'b2b.members.index',
            $membership->can(B2bPermission::ViewAnalytics) => 'b2b.statistics.index',
            default => 'profile.edit',
        };
    }

    /**
     * The route name of the page that shows this user's fleet: its own page
     * for a company, the dashboard for a Privatkunde. Used by the redirects
     * that should land on "the list the new row is in".
     */
    private function fleetRouteFor(User $user): string
    {
        return $this->b2bContext->activeMembership($user) !== null ? 'vehicles.index' : 'dashboard';
    }

    /**
     * One page of the customer's vehicles, with everything the fleet table
     * and its toolbar need.
     *
     * @return array<string, mixed>
     */
    private function fleetPageProps(Request $request, User $user, ?B2bMembership $membership): array
    {
        $belongs = $membership !== null ? 'B2B' : 'B2C';
        $ownerId = $this->scope->resolveOwnerId($user);

        // Filtering by who added a vehicle is only offered to company members
        // who can see the whole fleet — for an Own-scoped member the answer is
        // always "me", and VehicleScopeService has already enforced it.
        $canFilterByMember = $membership !== null && ! $membership->seesOwnVehiclesOnly();

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'sort' => (string) $request->query('sort', 'created_at'),
            'direction' => strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc',
            'created_by' => $canFilterByMember ? (string) $request->query('created_by', '') : '',
        ];

        $page = max(1, (int) $request->query('page', 1));
        // $user is passed so the listing applies the member-level vehicle
        // scope, not just the company one — an own-scope member must be shown
        // the same set the detail page would let them open (phase 17 finding 1).
        $paginated = $this->vehicleService->paginateVehiclesWithOrders($ownerId, $belongs, $filters, self::VEHICLES_PER_PAGE, $page, $user);

        return [
            'vehicles' => $paginated['data'],
            'pagination' => $paginated['meta'],
            'stations' => InspectionStation::where('is_active', true)
                ->orderBy('provider')
                ->orderBy('name')
                ->get(['station_id', 'provider', 'name', 'strasse', 'plz', 'ort', 'bundesland', 'land']),
            'filters' => $filters,
            'memberOptions' => $canFilterByMember ? $this->memberOptions($membership->b2bId) : [],
            'analytics' => $membership?->can(B2bPermission::ViewAnalytics)
                ? $this->analytics->summary($membership->b2bId, $user)
                : null,
        ];
    }

    /**
     * Members of the company, for the fleet's "added by" filter.
     *
     * @return list<array{value: int, label: string}>
     */
    private function memberOptions(string $b2bId): array
    {
        return DB::table('user_b2b as ub')
            ->join('users as u', 'u.id', '=', 'ub.user_id')
            ->where('ub.b2b_id', $b2bId)
            ->where('ub.status', 'active')
            ->orderBy('u.name')
            ->orderBy('u.email')
            ->get(['u.id', 'u.name', 'u.email'])
            ->map(fn (object $row) => [
                'value' => (int) $row->id,
                'label' => $row->name ?: $row->email,
            ])
            ->all();
    }
}
