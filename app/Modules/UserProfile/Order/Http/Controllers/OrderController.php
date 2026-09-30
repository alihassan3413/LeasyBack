<?php

namespace App\Modules\UserProfile\Order\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\InspectionStation;
use App\Models\LeasybackOrder;
use App\Models\OrderConfirmation;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use App\Modules\UserProfile\Order\Services\OrderService;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Support\PartnerLifecyclePermissions;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            ...OrderCollectionService::customerRules(false),
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
     * POST /order/b2b/create/{vehicleId} — B2B collection order. No station,
     * no appointment, no external call; staged as order_requested for Admin.
     */
    public function createB2bCollection(Request $request, string $vehicleId): JsonResponse
    {
        $user = $request->user();
        $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);

        if (! $vehicle) {
            return response()->json(['error' => 'Vehicle not found or access denied'], 404);
        }

        $validated = $request->validate(OrderCollectionService::b2bOrderRules());

        try {
            $order = $this->orderService->createB2bCollectionOrder($vehicle, $user, $validated);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return response()->json([
            'message' => 'Collection order request created successfully',
            'auftragsnummer' => $order->auftragsnummer,
            'order_id' => $order->id,
            'order_status' => $order->order_status,
        ]);
    }

    /**
     * POST /order/b2b/relocation/{vehicleId} (API)
     * POST /orders/b2b/relocation/{vehicleId} (portal)
     *
     * Überführung for one vehicle. Kept for the external SPA and for anything
     * still posting to the per-vehicle URL.
     */
    public function createB2bRelocation(Request $request, string $vehicleId): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        Log::info('RELOCATION HIT', ['vehicle_ids' => [$vehicleId], 'user_id' => $user?->id]);

        $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);
        abort_if($vehicle === null, 404);

        $details = $this->validatedRelocationDetails($request);

        $orders = $this->orderService->createB2bRelocationOrders([$vehicle], $user, $details);

        return $this->relocationResponse($request, $orders);
    }

    /**
     * POST /orders/b2b/relocation (portal)
     *
     * Überführung for several vehicles in one booking. One order is created
     * per vehicle, all with the same addresses, time window, contacts,
     * billing address and cost centre.
     */
    public function createB2bRelocationBatch(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $vehicleIds = $request->validate([
            'vehicle_ids' => ['required', 'array', 'min:1', 'max:'.OrderService::MAX_RELOCATION_VEHICLES],
            'vehicle_ids.*' => ['required', 'uuid', 'distinct'],
        ])['vehicle_ids'];

        Log::info('RELOCATION HIT', ['vehicle_ids' => $vehicleIds, 'user_id' => $user?->id]);

        // Every vehicle must be one this user may reach — the same check the
        // single-vehicle route applies, once per vehicle.
        $vehicles = array_map(function (string $vehicleId) use ($user) {
            $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);
            abort_if($vehicle === null, 404);

            return $vehicle;
        }, $vehicleIds);

        $details = $this->validatedRelocationDetails($request);

        $orders = $this->orderService->createB2bRelocationOrders($vehicles, $user, $details);

        return $this->relocationResponse($request, $orders);
    }

    /**
     * The booking details shared by both relocation routes. `vehicle_ids` is
     * not part of these rules, so it never ends up in the stored payload.
     *
     * Accepts either the portal's `time_from`/`time_to` window or the older
     * fixed `time_slot` (still sent by the external SPA).
     *
     * @return array<string, mixed>
     */
    private function validatedRelocationDetails(Request $request): array
    {
        $validated = $request->validate([
            'pickup_address' => ['required', 'array'],
            'pickup_address.street' => ['required', 'string', 'max:255'],
            'pickup_address.number' => ['nullable', 'string', 'max:20'],
            'pickup_address.zip_code' => ['required', 'string', 'max:10'],
            'pickup_address.city' => ['required', 'string', 'max:255'],
            'pickup_address.country' => ['nullable', 'string', 'max:100'],

            'destination_address' => ['required', 'array'],
            'destination_address.street' => ['required', 'string', 'max:255'],
            'destination_address.number' => ['nullable', 'string', 'max:20'],
            'destination_address.zip_code' => ['required', 'string', 'max:10'],
            'destination_address.city' => ['required', 'string', 'max:255'],
            'destination_address.country' => ['nullable', 'string', 'max:100'],

            'preferred_date' => ['required', 'date', 'after_or_equal:today'],
            'time_from' => ['nullable', 'date_format:H:i', 'required_without:time_slot'],
            'time_to' => ['nullable', 'date_format:H:i', 'required_with:time_from'],
            'time_slot' => ['nullable', 'string', 'max:20', 'required_without:time_from'],

            'pickup_contact' => ['nullable', 'array'],
            'pickup_contact.name' => ['nullable', 'string', 'max:255'],
            'pickup_contact.phone' => ['nullable', 'string', 'max:50'],
            'pickup_contact.email' => ['nullable', 'email', 'max:255'],

            'destination_contact' => ['nullable', 'array'],
            'destination_contact.name' => ['nullable', 'string', 'max:255'],
            'destination_contact.phone' => ['nullable', 'string', 'max:50'],
            'destination_contact.email' => ['nullable', 'email', 'max:255'],

            'billing_address' => ['nullable', 'array'],
            'billing_address.name' => ['nullable', 'string', 'max:255'],
            'billing_address.street' => ['nullable', 'string', 'max:255'],
            'billing_address.number' => ['nullable', 'string', 'max:20'],
            'billing_address.zip_code' => ['nullable', 'string', 'max:10'],
            'billing_address.city' => ['nullable', 'string', 'max:255'],
            'billing_address.country' => ['nullable', 'string', 'max:100'],

            'cost_centre' => ['nullable', 'array'],
            'cost_centre.name' => ['nullable', 'string', 'max:255'],
            'cost_centre.number' => ['nullable', 'string', 'max:100'],

            'vehicle_ready' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $errors = [];

        // "Mindestens 2 Stunden nach Startzeit"
        if (! empty($validated['time_from']) && ! empty($validated['time_to'])) {
            $toMinutes = fn (string $time): int => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);

            if ($toMinutes($validated['time_to']) - $toMinutes($validated['time_from']) < 120) {
                $errors['time_to'] = 'Das Zeitfenster muss mindestens 2 Stunden umfassen.';
            }
        }

        // The billing address is optional — but once started, it must be usable.
        $billing = (array) ($validated['billing_address'] ?? []);
        $billingStarted = collect($billing)->except('country')->filter(fn ($value) => filled($value))->isNotEmpty();

        if ($billingStarted) {
            foreach (['street' => 'Straße', 'zip_code' => 'PLZ', 'city' => 'Ort'] as $field => $label) {
                if (blank($billing[$field] ?? null)) {
                    $errors["billing_address.{$field}"] = "{$label} der Rechnungsadresse fehlt.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    /**
     * Portal (Inertia): back to the page with a flash message.
     * External SPA (Sanctum): JSON with the new order ids.
     *
     * The orders are not type-hinted: the service returns the canonical
     * order model, while this controller's `LeasybackOrder` import is the
     * App\Models subclass of it.
     *
     * @param  list<\App\Modules\UserProfile\Order\Models\LeasybackOrder>  $orders
     */
    private function relocationResponse(Request $request, array $orders): JsonResponse|RedirectResponse
    {
        $orderIds = array_map(fn ($order) => $order->id, $orders);
        $count = count($orders);

        Log::info('RELOCATION ORDER CREATED', ['order_ids' => $orderIds]);

        $message = $count === 1
            ? 'Die Überführung wurde erfolgreich beauftragt.'
            : "{$count} Überführungen wurden erfolgreich beauftragt.";

        if ($request->header('X-Inertia')) {
            return back()->with('success', $message);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'order_id' => $orderIds[0] ?? null,
                'order_ids' => $orderIds,
            ],
            'message' => $message,
        ], 201);
    }

    /**
     * GET /order/tuvsud/confirm — external callback. API-key auth is
     * enforced by the `tuvsud.webhook` route middleware, not inline here.
     *
     * The status it sets is fixed in code, so nothing a caller sends can
     * redirect it — but it still asks the same ownership question status()
     * asks, so "may this integration confirm an appointment" has exactly one
     * answer rather than one per endpoint.
     */
    public function confirm(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnownedTransition($request, OrderStatus::Confirmed->value)) {
            return $denied;
        }

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

        $target = PartnerLifecyclePermissions::resolveStatus($newStatus);

        if ($target === null) {
            return response()->json(['error' => 'Invalid status value'], 422);
        }

        $order = LeasybackOrder::where('auftragsnummer', $auftragsnummer)->first();
        if (! $order) {
            return response()->json(['error' => 'Auftragsnummer not found'], 404);
        }

        // Both questions, in this order: does the edge exist at all, and does
        // this caller own it. Asking about the graph first keeps an illegal
        // jump reporting as an illegal jump rather than as a permission
        // problem, which is what it is for any actor.
        if (! $this->isReachable($order, $target)) {
            return response()->json([
                'error' => "Cannot transition order from '{$order->order_status}' to '{$target}'.",
            ], 422);
        }

        if ($denied = $this->denyUnownedTransition($request, $target)) {
            return $denied;
        }

        try {
            $this->transitionOrderStatus->__invoke(
                $order,
                $target,
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
     * Is this edge on the canonical graph at all?
     *
     * Re-sending the status the order already holds counts: TransitionOrderStatus
     * treats it as a no-op rather than an error precisely so a redelivered
     * callback does not fail for doing nothing, and that has to survive this
     * gate or a provider's retry would start 422-ing.
     *
     * Uses the same graph TransitionOrderStatus uses for this order — an
     * Überführung has its own shorter path.
     */
    private function isReachable(LeasybackOrder $order, string $target): bool
    {
        if ($order->order_status === $target) {
            return true;
        }

        return in_array(
            $target,
            TransitionOrderStatus::allowedNextStatuses(
                $order->order_status,
                TransitionOrderStatus::isB2bOrder($order),
                TransitionOrderStatus::isRelocationOrder($order),
            ),
            true,
        );
    }

    /**
     * Does the authenticated integration own this transition? The provider is
     * read from the request attribute its middleware set, so a caller cannot
     * name itself.
     */
    private function denyUnownedTransition(Request $request, string $target): ?JsonResponse
    {
        $provider = (string) $request->attributes->get(PartnerLifecyclePermissions::REQUEST_ATTRIBUTE, '');

        if (PartnerLifecyclePermissions::owns($provider, $target)) {
            return null;
        }

        // Deliberately says only that the transition is not this caller's to
        // make. Which statuses exist, and where the order currently stands,
        // are not an unauthorized caller's business.
        return response()->json([
            'error' => "This integration may not set order status '{$target}'.",
        ], 403);
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

        $allowed = TransitionOrderStatus::allowedNextStatuses(
            $order->order_status,
            TransitionOrderStatus::isB2bOrder($order),
            TransitionOrderStatus::isRelocationOrder($order),
        );

        if (! in_array('order_placed', $allowed, true)) {
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
            ...OrderCollectionService::customerRules(false),
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