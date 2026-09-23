<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Models\InspectionStation;
use App\Models\LeasybackOrder;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Services\OrderService;
use App\Modules\UserProfile\Payment\Support\OrderCreatedFlash;
use App\Modules\UserProfile\Profile\Http\Requests\AddressContactRequest;
use App\Modules\UserProfile\Profile\Services\ProfileService;
use App\Modules\UserProfile\Vehicle\Http\Requests\StoreWebVehicleRequest;
use App\Modules\UserProfile\Vehicle\Http\Requests\UpdateWebVehicleRequest;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Post-registration onboarding wizard for Privatkunde (B2C) users: profile
 * (address/contact), first vehicle, then a booked inspection appointment.
 * Session-authenticated counterpart of the same ProfileService/VehicleService/
 * OrderService used by the Settings and Dashboard controllers — mirrors the
 * "separate controller/entry point, shared service" pattern already used
 * throughout this app (see VehicleController, OrderController).
 */
class OnboardingController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(
        private readonly ProfileService $profileService,
        private readonly VehicleService $vehicleService,
        private readonly OrderService $orderService,
        private readonly OrderCreatedFlash $orderCreated,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->user_type !== UserType::Privatkunde) {
            return to_route('dashboard');
        }

        $vehicle = $this->currentVehicle($user);

        return Inertia::render('onboarding/B2cRegistration', [
            'profile' => $this->profileService->findForUser($user),
            'vehicle' => $vehicle === null ? null : [
                ...$vehicle->only(['vehicle_id', 'license_plate', 'make', 'model', 'vin', 'leasinggeber']),
                // `only()` hands back the Carbon instance, which serialises as a
                // full ISO timestamp; the wizard's date field reads `Y-m-d`.
                'leasing_end_date' => VehicleService::asDateString($vehicle->leasing_end_date),
            ],
            'order' => $vehicle ? $this->currentOrder($vehicle)?->only(['id', 'auftragsnummer', 'order_status']) : null,
            'stations' => InspectionStation::where('is_active', true)
                ->orderBy('provider')
                ->orderBy('name')
                ->get(['station_id', 'provider', 'name', 'strasse', 'plz', 'ort', 'bundesland', 'land']),
        ]);
    }

    public function storeProfile(AddressContactRequest $request): RedirectResponse
    {
        return $this->withServiceErrorHandling(
            'profile',
            fn () => $this->profileService->createAddressContact($request->user(), $request->validated())
        ) ?? to_route('onboarding.show')->with('success', 'Kundendaten wurden gespeichert.');
    }

    /**
     * Re-saving step 1 after the user navigated back through the wizard.
     */
    public function updateProfile(AddressContactRequest $request): RedirectResponse
    {
        return $this->withServiceErrorHandling(
            'profile',
            fn () => $this->profileService->updateAddressContact($request->user(), $request->validated())
        ) ?? to_route('onboarding.show')->with('success', 'Kundendaten wurden aktualisiert.');
    }

    public function storeVehicle(StoreWebVehicleRequest $request): RedirectResponse
    {
        return $this->withServiceErrorHandling(
            'vehicle',
            fn () => $this->vehicleService->createVehicle($request->user(), $request->validated())
        ) ?? to_route('onboarding.show')->with('success', 'Fahrzeug wurde angelegt.');
    }

    /**
     * Re-saving step 2 after the user navigated back through the wizard. The
     * license plate is deliberately not updatable here, matching
     * VehicleService::updateVehicle() and the dashboard's edit modal.
     */
    public function updateVehicle(UpdateWebVehicleRequest $request): RedirectResponse
    {
        $user = $request->user();
        $vehicle = $this->currentVehicle($user);

        if (! $vehicle) {
            abort(404);
        }

        return $this->withServiceErrorHandling(
            'vehicle',
            fn () => $this->vehicleService->updateVehicle($vehicle, $request->validated(), $user)
        ) ?? to_route('onboarding.show')->with('success', 'Fahrzeugdaten wurden aktualisiert.');
    }

    public function storeAppointment(Request $request): RedirectResponse
    {
        $user = $request->user();
        $vehicle = $this->currentVehicle($user);

        if (! $vehicle) {
            abort(404);
        }

        $validated = $request->validate([
            'station_id' => 'required|uuid|exists:inspection_stations,station_id',
            'termin' => 'required|date',
            'remarks' => 'nullable|string',
        ]);

        $station = InspectionStation::find($validated['station_id']);

        $order = null;

        $denied = $this->withServiceErrorHandling(
            'appointment',
            function () use ($station, $vehicle, $user, $validated, &$order) {
                $order = $station->provider === 'tuvsud'
                    ? $this->orderService->createTuvsudOrder($vehicle, $user, $validated)
                    : $this->orderService->createOtherOrder($vehicle, $user, [...$validated, 'provider' => $station->provider]);
            }
        );

        if ($denied) {
            return $denied;
        }

        return to_route('onboarding.show')
            ->with('success', 'Termin wurde gebucht.')
            ->with('order_created', $this->orderCreated->for($order, $user));
    }

    private function currentVehicle(User $user): ?Vehicle
    {
        return Vehicle::where('vehicle_belongs', 'B2C')
            ->where('b2c_user_id', $user->id)
            ->latest()
            ->first();
    }

    private function currentOrder(Vehicle $vehicle): ?LeasybackOrder
    {
        return LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)
            ->where('order_status', '!=', 'cancelled')
            ->latest()
            ->first();
    }
}
