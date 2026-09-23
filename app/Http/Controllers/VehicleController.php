<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsFleetPage;
use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Models\InspectionStation;
use App\Models\Vehicle;
use App\Modules\UserProfile\B2B\Services\B2bAnalyticsService;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Vehicle\Http\Requests\StoreWebVehicleRequest;
use App\Modules\UserProfile\Vehicle\Http\Requests\UpdateWebVehicleRequest;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    use BuildsFleetPage;
    use HandlesServiceValidationErrors;

    public function __construct(
        private readonly VehicleScopeService $scope,
        private readonly VehicleService $vehicleService,
        private readonly B2bContext $b2bContext,
        private readonly B2bAnalyticsService $analytics,
    ) {}

    /**
     * The company's fleet: its vehicles with their current order status.
     *
     * Company customers only. A Privatkunde's fleet is their dashboard and
     * always has been — DashboardController renders it there from the same
     * BuildsFleetPage payload — so one arriving here is sent back to it
     * rather than shown a second copy under a different heading.
     *
     * Session-authenticated counterpart of the Sanctum API's
     * VehicleController::dashboard() — deliberately a separate
     * controller/entry point, not a reuse of that action, since Inertia pages
     * can't call Sanctum-bearer-token routes directly (see
     * docs/B2C_ADMIN_IMPLEMENTATION_PROGRESS.md, Checkpoint 3 decisions).
     * Both ultimately go through VehicleService.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $membership = $this->b2bContext->activeMembership($user);

        if ($redirect = $this->fleetAccessRedirect($user, $membership)) {
            return $redirect;
        }

        if ($membership === null) {
            return to_route('dashboard');
        }

        return Inertia::render('vehicles/Index', $this->fleetPageProps($request, $user, $membership));
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

    public function store(StoreWebVehicleRequest $request): RedirectResponse
    {
        return $this->withServiceErrorHandling(
            'vehicle',
            fn () => $this->vehicleService->createVehicle($request->user(), $request->validated())
        ) ?? to_route($this->fleetRouteFor($request->user()))->with('success', 'Fahrzeug wurde angelegt.');
    }

    public function update(UpdateWebVehicleRequest $request, string $vehicleId): RedirectResponse
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
