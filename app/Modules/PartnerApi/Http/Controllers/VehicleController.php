<?php

namespace App\Modules\PartnerApi\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Modules\PartnerApi\Http\Controllers\Concerns\TranslatesServiceFailures;
use App\Modules\PartnerApi\Http\Requests\StorePartnerVehicleRequest;
use App\Modules\PartnerApi\Http\Requests\UpdatePartnerVehicleRequest;
use App\Modules\PartnerApi\Http\Resources\PartnerVehicleResource;
use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\PartnerApi\Services\PartnerExternalReferenceRegistry;
use App\Modules\PartnerApi\Services\PartnerResourceLocator;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use App\Modules\PartnerApi\Support\PartnerPagination;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;

/**
 * The token's company fleet.
 *
 * Every write goes through VehicleService, the same service the portal's
 * VehicleController and the Excel import use, so a vehicle created by a
 * partner is indistinguishable from one a member typed in: same audit log,
 * same `created_by_user_id` attribution, same collection-address de-duplication
 * — and therefore it appears on the company's B2B dashboard immediately,
 * without this controller knowing anything about that dashboard.
 *
 * Ownership is never derived here. VehicleService resolves it from the acting
 * user, which AuthenticatePartner has already pinned to the token's company,
 * and every read is narrowed by PartnerResourceLocator.
 */
#[Group(name: 'Partner API')]
class VehicleController extends Controller
{
    use TranslatesServiceFailures;

    public function __construct(
        private readonly PartnerResourceLocator $locator,
        private readonly PartnerContext $context,
        private readonly VehicleService $vehicleService,
    ) {}

