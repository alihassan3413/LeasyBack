<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Models\InspectionStation;
use App\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Http\Requests\StoreVehicleRequest;
use App\Modules\UserProfile\Vehicle\Http\Requests\UpdateVehicleRequest;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    use HandlesServiceValidationErrors;

    private const VEHICLES_PER_PAGE = 10;

    public function __construct(
        private readonly VehicleScopeService $scope,
        private readonly VehicleService $vehicleService,
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

        $belongs = $user->user_type->value === 'Firmenkunde' ? 'B2B' : 'B2C';
        $ownerId = $this->scope->resolveOwnerId($user);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'sort' => (string) $request->query('sort', 'created_at'),
            'direction' => strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc',
        ];

        $page = max(1, (int) $request->query('page', 1));
        $paginated = $this->vehicleService->paginateVehiclesWithOrders($ownerId, $belongs, $filters, self::VEHICLES_PER_PAGE, $page);

        return Inertia::render('Dashboard', [
            'vehicles' => $paginated['data'],
            'pagination' => $paginated['meta'],
            'stations' => InspectionStation::where('is_active', true)
                ->orderBy('provider')
                ->orderBy('name')
                ->get(['station_id', 'provider', 'name', 'strasse', 'plz', 'ort', 'bundesland', 'land']),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, string $vehicleId): Response
    {
        $user = $request->user();
        $vehicle = Vehicle::find($vehicleId);

        if (! $vehicle || ! $user->can('view', $vehicle)) {
            abort(404);
        }

        $belongs = match ($user->user_type->value) {
            'Admin' => 'ALL',
            'Firmenkunde' => 'B2B',
            default => 'B2C',
        };
        $ownerId = $belongs === 'ALL' ? null : $this->scope->resolveOwnerId($user);

        $data = $this->vehicleService->findVehicleWithOrders($vehicleId, $ownerId, $belongs);

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
