<?php

namespace App\Http\Controllers;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Models\InspectionStation;
use App\Models\Vehicle;
use App\Modules\UserProfile\B2B\Services\B2bAnalyticsService;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Vehicle\Http\Requests\StoreVehicleRequest;
use App\Modules\UserProfile\Vehicle\Http\Requests\UpdateVehicleRequest;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    use HandlesServiceValidationErrors;

    private const VEHICLES_PER_PAGE = 10;

    public function __construct(
        private readonly VehicleScopeService $scope,
        private readonly VehicleService $vehicleService,
        private readonly B2bContext $b2bContext,
        private readonly B2bAnalyticsService $analytics,
    ) {}

    /**
     * The main authenticated dashboard: the user's vehicles with their
     * current order status. Session-authenticated counterpart of the
     * Sanctum API's VehicleController::dashboard() — deliberately a
     * separate controller/entry point, not a reuse of that action, since
     * Inertia pages can't call Sanctum-bearer-token routes directly (see
     * docs/B2C_ADMIN_IMPLEMENTATION_PROGRESS.md, Checkpoint 3 decisions).
     * Both ultimately go through VehicleService.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // The customer dashboard is a customer surface only — admins have
        // their own area (routes/admin.php) and are sent there instead.
        if ($user->isAdmin()) {
            return to_route('admin.dashboard');
        }

        $membership = $this->b2bContext->activeMembership($user);
        $isB2b = $membership !== null;

        // A Firmenkunde who belongs to no company can't have any vehicles yet
        // — send them to register one instead of an empty dashboard. A
        // Privatkunde acting privately has their own dashboard and is left
        // alone.
        if (! $isB2b && $user->user_type === UserType::Firmenkunde) {
            return to_route('onboarding.b2b.show');
        }

        if ($membership !== null && ! $membership->can(B2bPermission::ViewVehicles)) {
            return to_route('b2b.members.index');
        }

        $belongs = $isB2b ? 'B2B' : 'B2C';
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

        return Inertia::render('Dashboard', [
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
        ]);
    }

    /**
     * Members of the company, for the dashboard's "added by" filter.
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

    public function show(Request $request, string $vehicleId): Response
    {
        $user = $request->user();
        $vehicle = Vehicle::find($vehicleId);

        if (! $vehicle || ! $user->can('view', $vehicle)) {
            abort(404);
        }

        $belongs = match ($this->b2bContext->effectiveUserType($user)->value) {
            'Admin' => 'ALL',
            'Firmenkunde' => 'B2B',
            default => 'B2C',
        };
        $ownerId = $belongs === 'ALL' ? null : $this->scope->resolveOwnerId($user);

        $data = $this->vehicleService->findVehicleWithOrders($vehicleId, $ownerId, $belongs, $user);

        abort_if($data === null, 404);

        return Inertia::render('vehicles/Show', [
            'vehicle' => $data,
            'stations' => InspectionStation::where('is_active', true)
                ->orderBy('provider')
                ->orderBy('name')
                ->get(['station_id', 'provider', 'name', 'strasse', 'plz', 'ort', 'bundesland', 'land']),
        ]);
    }

    public function store(StoreVehicleRequest $request): RedirectResponse
    {
        return $this->withServiceErrorHandling(
            'vehicle',
            fn () => $this->vehicleService->createVehicle($request->user(), $request->validated())
        ) ?? to_route('dashboard')->with('success', 'Fahrzeug wurde angelegt.');
    }

    public function update(UpdateVehicleRequest $request, string $vehicleId): RedirectResponse
    {
        $user = $request->user();
        $vehicle = Vehicle::find($vehicleId);

        if (! $vehicle || ! $user->can('update', $vehicle)) {
            abort(404);
        }

        return $this->withServiceErrorHandling(
            'vehicle',
            fn () => $this->vehicleService->updateVehicle($vehicle, $request->validated(), $user)
        ) ?? back()->with('success', 'Fahrzeug wurde aktualisiert.');
    }
}
