<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\OrderStatus;
use App\Models\InspectionStation;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\CompanyBillingAddress;
use App\Modules\UserProfile\Order\Models\CompanyCostCentre;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAttachment;
use App\Modules\UserProfile\Order\Models\OrderVehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use App\Services\Mail\OrderMailer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
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

    public const B2B_RELOCATION_ORDER_TYPE = 'vehicle_relocation';

    public const ACCIDENT_DAMAGE_ORDER_TYPE = 'accident_damage';

    public const APPRAISAL_ORDER_TYPE = 'vehicle_appraisal';

    public const SERVICE_LEASING_RETURN = 'leasingrueckgabe';

    public const SERVICE_RELOCATION = 'ueberfuehrung';

    public const SERVICE_ACCIDENT_DAMAGE = 'unfallschaden';

    /** Vehicle Condition Appraisal ("Gutachten"): one order for one or more vehicles. */
    public const SERVICE_APPRAISAL = 'gutachten';

    /** Upper bound for one appraisal order. */
    public const MAX_APPRAISAL_VEHICLES = 25;

    /** Upper bound for one multi-vehicle Überführung booking. */
    public const MAX_RELOCATION_VEHICLES = 25;

    /** Accident Damage uploads: per file (KB, as Laravel's `max` rule reads it) and per order. */
    public const MAX_ATTACHMENT_KB = 20480;

    public const MAX_ATTACHMENTS = 10;

    /** Form-only switches that steer saving, never stored on the order. */
    private const ACCIDENT_FORM_FLAGS = ['save_billing_address', 'billing_address_default', 'save_cost_centre', 'vehicle_id', 'vehicle_ids', 'files'];

    public function __construct(
        private readonly VehicleService $vehicleService,
        private readonly TransitionOrderStatus $transitionOrderStatus,
        private readonly OrderMailer $orderMailer,
        private readonly OrderCollectionService $orderCollectionService,
        private readonly OrderNumberGenerator $orderNumbers,
        private readonly PartnerWebhookEvents $webhooks,
    ) {}

    /**
     * Unfallschaden for exactly one vehicle (Accident Damage brief,
     * 17 September 2026).
     *
     * The whole report lives in `request_payload`, marked with its own order
     * type; the supporting files are stored on the `documents` disk and
     * recorded in leasyback_order_attachments. Everything is written in one
     * transaction, and files already stored are removed again if anything
     * fails, so a failed submission leaves neither an order nor stray files.
     *
     * Duplicate submissions are refused by the same rule every order obeys:
     * a vehicle with a running order cannot take another one.
     *
     * @param  array<string, mixed>  $details  validated form data
     * @param  list<UploadedFile>  $files
     */
    public function createAccidentDamageOrder(Vehicle $vehicle, User $user, array $details, array $files = []): LeasybackOrder
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            $this->fail(422, 'accident damage orders are only available for B2B vehicles');
        }

        $this->assertVehicleIsFree($vehicle);

        $this->rememberCompanyBillingAndCostCentre((string) $vehicle->b2b_id, $user, $details);

        $payload = self::normaliseAccidentDamageDetails($details);
        $auftragsnummer = $this->reserveOrderNumber($vehicle, $user);
        $storedPaths = [];

        try {
            $order = DB::transaction(function () use ($vehicle, $user, $payload, $files, $auftragsnummer, &$storedPaths) {
                $order = $this->insertOrder([
                    'vehicle_id' => $vehicle->vehicle_id,
                    'auftragsnummer' => $auftragsnummer,
                    'leasyback_partner' => self::B2B_PARTNER,
                    'order_status' => 'order_requested',
                    'service_type' => self::SERVICE_ACCIDENT_DAMAGE,
                    'request_payload' => [
                        'order_type' => self::ACCIDENT_DAMAGE_ORDER_TYPE,
                        ...$payload,
                    ],
                    'created_by_user_id' => $user->id,
                ]);

                foreach ($files as $file) {
                    $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
                    $path = $file->storeAs('accident-damage/'.$order->id, Str::uuid().'.'.$extension, OrderAttachment::DISK);

                    if ($path === false) {
                        throw new RuntimeException('An accident damage file could not be stored.');
                    }

                    $storedPaths[] = $path;

                    OrderAttachment::create([
                        'order_id' => $order->id,
                        'auftragsnummer' => $order->auftragsnummer,
                        'kind' => OrderAttachment::KIND_CUSTOMER_UPLOAD,
                        'original_name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'mime_type' => $file->getClientMimeType(),
                        'size' => (int) $file->getSize(),
                        'uploaded_by_user_id' => $user->id,
                    ]);
                }

                $this->auditOrder($order, 'REQUEST_ACCIDENT_DAMAGE', null, [
                    'order_status' => 'order_requested',
                    'service_type' => self::SERVICE_ACCIDENT_DAMAGE,
                    'attachments' => count($files),
                ], $user->id);

                return $order;
            });
        } catch (Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk(OrderAttachment::DISK)->delete($path);
            }

            throw $e;
        }

        // Customer confirmation and the operations team's notification
        // (OrderMailer::orderCreated sends both).
        $this->orderMailer->orderCreated($order, $vehicle);

        return $order;
    }

    /**
     * Vehicle Condition Appraisal (brief of 17 September 2026): ONE order with
     * one order number for every selected vehicle.
     *
     * The first vehicle is stored on the order row, as for every order; all of
     * them — the first included — are listed in leasyback_order_vehicles, which
     * is what holds each one (VehicleService::blocksNewOrder()) and shows the
     * order on each vehicle's page. Every vehicle is checked before anything is
     * written, so a busy one refuses the whole booking and names it; the unique
     * slot on leasyback_order_vehicles backs that up against a race.
     *
     * Location, logistics, leasing company and appointment are the same for
     * all vehicles of the order (brief: "Keep the same vehicle location,
     * logistics selection and appointment details for all vehicles").
     *
     * @param  list<Vehicle>  $vehicles
     * @param  array<string, mixed>  $details  validated form data, without vehicle ids
     */
    public function createVehicleAppraisalOrder(array $vehicles, User $user, array $details): LeasybackOrder
    {
        if ($vehicles === []) {
            throw ValidationException::withMessages(['vehicle_ids' => 'Bitte wählen Sie mindestens ein Fahrzeug.']);
        }

        foreach ($vehicles as $vehicle) {
            if ($vehicle->vehicle_belongs !== 'B2B') {
                throw ValidationException::withMessages([
                    'vehicle_ids' => "Gutachten sind nur für Firmenfahrzeuge verfügbar ({$vehicle->license_plate}).",
                ]);
            }

            if ($this->vehicleService->blocksNewOrder($vehicle->vehicle_id)) {
                throw ValidationException::withMessages([
                    'vehicle_ids' => "Für das Fahrzeug {$vehicle->license_plate} läuft bereits ein Auftrag.",
                ]);
            }
        }

        $first = $vehicles[0];

        $this->rememberCompanyBillingAndCostCentre((string) $first->b2b_id, $user, $details);

        $payload = self::normaliseAppraisalDetails($details);
        $auftragsnummer = $this->reserveOrderNumber($first, $user);

        try {
            $order = DB::transaction(function () use ($vehicles, $first, $user, $payload, $auftragsnummer) {
                $order = $this->insertOrder([
                    'vehicle_id' => $first->vehicle_id,
                    'auftragsnummer' => $auftragsnummer,
                    'leasyback_partner' => self::B2B_PARTNER,
                    'order_status' => 'order_requested',
                    'service_type' => self::SERVICE_APPRAISAL,
                    'request_payload' => [
                        'order_type' => self::APPRAISAL_ORDER_TYPE,
                        ...$payload,
                        // A snapshot of what was selected, for every display of
                        // the order; the live link is leasyback_order_vehicles.
                        'vehicles' => array_map(fn (Vehicle $vehicle) => [
                            'vehicle_id' => $vehicle->vehicle_id,
                            'license_plate' => $vehicle->license_plate,
                            'make' => $vehicle->make,
                            'model' => $vehicle->model,
                            'vin' => $vehicle->vin,
                            'leasing_end_date' => VehicleService::asDateString($vehicle->leasing_end_date),
                        ], $vehicles),
                    ],
                    'created_by_user_id' => $user->id,
                ]);

                foreach (array_values($vehicles) as $position => $vehicle) {
                    OrderVehicle::create([
                        'order_id' => $order->id,
                        'vehicle_id' => $vehicle->vehicle_id,
                        'position' => $position,
                    ]);
                }

                $this->auditOrder($order, 'REQUEST_APPRAISAL', null, [
                    'order_status' => 'order_requested',
                    'service_type' => self::SERVICE_APPRAISAL,
                    'vehicle_count' => count($vehicles),
                ], $user->id);

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two bookings raced for the same vehicle; the loser gets the
            // ordinary refusal rather than a database error.
            if (! str_contains($e->getMessage(), 'active_vehicle_id')) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'vehicle_ids' => 'Eines der Fahrzeuge wurde gerade für einen anderen Auftrag gebucht.',
            ]);
        }

        // Customer confirmation and the operations team's notification.
        $this->orderMailer->orderCreated($order, $first);

        return $order;
    }

    /**
     * The stored shape of an appraisal order's details.
     *
     * - `time_from`/`time_to` are also written as `time_slot` ("08:00-12:00"),
     *   like the Überführung.
     * - Return transport only exists together with pickup (brief).
     * - Empty optional blocks are null; the form's save switches are dropped.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function normaliseAppraisalDetails(array $details): array
    {
        $details = collect($details)->except(self::ACCIDENT_FORM_FLAGS)->all();

        if (! empty($details['time_from']) && ! empty($details['time_to'])) {
            $details['time_slot'] = $details['time_from'].'-'.$details['time_to'];
        }

        $details['pickup_requested'] = (bool) ($details['pickup_requested'] ?? false);
        $details['return_transport'] = $details['pickup_requested'] && (bool) ($details['return_transport'] ?? false);

        foreach (['location_contact', 'cost_centre'] as $block) {
            $values = collect((array) ($details[$block] ?? []))->filter(fn ($value) => filled($value));
            $details[$block] = $values->isEmpty() ? null : $details[$block];
        }

        return $details;
    }

    /**
     * The stored shape of an Unfallschaden report.
     *
     * - Without a different return location, no return address or contact is
     *   kept — the vehicle comes back to where it is.
     * - Empty optional blocks (contacts, cost centre) are stored as null.
     * - The form's save switches are not part of the order.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function normaliseAccidentDamageDetails(array $details): array
    {
        $details = collect($details)->except(self::ACCIDENT_FORM_FLAGS)->all();
        $details['return_differs'] = (bool) ($details['return_differs'] ?? false);

        if (! $details['return_differs']) {
            $details['return_address'] = null;
            $details['return_contact'] = null;
        }

        foreach (['location_contact', 'return_contact', 'cost_centre'] as $block) {
            $values = collect((array) ($details[$block] ?? []))->filter(fn ($value) => filled($value));
            $details[$block] = $values->isEmpty() ? null : $details[$block];
        }

        return $details;
    }

    /**
     * Saves the billing address and cost centre to the company account — but
     * only when the customer explicitly asked for it. Editing an address for
     * one order never changes a saved one (brief: "unless the customer
     * explicitly saves it").
     *
     * @param  array<string, mixed>  $details
     */
    private function rememberCompanyBillingAndCostCentre(string $b2bId, User $user, array $details): void
    {
        if ($b2bId === '') {
            return;
        }

        if (! empty($details['save_billing_address']) && ! empty($details['billing_address'])) {
            $billing = (array) $details['billing_address'];
            $makeDefault = ! empty($details['billing_address_default'])
                || ! CompanyBillingAddress::where('b2b_id', $b2bId)->exists();

            DB::transaction(function () use ($b2bId, $user, $billing, $makeDefault) {
                if ($makeDefault) {
                    CompanyBillingAddress::where('b2b_id', $b2bId)->update(['is_default' => false]);
                }

                CompanyBillingAddress::create([
                    'b2b_id' => $b2bId,
                    'name' => (string) ($billing['name'] ?? ''),
                    'details' => collect($billing)->except('name')->all(),
                    'is_default' => $makeDefault,
                    'created_by_user_id' => $user->id,
                ]);
            });
        }

        $costCentre = (array) ($details['cost_centre'] ?? []);

        if (! empty($details['save_cost_centre']) && filled($costCentre['name'] ?? null)) {
            CompanyCostCentre::firstOrCreate(
                [
                    'b2b_id' => $b2bId,
                    'name' => trim((string) $costCentre['name']),
                    'number' => filled($costCentre['number'] ?? null) ? trim((string) $costCentre['number']) : null,
                ],
                ['created_by_user_id' => $user->id],
            );
        }
    }

    /**
     * Book one Überführung per vehicle, all sharing the same booking details
     * (addresses, time window, contacts, billing, cost centre).
     *
     * Every vehicle is checked before anything is written, so the ordinary
     * refusal — one of them already has an order — creates nothing at all
     * and names the vehicle, instead of leaving half a booking behind. The
     * unique index in insertOrder() still backs this up against a race.
     *
     * @param  list<Vehicle>  $vehicles
     * @param  array<string, mixed>  $details  validated booking details, without vehicle ids
     * @return list<LeasybackOrder>
     */
    public function createB2bRelocationOrders(array $vehicles, User $user, array $details): array
    {
        if ($vehicles === []) {
            throw ValidationException::withMessages(['vehicle_ids' => 'Bitte wählen Sie mindestens ein Fahrzeug.']);
        }

        foreach ($vehicles as $vehicle) {
            if ($vehicle->vehicle_belongs !== 'B2B') {
                throw ValidationException::withMessages([
                    'vehicle_ids' => "Überführungen sind nur für Firmenfahrzeuge verfügbar ({$vehicle->license_plate}).",
                ]);
            }

            if ($this->vehicleService->blocksNewOrder($vehicle->vehicle_id)) {
                throw ValidationException::withMessages([
                    'vehicle_ids' => "Für das Fahrzeug {$vehicle->license_plate} läuft bereits ein Auftrag.",
                ]);
            }
        }

        $details = self::normaliseRelocationDetails($details);

        return array_map(
            fn (Vehicle $vehicle) => $this->createB2bRelocationOrder($vehicle, $user, $details),
            $vehicles,
        );
    }

    /**
     * One Überführung order. There is no inspection, no station and no
     * external booking: the whole booking lives in `request_payload`, marked
     * with its own order type so nothing downstream mistakes it for a
     * Leasingrückgabe collection.
     *
     * @param  array<string, mixed>  $validated
     */
    public function createB2bRelocationOrder(Vehicle $vehicle, User $user, array $validated): LeasybackOrder
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            $this->fail(422, 'relocation orders are only available for B2B vehicles');
        }

        $this->assertVehicleIsFree($vehicle);

        $details = self::normaliseRelocationDetails($validated);
        $auftragsnummer = $this->reserveOrderNumber($vehicle, $user);

        $order = DB::transaction(function () use ($vehicle, $user, $details, $auftragsnummer) {
            $order = $this->insertOrder([
                'vehicle_id' => $vehicle->vehicle_id,
                'auftragsnummer' => $auftragsnummer,
                'leasyback_partner' => self::B2B_PARTNER,
                'order_status' => 'order_requested',
                'service_type' => self::SERVICE_RELOCATION,
                'request_payload' => [
                    'order_type' => self::B2B_RELOCATION_ORDER_TYPE,
                    ...$details,
                ],
                'created_by_user_id' => $user->id,
            ]);

            $this->auditOrder($order, 'REQUEST_RELOCATION', null, [
                'order_status' => 'order_requested',
                'service_type' => self::SERVICE_RELOCATION,
            ], $user->id);

            return $order;
        });

        $this->orderMailer->orderCreated($order, $vehicle);

        return $order;
    }

    /**
     * The stored shape of an Überführung's details, whichever client sent it.
     *
     * - A `von`/`bis` window is also written as `time_slot` ("08:00-12:00"),
     *   which is what every display reads — so the portal, the API and older
     *   rows that only ever had a fixed slot all render the same way.
     * - An empty billing address or cost centre is stored as null rather than
     *   as a block of empty strings.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function normaliseRelocationDetails(array $details): array
    {
        if (! empty($details['time_from']) && ! empty($details['time_to'])) {
            $details['time_slot'] = $details['time_from'].'-'.$details['time_to'];
        }

        if (array_key_exists('billing_address', $details)) {
            $billing = collect((array) ($details['billing_address'] ?? []))->except('country')->filter(fn ($value) => filled($value));
            $details['billing_address'] = $billing->isEmpty() ? null : $details['billing_address'];
        }

        if (array_key_exists('cost_centre', $details)) {
            $costCentre = collect((array) ($details['cost_centre'] ?? []))->filter(fn ($value) => filled($value));
            $details['cost_centre'] = $costCentre->isEmpty() ? null : $details['cost_centre'];
        }

        return $details;
    }

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
                'service_type' => self::SERVICE_LEASING_RETURN,
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
     *
     * RESTORED: during the relocation work the body of this method had been
     * overwritten with relocation code, so every B2C booking with a
     * non-TÜV-SÜD provider was being saved as an Überführung. Compare against
     * your git history (see the delivery notes) and keep your original if it
     * differs — in particular the `leasyback_partner` value.
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

        $order = DB::transaction(function () use ($vehicle, $auftragsnummer, $requestPayload, $user, $validated, $station) {
            $order = $this->insertOrder([
                'vehicle_id' => $vehicle->vehicle_id,
                'auftragsnummer' => $auftragsnummer,
                'leasyback_partner' => $validated['provider'] ?? $station?->provider ?? 'other',
                'order_status' => 'order_placed',
                'service_type' => self::SERVICE_LEASING_RETURN,
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
     *
     * An Überführung and an Unfallschaden are approved the same way: both are
     * LeasyBack-partner B2B orders with no external booking either.
     */
    private function approveB2bCollectionOrder(LeasybackOrder $order, User $user, ?string $callerIp): LeasybackOrder
    {
        // `sent_at` is when an order became order_placed — on B2C when TÜV SÜD
        // accepted it or a direct booking was saved; on B2B it is this
        // approval, as there is no provider to send it to. Written with the
        // transition, never at request time: an order_requested order has not
        // been placed. (Partner API: `placed_at`.)
        $order = $this->transitionOrderStatus->__invoke(
            $order,
            'order_placed',
            'admin',
            $user->name ?? $user->email,
            $user->id,
            $callerIp,
            additionalAttributes: ['sent_at' => now()],
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
        $b2bOrderTypes = [self::B2B_ORDER_TYPE, self::B2B_RELOCATION_ORDER_TYPE, self::ACCIDENT_DAMAGE_ORDER_TYPE, self::APPRAISAL_ORDER_TYPE];

        if (in_array(data_get($order->request_payload, 'order_type'), $b2bOrderTypes, true)) {
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