    /**
     * List fleet vehicles.
     */
    #[Endpoint(
        title: 'List vehicles',
        description: 'Returns the fleet of the company this token belongs to, newest first. '
            .'Vehicles belonging to any other company are not merely hidden — they are outside '
            .'every query this endpoint can build.'
    )]
    #[QueryParam('search', 'string', 'Matches registration number, VIN, make or model.', required: false, example: 'B-XY 123')]
    #[QueryParam('status', 'string', 'Machine status of an order on the vehicle, or `open` for '
        .'a vehicle with an order still in progress, or `none` for one that has never been ordered.', required: false, example: 'open')]
    #[QueryParam('external_vehicle_id', 'string', 'Your own id for a vehicle. Returns at most one result.', required: false, example: 'FLEET-00042')]
    #[QueryParam('per_page', 'integer', 'Results per page, 1–100. Defaults to 25.', required: false, example: 25)]
    #[QueryParam('page', 'integer', 'Page number, from 1.', required: false, example: 1)]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'vehicles' => [[
                    'id' => '9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f',
                    'external_id' => 'FLEET-00042',
                    'license_plate' => 'B-XY 123',
                    'vin' => 'WVWZZZ1JZXW000001',
                    'make' => 'Volkswagen',
                    'model' => 'Passat',
                    'first_registration_date' => '2021-03-14',
                    'leasing_end_date' => '2026-03-13',
                    'leasinggeber' => 'Example Leasing GmbH',
                    'mileage' => 84000,
                    'contract_number' => 'LV-2021-0042',
                    'cost_centre' => 'KST-1000',
                    'driver_name' => 'A. Beispiel',
                    'driver_contact' => 'a.beispiel@example.com',
                    'status' => 'order_requested',
                    'status_label' => 'Anfrage gesendet',
                    'created_at' => '2026-08-06T09:12:44+00:00',
                    'updated_at' => '2026-08-06T09:12:44+00:00',
                ]],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 25,
                    'total' => 1,
                    'from' => 1,
                    'to' => 1,
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = $this->locator->vehicleQuery()
            ->with(['orders' => fn ($orders) => $orders->orderByDesc('created_at')->limit(1)]);

        $this->applyFilters($query, $request);

        $paginator = $query->orderByDesc('created_at')
            ->orderBy('vehicle_id')
            ->paginate(
                perPage: PartnerPagination::perPage($request),
                page: PartnerPagination::page($request),
            );

        /** @var Collection<int, Vehicle> $vehicles */
        $vehicles = collect($paginator->items());

        $externalIds = $this->locator->externalIdsFor(
            PartnerExternalReferenceRegistry::TYPE_VEHICLE,
            $vehicles->pluck('vehicle_id')->all(),
        );

        return PartnerApiResponse::success([
            'vehicles' => $vehicles
                ->map(fn (Vehicle $vehicle) => PartnerVehicleResource::make($vehicle)
                    ->withExternalId($externalIds[$vehicle->vehicle_id] ?? null)
                    ->resolve($request))
                ->all(),
            'pagination' => PartnerPagination::meta($paginator),
        ]);
    }

    /**
     * Retrieve one vehicle.
     */
    #[Endpoint(
        title: 'Get a vehicle',
        description: 'A vehicle outside this token’s company answers 404, never 403 — a partner '
            .'enumerating ids must not be able to tell "not yours" from "no such thing".'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'vehicle' => [
                    'id' => '9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f',
                    'external_id' => 'FLEET-00042',
                    'license_plate' => 'B-XY 123',
                    'vin' => 'WVWZZZ1JZXW000001',
                    'make' => 'Volkswagen',
                    'model' => 'Passat',
                    'first_registration_date' => '2021-03-14',
                    'leasing_end_date' => '2026-03-13',
                    'leasinggeber' => 'Example Leasing GmbH',
                    'mileage' => 84000,
                    'contract_number' => 'LV-2021-0042',
                    'cost_centre' => 'KST-1000',
                    'driver_name' => 'A. Beispiel',
                    'driver_contact' => 'a.beispiel@example.com',
                    'status' => 'order_requested',
                    'status_label' => 'Anfrage gesendet',
                    'created_at' => '2026-08-06T09:12:44+00:00',
                    'updated_at' => '2026-08-06T09:12:44+00:00',
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: '`status` reflects the vehicle’s most recent order, so a vehicle with no order '
            .'yet reports no order status.'
    )]
    #[Response(
        status: 404,
        content: [
            'error' => [
                'type' => 'not_found',
                'code' => 'vehicle_not_found',
                'message' => 'No vehicle with that id exists in the company this token belongs to.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function show(Request $request, string $vehicle): JsonResponse
    {
        $found = $this->locator->findVehicleOrFail($vehicle);
        $found->load(['orders' => fn ($orders) => $orders->orderByDesc('created_at')->limit(1)]);

        return PartnerApiResponse::success([
            'vehicle' => $this->resource($request, $found),
        ]);
    }

    /**
     * Register a vehicle.
     */
    #[Endpoint(
        title: 'Create a vehicle',
        description: 'Registers a vehicle in the token’s company fleet. Requires an '
            .'`Idempotency-Key` header: a retried create without one cannot be told from a second '
            .'vehicle, and the registration number is globally unique, so the retry would fail '
            .'validation instead of returning the vehicle it already made. '
            .'Supply `external_vehicle_id` to address the vehicle by your own id afterwards.'
    )]
    #[Response(
        status: 201,
        content: [
            'data' => [
                'vehicle' => [
                    'id' => '9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f',
                    'external_id' => 'FLEET-00042',
                    'license_plate' => 'B-XY 123',
                    'status' => null,
                    'status_label' => null,
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    #[Response(
        status: 422,
        content: [
            'error' => [
                'type' => 'validation_error',
                'code' => 'validation_failed',
                'message' => 'The request payload failed validation.',
                'details' => ['fields' => ['license_plate' => ['The license plate has already been taken.']]],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ],
        description: 'The registration number is unique across the whole application, so a '
            .'collision may involve a vehicle this token cannot see. The message never names it.'
    )]
    #[Response(
        status: 409,
        content: [
            'error' => [
                'type' => 'conflict',
                'code' => 'external_reference_conflict',
                'message' => "The external vehicle reference 'FLEET-00042' is already mapped to a different record.",
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function store(StorePartnerVehicleRequest $request): JsonResponse
    {
        $externalId = $request->externalVehicleId();

        $this->locator->assertExternalIdAvailable(
            PartnerExternalReferenceRegistry::TYPE_VEHICLE,
            $externalId,
        );

        // The mapping is written inside the same transaction as the vehicle,
        // so the race assertExternalIdAvailable() cannot close — two requests
        // claiming one external id at once — loses at the unique index and
        // takes its vehicle down with it, rather than leaving a vehicle the
        // partner has no id for.
        $vehicle = DB::transaction(function () use ($request, $externalId): Vehicle {
            $vehicle = $this->translatingServiceFailures(
                [404 => 'company_not_found', 400 => 'invalid_integration_account'],
                fn () => $this->vehicleService->createVehicle(
                    $this->context->user(),
                    $request->vehicleAttributes(),
                ),
            );

            $this->locator->registerExternalId(
                PartnerExternalReferenceRegistry::TYPE_VEHICLE,
                $externalId,
                $vehicle->vehicle_id,
            );

            return $vehicle;
        });

        return PartnerApiResponse::success([
            'vehicle' => $this->resource($request, $vehicle, $externalId),
        ], 201);
    }

    /**
     * Update a vehicle.
     */
    #[Endpoint(
        title: 'Update a vehicle',
        description: 'Changes a vehicle’s own details. The registration number and the owner are '
            .'not editable — neither is anywhere in the application — and sending either is a 422 '
            .'rather than a silently ignored field. Fields you omit are left alone.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'vehicle' => [
                    'id' => '9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f',
                    'external_id' => 'FLEET-00042',
                    'license_plate' => 'B-XY 123',
                    'mileage' => 91000,
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function update(UpdatePartnerVehicleRequest $request, string $vehicle): JsonResponse
    {
        $found = $this->locator->findVehicleOrFail($vehicle);
        $externalId = $request->externalVehicleId();

        $updated = DB::transaction(function () use ($request, $found, $externalId): Vehicle {
            $updated = $this->vehicleService->updateVehicle(
                $found,
                $request->vehicleAttributes(),
                $this->context->user(),
            );

            // Re-sending the id this vehicle already carries is a no-op; a
            // different one, or one already spoken for, is a 409. Both are the
            // registry's own uniqueness rules, unchanged.
            $this->locator->registerExternalId(
                PartnerExternalReferenceRegistry::TYPE_VEHICLE,
                $externalId,
                $updated->vehicle_id,
            );

            return $updated;
        });

        $updated->load(['orders' => fn ($orders) => $orders->orderByDesc('created_at')->limit(1)]);

        return PartnerApiResponse::success([
            'vehicle' => $this->resource($request, $updated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Request $request, Vehicle $vehicle, ?string $externalId = null): array
    {
        return PartnerVehicleResource::make($vehicle)
            ->withExternalId($externalId ?? $this->locator->externalIdFor(
                PartnerExternalReferenceRegistry::TYPE_VEHICLE,
                $vehicle->vehicle_id,
            ))
            ->resolve($request);
    }

    /**
     * @param  Builder<Vehicle>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $scoped) use ($term) {
                foreach (['license_plate', 'vin', 'make', 'model'] as $column) {
                    $scoped->orWhere($column, 'like', $term);
                }
            });
        }

        $externalId = trim((string) $request->query('external_vehicle_id', ''));

        if ($externalId !== '') {
            $internalId = $this->locator->internalIdFor(
                PartnerExternalReferenceRegistry::TYPE_VEHICLE,
                $externalId,
            );

            // An id this partner has never mapped must return an empty page,
            // not the whole fleet. Matched with an impossible predicate rather
            // than a sentinel value, so nothing is ever compared against a
            // uuid column that could not hold it.
            $internalId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('vehicle_id', $internalId);
        }

        $status = trim((string) $request->query('status', ''));

        if ($status === 'none') {
            $query->whereDoesntHave('orders');
        } elseif ($status === 'open') {
            // A B2B vehicle may hold at most one order that is not closed —
            // OrderService refuses a second — so "has an open order" and "its
            // current order is open" are the same set.
            $query->whereHas('orders', fn ($orders) => $orders->whereNotIn('order_status', OrderStatus::closedValues()));
        } elseif ($status !== '') {
            $query->whereHas('orders', fn ($orders) => $orders->where('order_status', $status));
        }
    }
}
