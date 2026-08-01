<?php

namespace App\Modules\UserProfile\Order\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\InspectionStation;
use App\Models\LeasybackOrder;
use App\Models\OrderConfirmation;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Services\OrderService;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private VehicleScopeService $scope,
        private TransitionOrderStatus $transitionOrderStatus,
        private OrderService $orderService,
    ) {}

    /**
     * POST /order/tuvsud/create/{vehicleId}
     */
    public function createTuvsud(Request $request, string $vehicleId): JsonResponse
    {
        $user = $request->user();
        $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);

        if (! $vehicle) {
            return response()->json(['error' => 'Vehicle not found or access denied'], 404);
        }

        $validated = $request->validate([
            'station_id' => 'required|uuid',
            'termin' => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        try {
            $order = $this->orderService->createTuvsudOrder($vehicle, $user, $validated);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        if ($order->order_status === 'order_requested') {
            return response()->json([
                'message' => 'Order request created successfully',
                'auftragsnummer' => $order->auftragsnummer,
                'order_status' => 'order_requested',
            ]);
        }

        return response()->json([
            'auftragsnummer' => $order->auftragsnummer,
            'status' => $order->response_status,
            'response' => $order->response_body,
        ]);
    }

    /**
     * GET /order/tuvsud/confirm — external callback. API-key auth is
     * enforced by the `tuvsud.webhook` route middleware, not inline here.
     */
    public function confirm(Request $request): JsonResponse
    {
        $auftragsnummer = $request->query('auftragsnummer');
        if (! $auftragsnummer) {
            return response()->json(['error' => 'auftragsnummer is required'], 400);
        }

        $order = LeasybackOrder::where('auftragsnummer', $auftragsnummer)->first();
        if (! $order) {
            return response()->json([
                'status' => 'error',
                'message' => "Auftragsnummer '{$auftragsnummer}' not found",
            ], 400);
        }

        // Parse confirmation date
        $datetimeStr = $request->query('datetime');
        if ($datetimeStr && trim($datetimeStr) !== '') {
            try {
                $confirmationDate = Carbon::createFromFormat('Y-m-d h:i A', trim($datetimeStr), 'Europe/Berlin')
                    ->utc();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Invalid datetime format. Expected: YYYY-MM-DD HH:MM AM/PM'], 400);
            }
        } else {
            // Get termin from request_payload
            $termin = data_get($order->request_payload, 'besichtigungsort.termin');
            $confirmationDate = $termin ? Carbon::parse($termin)->utc() : now();
        }

        try {
            DB::transaction(function () use ($order, $request, $auftragsnummer, $confirmationDate) {
                $this->transitionOrderStatus->__invoke($order, 'confirmed', 'api_key', 'tuvsud', null, $request->ip());

                OrderConfirmation::updateOrCreate(
                    ['auftragsnummer' => $auftragsnummer],
                    [
                        'confirmation_date' => $confirmationDate,
                        'confirmed_by_type' => 'api_key',
                        'confirmed_by_name' => 'tuvsud',
                    ]
                );
            });
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Confirmation stored successfully',
        ]);
    }

    /**
     * GET /order/tuvsud/status — external status update callback. API-key
     * auth is enforced by the `tuvsud.webhook` route middleware.
     */
    public function status(Request $request): JsonResponse
    {
        $auftragsnummer = $request->query('auftragsnummer');
        $newStatus = $request->query('status');
        $bewertungId = $request->query('bewertung_id');

        if (! $auftragsnummer || ! $newStatus) {
            return response()->json(['error' => 'auftragsnummer and status are required'], 400);
        }

        if (OrderStatus::tryFrom($newStatus) === null) {
            return response()->json(['error' => 'Invalid status value'], 422);
        }

        $order = LeasybackOrder::where('auftragsnummer', $auftragsnummer)->first();
        if (! $order) {
            return response()->json(['error' => 'Auftragsnummer not found'], 404);
        }

        try {
            $this->transitionOrderStatus->__invoke(
                $order,
                $newStatus,
                'api_key',
                'tuvsud_api_key',
                null,
                $request->ip(),
                $bewertungId,
                $bewertungId ? ['response_body' => $bewertungId] : [],
            );
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'message' => 'Status updated']);
    }

    /**
     * POST /order/tuvsud/order/approve/{orderId} — Admin approves B2B order
     */
    public function approve(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('approve', LeasybackOrder::class)) {
            return response()->json(['error' => 'Only admin can approve order requests'], 403);
        }

        $order = LeasybackOrder::find($orderId);
        if (! $order) {
            return response()->json(['error' => 'Order request not found'], 404);
        }

        if (! in_array('order_placed', TransitionOrderStatus::allowedNextStatuses($order->order_status), true)) {
            return response()->json([
                'error' => 'Only order_requested orders can be approved',
                'current_status' => $order->order_status,
            ], 400);
        }

        try {
            $order = $this->orderService->approveOrder($order, $user, $request->ip());
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Only order_requested orders can be approved',
                'current_status' => $order->fresh()->order_status,
            ], 400);
        }

        return response()->json([
            'message' => 'Order approved and sent to TUV SÜD successfully',
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'order_status' => 'order_placed',
            'tuvsud_status' => $order->response_status,
            'tuvsud_response' => $order->response_body,
        ]);
    }

    /**
     * GET /order/stations/{provider}
     */
    public function stationsByProvider(Request $request, string $provider): JsonResponse
    {
        $stations = InspectionStation::where('provider', strtolower($provider))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['station_id', 'provider', 'name', 'strasse', 'plz', 'ort', 'bundesland', 'land']);

        return response()->json($stations);
    }

    /**
     * GET /order/stations
     */
    public function allStations(Request $request): JsonResponse
    {
        $stations = InspectionStation::where('is_active', true)
            ->orderBy('provider')
            ->orderBy('name')
            ->get(['station_id', 'provider', 'name', 'strasse', 'plz', 'ort', 'bundesland', 'land']);

        return response()->json($stations);
    }

    /**
     * POST /order/stations/create
     */
    public function createStation(Request $request): JsonResponse
    {
        if (! $request->user()->can('createStation', LeasybackOrder::class)) {
            return response()->json(['error' => 'Only admin can create inspection stations'], 403);
        }

        $validated = $request->validate([
            'provider' => 'nullable|string',
            'name' => 'required|string',
            'strasse' => 'required|string',
            'plz' => 'required|string',
            'ort' => 'required|string',
            'bundesland' => 'nullable|string',
            'land' => 'nullable|string',
        ]);

        $station = InspectionStation::create([
            'provider' => strtolower($validated['provider'] ?? 'tuvsud'),
            'name' => trim($validated['name']),
            'strasse' => trim($validated['strasse']),
            'plz' => trim($validated['plz']),
            'ort' => trim($validated['ort']),
            'bundesland' => isset($validated['bundesland']) ? trim($validated['bundesland']) : null,
            'land' => strtolower($validated['land'] ?? 'de'),
        ]);

        return response()->json($station, 201);
    }

    /**
     * POST /order/others/create/{vehicleId}
     */
    public function createOther(Request $request, string $vehicleId): JsonResponse
    {
        $user = $request->user();
        $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);

        if (! $vehicle) {
            return response()->json(['error' => 'Vehicle not found or access denied'], 404);
        }

        $validated = $request->validate([
            'provider' => 'required|string',
            'station_id' => 'required|uuid',
            'termin' => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        $order = $this->orderService->createOtherOrder($vehicle, $user, $validated);

        return response()->json([
            'message' => 'Order created',
            'auftragsnummer' => $order->auftragsnummer,
            'order_id' => $order->id,
        ]);
    }

    /**
     * POST /order/others/confirm
     */
    public function confirmOther(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('confirm', LeasybackOrder::class)) {
            return response()->json(['error' => 'Only admin can confirm orders'], 403);
        }

        $validated = $request->validate([
            'auftragsnummer' => 'required|string',
            'confirmation_date' => 'required|date',
        ]);

        $order = LeasybackOrder::where('auftragsnummer', $validated['auftragsnummer'])->first();
        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        try {
            DB::transaction(function () use ($order, $validated, $user) {
                $this->transitionOrderStatus->__invoke($order, 'confirmed', 'admin', $user->name ?? $user->email, $user->id);

                OrderConfirmation::updateOrCreate(
                    ['auftragsnummer' => $validated['auftragsnummer']],
                    [
                        'confirmation_date' => Carbon::parse($validated['confirmation_date']),
                        'confirmed_by_type' => 'admin',
                        'confirmed_by_user_id' => $user->id,
                        'confirmed_by_name' => $user->name ?? $user->email,
                    ]
                );
            });
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Order confirmed',
        ]);
    }
}
