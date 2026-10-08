<?php

namespace App\Http\Controllers;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Http\Controllers\Concerns\BuildsFleetPage;
use App\Models\InspectionStation;
use App\Models\User;
use App\Modules\UserProfile\B2B\Data\B2bMembership;
use App\Modules\UserProfile\B2B\Services\B2bAnalyticsService;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\B2B\Services\B2bStatisticsService;
use App\Modules\UserProfile\Order\Models\CompanyBillingAddress;
use App\Modules\UserProfile\Order\Models\CompanyCostCentre;
use App\Modules\UserProfile\Order\Models\LogisticsAddressProfile;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        if ($this->awaitsCompanyRegistration($user, $membership)) {
            return $this->companyPendingDashboard();
        }

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
            'companyPending' => false,
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
            // What the Überführung and Unfallschaden forms offer under
            // "Gespeicherte Adresse / Kostenstelle wählen". The company's own
            // data only. (Prop name kept from the Überführung.)
            'relocationOptions' => $this->relocationOptions($membership->b2bId),
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

    /**
     * A Firmenkunde who skipped company registration ("Jetzt überspringen" /
     * "Später fertigstellen"). They belong to no company, so there is nothing
     * of one to show — but the dashboard is still theirs: the service
     * catalogue to look at and the way back to registration.
     *
     * A deactivated member is not this case: they had a company and lost
     * access, and fleetAccessRedirect() refuses them as before.
     */
    private function awaitsCompanyRegistration(User $user, ?B2bMembership $membership): bool
    {
        return $membership === null
            && $user->user_type === UserType::Firmenkunde
            && ! $this->b2bContext->hasInactiveMembership($user);
    }

    /**
     * The dashboard without a company. Nothing company-scoped is queried or
     * sent: no vehicles, orders, figures or saved company data. Every action
     * stays refused server-side regardless — the fleet pages redirect to
     * registration (fleetAccessRedirect) and every company route answers
     * through EnsureB2bPermission, which sends a company-less Firmenkunde to
     * the registration form too.
     */
    private function companyPendingDashboard(): Response
    {
        // Every prop the page declares, empty — the page never reads one that
        // is missing (DashboardControllerTest pins that), and none carries data.
        return Inertia::render('b2b/Dashboard', [
            'companyPending' => true,
            'bookableVehicles' => [],
            'myOverview' => null,
            'myVehicles' => [],
            'recentOrders' => [],
            'stations' => [],
            'relocationOptions' => ['address_profiles' => [], 'billing_addresses' => [], 'saved_cost_centres' => [], 'cost_centres' => []],
            'analytics' => null,
            'statistics' => null,
        ]);
    }

    /**
     * The company's saved data the service forms offer:
     *
     * - address_profiles: the logistics address profiles the fleet uses as
     *   pickup addresses (vehicle and location addresses);
     * - billing_addresses: the company's saved billing addresses, the
     *   default first — the forms preselect it;
     * - saved_cost_centres: the company's saved cost centres (name + number);
     * - cost_centres: the cost centre names already recorded on vehicles.
     *
     * @return array<string, list<mixed>>
     */
    private function relocationOptions(string $b2bId): array
    {
        $addressProfiles = LogisticsAddressProfile::where('owner_type', 'B2B')
            ->where('b2b_id', $b2bId)
            ->orderByDesc('is_default')
            ->orderBy('profile_name')
            ->get(['id', 'profile_name', 'details'])
            ->map(fn (LogisticsAddressProfile $profile) => [
                'id' => $profile->id,
                'profile_name' => $profile->profile_name,
                'details' => $profile->details,
            ])
            ->values()
            ->all();

        $billingAddresses = CompanyBillingAddress::where('b2b_id', $b2bId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'details', 'is_default'])
            ->map(fn (CompanyBillingAddress $address) => [
                'id' => $address->id,
                'name' => $address->name,
                'details' => $address->details,
                'is_default' => $address->is_default,
            ])
            ->values()
            ->all();

        $savedCostCentres = CompanyCostCentre::where('b2b_id', $b2bId)
            ->orderBy('name')
            ->get(['id', 'name', 'number'])
            ->map(fn (CompanyCostCentre $centre) => [
                'id' => $centre->id,
                'name' => $centre->name,
                'number' => $centre->number,
            ])
            ->values()
            ->all();

        $costCentres = DB::table('vehicles')
            ->where('vehicle_belongs', 'B2B')
            ->where('b2b_id', $b2bId)
            ->whereNotNull('cost_centre')
            ->where('cost_centre', '!=', '')
            ->distinct()
            ->orderBy('cost_centre')
            ->pluck('cost_centre')
            ->values()
            ->all();

        return [
            'address_profiles' => $addressProfiles,
            'billing_addresses' => $billingAddresses,
            'saved_cost_centres' => $savedCostCentres,
            'cost_centres' => $costCentres,
        ];
    }
}
