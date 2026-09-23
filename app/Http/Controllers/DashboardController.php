<?php

namespace App\Http\Controllers;

use App\Enums\B2bPermission;
use App\Http\Controllers\Concerns\BuildsFleetPage;
use App\Models\InspectionStation;
use App\Models\User;
use App\Modules\UserProfile\B2B\Services\B2bAnalyticsService;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\B2B\Services\B2bStatisticsService;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landing page every post-login redirect goes to. What it shows depends
 * on who is asking, and the two answers are deliberately different pages:
 *
 * - A **Privatkunde** gets their fleet, exactly as they always have. Their
 *   dashboard *is* their vehicle list; there is no company, no team and no
 *   catalogue to put in front of it.
 * - A **Firmenkunde** gets the service catalogue and the vehicle picker each
 *   service is booked through, with the fleet and the orders on their own
 *   pages (`vehicles.index` / `orders.index`) reached from the navigation.
 */
class DashboardController extends Controller
{
    use BuildsFleetPage;

    /** Enough to show what is moving without becoming a second orders page. */
    private const RECENT_ORDER_LIMIT = 5;

    /** Same idea for the member's fleet preview — the list itself is a page. */
    private const VEHICLE_PREVIEW_LIMIT = 5;

    public function __construct(
        private readonly VehicleScopeService $scope,
        private readonly VehicleService $vehicleService,
        private readonly B2bContext $b2bContext,
        private readonly B2bAnalyticsService $analytics,
        private readonly B2bStatisticsService $statistics,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
    
        $user = $request->user();
        $membership = $this->b2bContext->activeMembership($user);

        if ($redirect = $this->fleetAccessRedirect($user, $membership)) {
            return $redirect;
        }

        if ($membership === null) {
            return Inertia::render('Dashboard', $this->fleetPageProps($request, $user, $membership));
        }

        $ownerId = $this->scope->resolveOwnerId($user);
        $seesCompanyOverview = $membership->can(B2bPermission::ViewAnalytics);
        $bookableVehicles = $this->vehicleService->listBookableVehicles($ownerId, 'B2B', $user);

        /*
         * A member without the company overview gets an operating one instead:
         * what they can reach, what is running, and what they could start.
         *
         * Every figure is scoped to the reader by the same VehicleScopeService
         * rule as the fleet page, so an own-scope member counts their own
         * vehicles and nobody else's — these are not the company's numbers
         * shown to someone who may not see them, they are the reader's own.
         */
        $memberOverview = null;
        $memberVehicles = [];

        if (! $seesCompanyOverview) {
            // One call for both the preview rows and the accessible total, so
            // the count can never disagree with the list under it.
            $fleet = $this->vehicleService->paginateVehiclesWithOrders(
                $ownerId,
                'B2B',
                [],
                self::VEHICLE_PREVIEW_LIMIT,
                1,
                $user,
            );

            $memberVehicles = $fleet['data'];
            $memberOverview = [
                'vehicles' => $fleet['meta']['total'],
                'active_orders' => count(
                    $this->vehicleService->listCustomerOrders($ownerId, 'B2B', ['status' => 'open'], $user),
                ),
                'bookable_vehicles' => count($bookableVehicles),
            ];
        }

        return Inertia::render('b2b/Dashboard', [
            // Only the vehicles a service can actually be booked for. One
            // already in a process is not an option the picker should offer,
            // and the server refuses a second order for it either way.
            'bookableVehicles' => $bookableVehicles,
            // Operating figures and a fleet preview — a member's dashboard.
            // Null/empty for a Company Administrator, whose page is the
            // company overview above and is left exactly as it was.
            'myOverview' => $memberOverview,
            'myVehicles' => $memberVehicles,
            // The newest few processes, as an overview — the full list is its
            // own page. Same query that page runs, limited in SQL, so the two
            // can never disagree about what an order says.
            'recentOrders' => $seesCompanyOverview
                ? $this->vehicleService->listCustomerOrders($ownerId, 'B2B', [], $user, self::RECENT_ORDER_LIMIT)
                : [],
            'stations' => InspectionStation::where('is_active', true)
                ->orderBy('provider')
                ->orderBy('name')
                ->get(['station_id', 'provider', 'name', 'strasse', 'plz', 'ort', 'bundesland', 'land']),
            /*
             * The company overview — fleet states, key figures and the recent
             * activity above — is for whoever may see the company's numbers,
             * which is what `analytics.view` means. An owner holds it
             * implicitly (B2bMembership::can), so a Company Administrator gets
             * the full dashboard while a Standard User and a Read-only member
             * get the service catalogue alone.
             *
             * Withheld from the payload, not merely hidden in the template:
             * props travel to the browser in the page source, so a section the
             * reader may not see is a section they must not be sent.
             */
            'analytics' => $seesCompanyOverview ? $this->analytics->summary($membership->b2bId, $user) : null,
            // Order totals, average processing time and savings, from the
            // service the statistics page already aggregates them with — the
            // dashboard reads the same numbers rather than deriving its own.
            'statistics' => $seesCompanyOverview ? $this->statistics->summary($membership, $user->id) : null,
        ]);
    }
}
