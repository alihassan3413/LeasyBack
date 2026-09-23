<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\OrderStatus;
use App\Models\InspectionStation;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use App\Services\Mail\OrderMailer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        private readonly OrderNumberGenerator $orderNumbers,
        private readonly PartnerWebhookEvents $webhooks,
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

        $this->assertVehicleIsFree($vehicle);

        $auftragsnummer = $this->reserveOrderNumber($vehicle, $user);

        $order = DB::transaction(function () use ($vehicle, $auftragsnummer, $user, $validated) {
            $order = $this->insertOrder([
                'vehicle_id' => $vehicle->vehicle_id,
                'auftragsnummer' => $auftragsnummer,
                'leasyback_partner' => self::B2B_PARTNER,
                'order_status' => 'order_requested',
                'request_payload' => ['order_type' => self::B2B_ORDER_TYPE],
                'created_by_user_id' => $user->id,
            ]);

            $this->orderCollectionService->recordCustomerRequest($order, $vehicle, $user, $validated);
            $this->auditOrder($order, 'REQUEST_ORDER', null, ['order_status' => 'order_requested'], $user->id);

            // In the transaction, so an order that rolls back is never
            // announced. Delivery is queued after commit — see
            // PartnerWebhookEmitter.
            $this->webhooks->orderCreated($order);

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

        $this->assertVehicleIsFree($vehicle);

        $station = InspectionStation::where('station_id', $validated['station_id'])
            ->where('provider', 'tuvsud')
            ->where('is_active', true)
            ->first();

        if (! $station) {
            $this->fail(404, 'Inspection station not found');
        }

        $auftragsnummer = $this->reserveOrderNumber($vehicle, $user);

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
                $order = $this->insertOrder([
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
            $order = $this->insertOrder([
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

        $this->assertVehicleIsFree($vehicle);

        $auftragsnummer = $this->reserveOrderNumber($vehicle, $user);
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
            $order = $this->insertOrder([
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
     * exact same external-call/persistence logic.
     *
     * The order only moves once TÜV SÜD has accepted the booking. The
     * integration contract this app relies on is the HTTP layer — the portal's
     * response body carries no documented success field, and nothing in this
     * app has ever interpreted one — so a 2xx answer is a booking, and a
     * non-2xx answer, a timeout or any other transport error is not. On
     * failure the order stays exactly as it was (`order_requested`, no
     * `sent_at`, no response recorded), the failed attempt is audited, and the
     * caller receives an HttpResponseException (502) to report.
     *
     * Two guards keep a retry from booking twice:
     * - a short lock per order serialises concurrent approvals, and
     * - the order's status is re-checked inside that lock, so an approval that
     *   queued behind a successful one is refused (ValidationException, as
     *   before) instead of sending a second booking.
     *
     * The TÜV SÜD credentials are added only to the outgoing request. They are
     * never written to `request_payload`, which is shown to customers.
     */
    public function approveOrder(LeasybackOrder $order, User $user, ?string $callerIp): LeasybackOrder
    {
        if ($this->isB2bCollectionOrder($order)) {
            return $this->approveB2bCollectionOrder($order, $user, $callerIp);
        }

        $lock = Cache::lock('tuvsud-booking:'.$order->id, 60);

        if (! $lock->get()) {
            $this->fail(409, 'Die Buchung bei TÜV SÜD für diesen Auftrag läuft bereits. Bitte versuchen Sie es in einem Moment erneut.');
        }

        try {
            $order = $order->fresh() ?? $order;

            if (! in_array(OrderStatus::OrderPlaced->value, TransitionOrderStatus::allowedNextStatuses($order->order_status), true)) {
                throw ValidationException::withMessages([
                    'order_status' => "Cannot transition order from '{$order->order_status}' to 'order_placed'.",
                ]);
            }

            $bookingPayload = self::withoutTuvsudCredentials((array) ($order->request_payload ?? []));
            $response = $this->bookWithTuvsud($order, $bookingPayload, $user);
            $status = $response->status();

            $order = $this->transitionOrderStatus->__invoke(
                $order,
                OrderStatus::OrderPlaced->value,
                'admin',
                $user->name ?? $user->email,
                $user->id,
                $callerIp,
                null,
                [
                    'sent_at' => now(),
                    'response_status' => $status,
                    'response_body' => $response->json() ?? ['ok' => false, 'status' => $status],
                    // Rewritten without credentials, which also cleans a row
                    // stored by earlier code that did include them.
                    'request_payload' => $bookingPayload,
                ],
            );
        } finally {
            $lock->release();
        }

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
     * The booking payload as it may be stored and shown: everything the
     * application reads (`auftrag`, `besichtigungsort`, …) without the
     * `authentifizierung` block carrying the TÜV SÜD username and token.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withoutTuvsudCredentials(array $payload): array
    {
        unset($payload['authentifizierung']);

        return $payload;
    }

    /**
     * One booking request. Returns the response only when TÜV SÜD accepted it;
     * every other outcome is audited and turned into a 502 for the caller,
     * before anything about the order has been written.
     *
     * @param  array<string, mixed>  $bookingPayload
     */
    private function bookWithTuvsud(LeasybackOrder $order, array $bookingPayload, User $user): Response
    {
        try {
            $response = Http::timeout(30)->post(config('services.tuvsud.url'), [
                ...$bookingPayload,
                'authentifizierung' => [
                    'benutzername' => config('services.tuvsud.username'),
                    'token' => config('services.tuvsud.token'),
                ],
            ]);
        } catch (Throwable $e) {
            $this->recordFailedBooking($order, $user, null, $e::class);

            $this->fail(502, 'TÜV SÜD ist derzeit nicht erreichbar. Der Auftrag wurde nicht gebucht und bleibt angefragt.');
        }

        if (! $response->successful()) {
            $this->recordFailedBooking($order, $user, $response->status(), null);

            $this->fail(502, sprintf(
                'TÜV SÜD hat die Buchung nicht angenommen (HTTP %d). Der Auftrag wurde nicht gebucht und bleibt angefragt.',
                $response->status(),
            ));
        }

        return $response;
    }

    /**
     * Only the outcome is recorded — never the request or TÜV SÜD's response
     * body, either of which may echo the credentials.
     */
    private function recordFailedBooking(LeasybackOrder $order, User $user, ?int $httpStatus, ?string $exception): void
    {
        Log::warning('TÜV SÜD booking failed; order left unchanged', [
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'http_status' => $httpStatus,
            'exception' => $exception,
        ]);

        $this->auditOrder($order, 'TUVSUD_BOOKING_FAILED', ['order_status' => $order->order_status], [
            'http_status' => $httpStatus,
            'exception' => $exception,
        ], $user->id);
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
     * The one expression of "a vehicle gets one order, and only a cancelled
     * one may be replaced", applied by every creation path — customer, Admin
     * and Partner API — and to both channels.
     *
     * Widened from "at most one *active* order": a completed order now bars a
     * new one too, so a car cannot be put through the process twice. Only a
     * cancelled or discarded order leaves the vehicle free, which is what makes
     * calling an order off recoverable without making completion so.
     *
     * This pre-check exists to produce a useful 409 in the ordinary case —
     * before a reference is reserved and before an external booking call goes
     * out. It cannot be the whole guarantee: two requests can both read the
     * vehicle as free before either writes, and the deployment target is
     * sqlite, where lockForUpdate() compiles to nothing. insertOrder() closes
     * that window on the unique index, which still covers it: every order is
     * created active, so two concurrent creations always collide there
     * regardless of what the losing history looked like.
     */
    private function assertVehicleIsFree(Vehicle $vehicle): void
    {
        if ($this->vehicleService->blocksNewOrder($vehicle->vehicle_id)) {
            $this->failActiveOrderExists();
        }
    }

    /**
     * The single writer of `leasyback_orders`, so the race the pre-check
     * cannot cover has exactly one place to surface.
     *
     * A caller that loses the race hits the unique index on
     * `active_vehicle_id` and gets back the same 409 the pre-check produces,
     * so no caller can tell a lost race from a plain duplicate — and the
     * Partner API keeps mapping it to `order_already_open` unchanged. Any
     * other unique violation on this table (`auftragsnummer`) is a real bug
     * and is left to surface as one.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertOrder(array $attributes): LeasybackOrder
    {
        try {
            return LeasybackOrder::create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'active_vehicle_id')) {
                throw $e;
            }

            $this->failActiveOrderExists();
        }
    }

    /**
     * The wording had to widen with the rule: "not completed yet" was the
     * whole reason a caller was refused, and now completion is one of the
     * reasons. The 409 and the Partner API's `order_already_open` code are
     * deliberately unchanged — they are a published contract, and the
     * condition they describe (this vehicle cannot take another order) is
     * still exactly what happened.
     */
    private function failActiveOrderExists(): never
    {
        $this->fail(409, 'vehicle already has an order that cannot be replaced');
    }

    /**
     * Claim this order's reference before anything is written or sent.
     *
     * Every creation path goes through here, so B2C inspections, B2B
     * collections and Partner API creations all draw from one sequence and
     * cannot collide with each other. Deliberately outside the surrounding
     * transaction: the reservation must survive a rolled-back creation, or two
     * concurrent callers would both see the number as free.
     */
    private function reserveOrderNumber(Vehicle $vehicle, User $user): string
    {
        return $this->orderNumbers->reserve(
            $vehicle->license_plate,
            $vehicle->vehicle_id,
            $user->id,
        );
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
