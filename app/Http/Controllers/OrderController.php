<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Models\InspectionStation;
use App\Models\LeasybackOrder;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use App\Modules\UserProfile\Order\Services\OrderService;
use App\Modules\UserProfile\Payment\Support\OrderCreatedFlash;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    use HandlesServiceValidationErrors;

    public function __construct(
        private readonly VehicleScopeService $scope,
        private readonly OrderService $orderService,
        private readonly OrderCreatedFlash $orderCreated,
        private readonly VehicleService $vehicleService,
        private readonly B2bContext $b2bContext,
    ) {}

    /**
     * One order in full, reached from the vehicle's Auftragsverlauf.
     *
     * A vehicle keeps every order it has ever had, so "the order" is only ever
     * a specific record — this is addressed by that record's id and never by
     * its position in a list. The customer's counterpart of admin.orders.show,
     * and deliberately not a mode of the vehicle page: a closed order has its
     * own timeline, its own documents and its own final state, none of which
     * belong on the page showing the live one.
     *
     * Authorization is answered twice by the same rule: OrderPolicy::view()
     * for the record, then the owner/company/member scope inside
     * findOrderDetail(), which is what actually decides whether the payload
     * can be built. Either miss is a 404 — an order the viewer may not see
     * must not be distinguishable from one that does not exist.
     */
    public function show(Request $request, string $orderId): Response
    {
        $user = $request->user();
        $order = LeasybackOrder::find($orderId);

        abort_if($order === null || ! $user->can('view', $order), 404);

        $belongs = match ($this->b2bContext->effectiveUserType($user)->value) {
            'Admin' => 'ALL',
            'Firmenkunde' => 'B2B',
            default => 'B2C',
        };

        $data = $this->vehicleService->findOrderDetail(
            $orderId,
            $belongs === 'ALL' ? null : $this->scope->resolveOwnerId($user),
            $belongs,
            $user,
        );

        abort_if($data === null, 404);

        return Inertia::render('orders/Show', [
            'vehicle' => $data['vehicle'],
            'order' => $data['order'],
        ]);
    }

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

        if ($vehicle->vehicle_belongs === 'B2B') {
            $validated = $request->validate(OrderCollectionService::b2bOrderRules());

            return $this->withServiceErrorHandling(
                'order',
                fn () => $this->orderService->createB2bCollectionOrder($vehicle, $user, $validated)
            ) ?? back()->with('success', 'Abholung wurde angefragt.');
        }

        $validated = $request->validate([
            'station_id' => 'required|uuid|exists:inspection_stations,station_id',
            'termin' => 'required|date',
            'remarks' => 'nullable|string',
            ...OrderCollectionService::customerRules(false),
        ]);

        $station = InspectionStation::find($validated['station_id']);

        $order = null;

        $denied = $this->withServiceErrorHandling(
            'order',
            function () use ($station, $vehicle, $user, $validated, &$order) {
                $order = $station->provider === 'tuvsud'
                    ? $this->orderService->createTuvsudOrder($vehicle, $user, $validated)
                    : $this->orderService->createOtherOrder($vehicle, $user, [...$validated, 'provider' => $station->provider]);
            }
        );

        if ($denied) {
            return $denied;
        }

        return back()
            ->with('success', 'Termin wurde gebucht.')
            ->with('order_created', $this->orderCreated->for($order, $user));
    }
}
