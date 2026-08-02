<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Vehicle\Http\Requests\StoreVehicleRequest;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(
        private readonly AdminQueryService $adminQueryService,
        private readonly VehicleService $vehicleService,
    ) {}

    public function index(Request $request): Response
    {
        $userType = in_array($request->query('user_type'), ['Privatkunde', 'Firmenkunde'], true)
            ? $request->query('user_type')
            : null;

        $request->merge(['limit' => 10]);

        return Inertia::render('Admin/Vehicles/Index', [
            'vehicles' => $this->adminQueryService->vehicles($request, $userType),
            'filters' => [
                'search' => trim((string) $request->query('search', '')),
                'status' => (string) $request->query('status', ''),
                'user_type' => $userType ?? 'all',
            ],
        ]);
    }

    public function show(string $vehicleId): Response
    {
        $vehicle = $this->adminQueryService->vehicleDetail($vehicleId);
        abort_unless($vehicle !== null, 404);

        return Inertia::render('Admin/Vehicles/Show', [
            'vehicle' => $vehicle,
        ]);
    }

    /**
     * "Create on behalf of a customer" — StoreVehicleRequest already
     * requires and validates vehicle_belongs/b2b_id/b2c_user_id as real
     * records when the caller is Admin (see docs/B2C_ADMIN_PERMISSION_MATRIX.md's
     * Vehicle `create` row). Redirects to the new vehicle's own detail
     * page rather than a listing, unlike the B2C dashboard's store().
     */
    public function store(StoreVehicleRequest $request): RedirectResponse
    {
        $vehicle = null;
        $denied = $this->withServiceErrorHandling('vehicle', function () use ($request, &$vehicle) {
            $vehicle = $this->vehicleService->createVehicle($request->user(), $request->validated());
        });

        if ($denied) {
            return $denied;
        }

        /** @var Vehicle $vehicle */
        return to_route('admin.vehicles.show', $vehicle->vehicle_id)->with('success', 'Fahrzeug wurde angelegt.');
    }
}
