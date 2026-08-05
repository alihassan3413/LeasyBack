<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\InspectionStation;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use App\Services\Mail\OrderMailer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Order-creation logic extracted from the Sanctum API OrderController so
 * the session-authenticated web OrderController can reuse it without
 * duplicating the TÜV SÜD payload/HTTP-call/persistence logic. Both
 * controllers call these methods; neither reimplements them.
 */
class OrderService
{
    public const B2B_PARTNER = 'leasyback';

    public const B2B_ORDER_TYPE = 'b2b_collection';

    public function __construct(
        private readonly VehicleService $vehicleService,
        private readonly TransitionOrderStatus $transitionOrderStatus,
        private readonly OrderMailer $orderMailer,
        private readonly OrderCollectionService $orderCollectionService,
    ) {}

    /**
     * B2B return orders: the vehicle is collected at the customer's site, so
     * there is no inspection station, no TÜV SÜD appointment and no external
     * booking call. The order still lives in leasyback_orders with the same
     * statuses; the collection details are written to
     * leasyback_order_logistics by OrderCollectionService.
     *
     * request_payload is NOT NULL on the table, so a minimal marker is
     * stored rather than a fabricated TÜV SÜD request — nothing downstream
     * may mistake this for one.
     */
    public function createB2bCollectionOrder(Vehicle $vehicle, User $user, array $validated): LeasybackOrder
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            $this->fail(422, 'collection orders are only available for B2B vehicles');
        }

        if ($this->vehicleService->hasUnfinishedOrder($vehicle->vehicle_id)) {
            $this->fail(409, 'vehicle previous order not completed yet');
        }

        $auftragsnummer = $this->vehicleService->generateAuftragsnummer($vehicle->license_plate);

        $order = DB::transaction(function () use ($vehicle, $auftragsnummer, $user, $validated) {
            $order = LeasybackOrder::create([
                'vehicle_id' => $vehicle->vehicle_id,
                'auftragsnummer' => $auftragsnummer,
                'leasyback_partner' => self::B2B_PARTNER,
                'order_status' => 'order_requested',
                'request_payload' => ['order_type' => self::B2B_ORDER_TYPE],
                'created_by_user_id' => $user->id,
            ]);

            $this->orderCollectionService->recordCustomerRequest($order, $vehicle, $user, $validated);
            $this->auditOrder($order, 'REQUEST_ORDER', null, ['order_status' => 'order_requested'], $user->id);

            return $order;
        });

        $this->orderMailer->orderCreated($order, $vehicle);

        return $order;
    }

    /**
     * Book a TÜV SÜD inspection appointment. Firmenkunde bookings are
     * staged as order_requested (pending Admin approval, see
     * OrderController::approve); Privatkunde/Admin bookings are sent to
     * TÜV SÜD immediately and saved as order_placed.
     */
    public function createTuvsudOrder(Vehicle $vehicle, User $user, array $validated): LeasybackOrder
    {
        if ($vehicle->vehicle_belongs === 'B2B') {
            $this->fail(422, 'B2B vehicles use the collection order flow');
        }

        if ($this->vehicleService->hasUnfinishedOrder($vehicle->vehicle_id)) {
            $this->fail(409, 'vehicle previous order not completed yet');
        }

        $station = InspectionStation::where('station_id', $validated['station_id'])
            ->where('provider', 'tuvsud')
            ->where('is_active', true)
            ->first();

        if (! $station) {
            $this->fail(404, 'Inspection station not found');
        }

        $auftragsnummer = $this->vehicleService->generateAuftragsnummer($vehicle->license_plate);

        $requestPayload = [
            'auftrag' => [
                'produktkey' => config('services.tuvsud.product_key'),
                'fin' => $vehicle->vin ?? 'UNKNOWN',
                'kennzeichen' => $vehicle->license_plate,
                'hersteller' => $vehicle->make ?? '',
                'modell' => $vehicle->model ?? '',
                'vertragsnummer' => $auftragsnummer,
                'auftragsnummer' => $auftragsnummer,
                'bemerkung' => $validated['remarks'] ?? '',
            ],
            'ansprechpartner' => [
                'name' => 'Jannis Gremler',
                'telefon' => '01234 5678943',
                'email' => 'jannis.gremler@leasyback.de',
            ],
            'besichtigungsort' => [
                'termin' => $validated['termin'],
                'name' => $station->name,
                'strasse' => $station->strasse,
                'plz' => $station->plz,
                'ort' => $station->ort,
                'land' => 'de',
            ],
            'benachrichtigung' => [
                'terminbestätigung' => [],
                'gutachten' => [],
            ],
            'dokumente' => [],
        ];

        if ($user->user_type->value === 'Firmenkunde') {
            $order = DB::transaction(function () use ($vehicle, $auftragsnummer, $requestPayload, $user, $validated) {
                $order = LeasybackOrder::create([
                    'vehicle_id' => $vehicle->vehicle_id,
                    'auftragsnummer' => $auftragsnummer,
                    'leasyback_partner' => 'tuvsud',
                    'order_status' => 'order_requested',
                    'request_payload' => $requestPayload,
                    'created_by_user_id' => $user->id,
                ]);

                $this->orderCollectionService->recordCustomerRequest($order, $vehicle, $user, $validated);
                $this->auditOrder($order, 'REQUEST_ORDER', null, ['order_status' => 'order_requested'], $user->id);

                return $order;
            });

            $this->orderMailer->orderCreated($order, $vehicle);

            return $order;
        }

        $fullPayload = array_merge($requestPayload, [
            'authentifizierung' => [
                'benutzername' => config('services.tuvsud.username'),
                'token' => config('services.tuvsud.token'),
            ],
        ]);

        $response = Http::timeout(30)->post(config('services.tuvsud.url'), $fullPayload);
        $status = $response->status();
        $respJson = $response->json() ?? ['ok' => false, 'status' => $status];

        $order = DB::transaction(function () use ($vehicle, $auftragsnummer, $requestPayload, $status, $respJson, $user, $validated) {
            $order = LeasybackOrder::create([
                'vehicle_id' => $vehicle->vehicle_id,
                'auftragsnummer' => $auftragsnummer,
                'leasyback_partner' => 'tuvsud',
                'order_status' => 'order_placed',
                'request_payload' => $requestPayload,
                'response_status' => $status,
                'response_body' => $respJson,
                'created_by_user_id' => $user->id,
                'sent_at' => now(),
            ]);

            $this->orderCollectionService->recordCustomerRequest($order, $vehicle, $user, $validated);
            $this->auditOrder($order, 'CREATE_ORDER', null, ['order_status' => 'order_placed'], $user->id);

            return $order;
        });

        $this->orderMailer->orderCreated($order, $vehicle);

        return $order;
    }

    /**
     * Book an inspection with a non-TÜV-SÜD provider. No real external API
     * call exists for these providers today (matches the reference
     * system) — the order is saved directly as order_placed.
     */
    public function createOtherOrder(Vehicle $vehicle, User $user, array $validated): LeasybackOrder
    {
        if ($vehicle->vehicle_belongs === 'B2B') {
            $this->fail(422, 'B2B vehicles use the collection order flow');
        }

        $auftragsnummer = $this->vehicleService->generateAuftragsnummer($vehicle->license_plate);
        $station = InspectionStation::find($validated['station_id']);

        $requestPayload = [
            'auftrag' => [
                'fin' => $vehicle->vin ?? 'UNKNOWN',
                'kennzeichen' => $vehicle->license_plate,
                'auftragsnummer' => $auftragsnummer,
                'bemerkung' => $validated['remarks'] ?? '',
            ],
            'besichtigungsort' => [
                'termin' => $validated['termin'],
                'name' => $station?->name ?? '',
                'strasse' => $station?->strasse ?? '',
                'plz' => $station?->plz ?? '',
                'ort' => $station?->ort ?? '',
            ],
        ];

        $order = DB::transaction(function () use ($vehicle, $auftragsnummer, $validated, $requestPayload, $user) {
            $order = LeasybackOrder::create([
                'vehicle_id' => $vehicle->vehicle_id,
                'auftragsnummer' => $auftragsnummer,
                'leasyback_partner' => $validated['provider'],
                'order_status' => 'order_placed',
                'request_payload' => $requestPayload,
                'created_by_user_id' => $user->id,
                'sent_at' => now(),
            ]);

            $this->orderCollectionService->recordCustomerRequest($order, $vehicle, $user, $validated);
            $this->auditOrder($order, 'CREATE_ORDER', null, ['order_status' => 'order_placed'], $user->id);

            return $order;
        });

        $this->orderMailer->orderCreated($order, $vehicle);

        return $order;
    }

    /**
     * Send an order_requested order to TÜV SÜD and transition it to
     * order_placed on success. Extracted from the Sanctum OrderController
     * so the new Admin web OrderController (Checkpoint 11) can reuse the
     * exact same external-call/persistence logic. The caller is
     * responsible for the "is this order actually approvable right now"
     * pre-check (via TransitionOrderStatus::allowedNextStatuses) — this
     * method lets a genuine race-condition ValidationException from
     * __invoke() propagate uncaught, same as the original inline code did.
     */
    public function approveOrder(LeasybackOrder $order, User $user, ?string $callerIp): LeasybackOrder
    {
        if ($this->isB2bCollectionOrder($order)) {
            return $this->approveB2bCollectionOrder($order, $user, $callerIp);
        }

        $requestBody = $order->request_payload;
        $requestBody['authentifizierung'] = [
            'benutzername' => config('services.tuvsud.username'),
            'token' => config('services.tuvsud.token'),
        ];

        $response = Http::timeout(30)->post(config('services.tuvsud.url'), $requestBody);
        $status = $response->status();
        $respJson = $response->json() ?? ['ok' => false, 'status' => $status];

        $order = $this->transitionOrderStatus->__invoke(
            $order,
            'order_placed',
            'admin',
            $user->name ?? $user->email,
            $user->id,
            $callerIp,
            null,
            [
                'sent_at' => now(),
                'response_status' => $status,
                'response_body' => $respJson,
                'request_payload' => $requestBody,
            ],
        );

        // "Approval with its external-call context" — an audit_log-worthy
        // lifecycle event beyond the plain status flip TransitionOrderStatus
        // already recorded in leasyback_order_status_updates (see
        // docs/B2C_ADMIN_STATUS_MATRIX.md §6). TransitionOrderStatus also
        // already sent the customer-facing status-change notification;
        // approveOrder() doesn't send a second one.
        $this->auditOrder($order, 'APPROVE_ORDER', ['order_status' => 'order_requested'], ['order_status' => 'order_placed'], $user->id);

        return $order;
    }

    /**
     * The B2B counterpart of approveOrder(): the same order_requested →
     * order_placed transition and the same APPROVE_ORDER audit entry, with
     * no external booking call, because no appointment was ever requested
     * from TÜV SÜD. TransitionOrderStatus still sends the single
     * customer-facing status notification it always does.
     */
    private function approveB2bCollectionOrder(LeasybackOrder $order, User $user, ?string $callerIp): LeasybackOrder
    {
        $order = $this->transitionOrderStatus->__invoke(
            $order,
            'order_placed',
            'admin',
            $user->name ?? $user->email,
            $user->id,
            $callerIp,
        );

        $this->auditOrder($order, 'APPROVE_ORDER', ['order_status' => 'order_requested'], ['order_status' => 'order_placed'], $user->id);

        return $order;
    }

    /**
     * Resolved from the order's own record rather than the caller: whichever
     * entry point approves an order, a collection order must never reach the
     * TÜV SÜD call. The vehicle type is authoritative; the stored marker
     * covers orders whose vehicle row has since changed hands.
     */
    private function isB2bCollectionOrder(LeasybackOrder $order): bool
    {
        if (data_get($order->request_payload, 'order_type') === self::B2B_ORDER_TYPE) {
            return true;
        }

        return Vehicle::where('vehicle_id', $order->vehicle_id)->value('vehicle_belongs') === 'B2B'
            && $order->leasyback_partner === self::B2B_PARTNER;
    }

    /**
     * Best-effort order lifecycle audit entry — broader events that aren't
     * a pure order_status change (creation, approval, offer touchpoints),
     * per docs/B2C_ADMIN_STATUS_MATRIX.md §6. Written in the same
     * transaction as its triggering state change where the caller does
     * that (see createTuvsudOrder()/createOtherOrder()); approveOrder()
     * writes it just after TransitionOrderStatus's own transaction commits,
     * matching how that method already isn't wrapped in a further
     * transaction here.
     */
    private function auditOrder(LeasybackOrder $order, string $action, ?array $old, ?array $new, ?int $userId): void
    {
        OrderAuditLog::create([
            'order_id' => $order->id,
            'vehicle_id' => $order->vehicle_id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'changed_by_user_id' => $userId,
        ]);
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
