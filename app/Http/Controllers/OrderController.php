<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Models\InspectionStation;
use App\Modules\UserProfile\Order\Services\OrderService;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(
        private readonly VehicleScopeService $scope,
        private readonly OrderService $orderService,
    ) {}

    /**
     * Session-authenticated counterpart of the Sanctum API's
     * OrderController::createTuvsud()/createOther(). The customer never
     * picks a provider explicitly — it's derived from the chosen station,
     * matching leasyback_web's OrderCreationModal: a TÜV SÜD station goes
     * through the TÜV SÜD booking flow, any other provider's station goes
     * through the generic flow.
     */
    public function store(Request $request, string $vehicleId): RedirectResponse
    {
        $user = $request->user();
        $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);

        if (! $vehicle) {
            abort(404);
        }

        $validated = $request->validate([
            'station_id' => 'required|uuid|exists:inspection_stations,station_id',
            'termin' => 'required|date',
            'remarks' => 'nullable|string',
        ]);

        $station = InspectionStation::find($validated['station_id']);

        return $this->withServiceErrorHandling(
            'order',
            fn () => $station->provider === 'tuvsud'
                ? $this->orderService->createTuvsudOrder($vehicle, $user, $validated)
                : $this->orderService->createOtherOrder($vehicle, $user, [...$validated, 'provider' => $station->provider])
        ) ?? back()->with('success', 'Termin wurde gebucht.');
    }
}
