<?php

namespace App\Modules\PartnerApi\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Modules\PartnerApi\Http\Controllers\Concerns\TranslatesServiceFailures;
use App\Modules\PartnerApi\Http\Requests\StorePartnerOrderRequest;
use App\Modules\PartnerApi\Http\Resources\PartnerOrderResource;
use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\PartnerApi\Services\PartnerExternalReferenceRegistry;
use App\Modules\PartnerApi\Services\PartnerResourceLocator;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use App\Modules\PartnerApi\Support\PartnerPagination;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\OrderService;
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
 * B2B leasing-return orders.
 *
 * The only order this API creates is the collection order — the vehicle is
 * picked up at the company's site, there is no inspection station, no TÜV SÜD
 * appointment and no external booking call. `OrderService::createB2bCollectionOrder()`
 * is the same method the portal's order button calls, so the B2C inspection
 * flow is not merely unexposed here, it is unreachable: that method refuses a
 * non-B2B vehicle outright.
 *
 * There is deliberately **no** status-update endpoint. An order advances
 * through the status graph by what actually happens to the vehicle — Admin
 * approval, collection, appraisal, the workshop — and every legal edge is
 * TransitionOrderStatus's to decide. A partner endpoint that set a status
 * would be a second, weaker copy of that graph, and the first thing it would
 * allow is skipping a stage. Partners read status; they do not write it.
 */
#[Group(name: 'Partner API')]
class OrderController extends Controller
{
    use TranslatesServiceFailures;

    public function __construct(
        private readonly PartnerResourceLocator $locator,
        private readonly PartnerContext $context,
        private readonly OrderService $orderService,
    ) {}

    /**
     * List orders across the company fleet.
     */
    #[Endpoint(
        title: 'List orders',
        description: 'Every order on every vehicle in the token’s company, newest first.'
    )]
    #[QueryParam('status', 'string', 'Machine status, or `open` for orders still in progress.', required: false, example: 'open')]
    #[QueryParam('external_order_id', 'string', 'Your own id for an order. Returns at most one result.', required: false, example: 'PO-2026-0042')]
    #[QueryParam('per_page', 'integer', 'Results per page, 1–100. Defaults to 25.', required: false, example: 25)]
    #[QueryParam('page', 'integer', 'Page number, from 1.', required: false, example: 1)]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'orders' => [[
                    'id' => '4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2',
                    'external_id' => 'PO-2026-0042',
                    'reference' => 'BXY123260806',
                    'vehicle' => [
                        'id' => '9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f',
                        'external_id' => 'FLEET-00042',
                    ],
                    'status' => 'order_requested',
                    'status_label' => 'Anfrage gesendet',
                    'is_open' => true,
                    'created_at' => '2026-08-06T09:14:02+00:00',
                    'placed_at' => null,
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
        return $this->paginated($request, $this->locator->orderQuery());
    }

    /**
     * List one vehicle's orders.
     */
    #[Endpoint(
        title: 'List a vehicle’s orders',
        description: 'The order history of a single vehicle, newest first. A vehicle outside this '
            .'token’s company answers 404.'
    )]
    #[QueryParam('status', 'string', 'Machine status, or `open` for orders still in progress.', required: false, example: 'open')]
    #[QueryParam('per_page', 'integer', 'Results per page, 1–100. Defaults to 25.', required: false, example: 25)]
    #[QueryParam('page', 'integer', 'Page number, from 1.', required: false, example: 1)]
    public function forVehicle(Request $request, string $vehicle): JsonResponse
    {
        // Resolved rather than filtered on, so an unknown or another company's
        // vehicle answers 404 instead of an empty list — "no orders" and "not
        // your vehicle" are different facts and a partner needs to tell them
        // apart when reconciling.
        $found = $this->locator->findVehicleOrFail($vehicle);

        return $this->paginated(
            $request,
            $this->locator->orderQuery()->where('vehicle_id', $found->vehicle_id),
        );
    }

    /**
     * Retrieve one order.
     */
    #[Endpoint(title: 'Get an order')]
    #[Response(
        status: 404,
        content: [
            'error' => [
                'type' => 'not_found',
                'code' => 'order_not_found',
                'message' => 'No order with that id exists in the company this token belongs to.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function show(Request $request, string $order): JsonResponse
    {
        $found = $this->locator->findOrderOrFail($order);

        return PartnerApiResponse::success([
            'order' => PartnerOrderResource::make($found)
                ->withExternalIds(
                    $this->locator->externalIdFor(PartnerExternalReferenceRegistry::TYPE_ORDER, $found->id),
                    $this->locator->externalIdFor(PartnerExternalReferenceRegistry::TYPE_VEHICLE, $found->vehicle_id),
                )
                ->resolve($request),
        ]);
    }

    /**
     * Request collection of a vehicle.
     */
    #[Endpoint(
        title: 'Create a return order',
        description: 'Requests collection of a vehicle at the end of its lease. The order is '
            .'created as `order_requested` and waits for Leasyback to approve it — exactly as when '
            .'a company member submits the same request in the portal. Requires an '
            .'`Idempotency-Key` header. '
            .'A vehicle with an order that has not closed yet answers 409: one vehicle can only be '
            .'in one return process at a time.'
    )]
    #[Response(
        status: 201,
        content: [
            'data' => [
                'order' => [
                    'id' => '4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2',
                    'external_id' => 'PO-2026-0042',
                    'reference' => 'BXY123260806',
                    'status' => 'order_requested',
                    'status_label' => 'Anfrage gesendet',
                    'is_open' => true,
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    #[Response(
        status: 409,
        content: [
            'error' => [
                'type' => 'conflict',
                'code' => 'order_already_open',
                'message' => 'vehicle previous order not completed yet',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function store(StorePartnerOrderRequest $request, string $vehicle): JsonResponse
    {
        $found = $this->locator->findVehicleOrFail($vehicle);
        $externalId = $request->externalOrderId();

        $this->locator->assertExternalIdAvailable(
            PartnerExternalReferenceRegistry::TYPE_ORDER,
            $externalId,
        );

        $order = DB::transaction(function () use ($request, $found, $externalId): LeasybackOrder {
            $order = $this->translatingServiceFailures(
                [409 => 'order_already_open', 422 => 'vehicle_not_eligible'],
                fn () => $this->createOrder($found, $request),
            );

            $this->locator->registerExternalId(
                PartnerExternalReferenceRegistry::TYPE_ORDER,
                $externalId,
                $order->id,
            );

            return $order;
        });

        return PartnerApiResponse::success([
            'order' => PartnerOrderResource::make($order)
                ->withExternalIds($externalId, $this->locator->externalIdFor(
                    PartnerExternalReferenceRegistry::TYPE_VEHICLE,
                    $order->vehicle_id,
                ))
                ->resolve($request),
        ], 201);
    }

    /**
     * OrderService::createB2bCollectionOrder(), unchanged.
     *
     * This used to wrap the call in a `order_reference_conflict` translation:
     * `auftragsnummer` was registration-number + date and unique
     * application-wide, so a second order for the same vehicle on the same
     * calendar day hit the index and a legitimate request got a conflict it
     * could do nothing about. OrderNumberGenerator now allocates `-02`, `-03`,
     * … for exactly that case, so the collision no longer exists and the error
     * code has been withdrawn (§12.16). A unique violation on that column would
     * now be a real bug, and is left to surface as one.
     */
    private function createOrder(mixed $vehicle, StorePartnerOrderRequest $request): LeasybackOrder
    {
        return $this->orderService->createB2bCollectionOrder(
            $vehicle,
            $this->context->user(),
            $request->orderAttributes(),
        );
    }

    /**
     * One page of orders, with both id mappings resolved in two queries rather
     * than two per row.
     *
     * @param  Builder<LeasybackOrder>  $query
     */
    private function paginated(Request $request, Builder $query): JsonResponse
    {
        $this->applyFilters($query, $request);

        $paginator = $query->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(
                perPage: PartnerPagination::perPage($request),
                page: PartnerPagination::page($request),
            );

        /** @var Collection<int, LeasybackOrder> $orders */
        $orders = collect($paginator->items());

        $orderExternalIds = $this->locator->externalIdsFor(
            PartnerExternalReferenceRegistry::TYPE_ORDER,
            $orders->pluck('id')->all(),
        );

        $vehicleExternalIds = $this->locator->externalIdsFor(
            PartnerExternalReferenceRegistry::TYPE_VEHICLE,
            $orders->pluck('vehicle_id')->unique()->values()->all(),
        );

        return PartnerApiResponse::success([
            'orders' => $orders
                ->map(fn (LeasybackOrder $order) => PartnerOrderResource::make($order)
                    ->withExternalIds(
                        $orderExternalIds[$order->id] ?? null,
                        $vehicleExternalIds[$order->vehicle_id] ?? null,
                    )
                    ->resolve($request))
                ->all(),
            'pagination' => PartnerPagination::meta($paginator),
        ]);
    }

    /**
     * @param  Builder<LeasybackOrder>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $externalId = trim((string) $request->query('external_order_id', ''));

        if ($externalId !== '') {
            $internalId = $this->locator->internalIdFor(
                PartnerExternalReferenceRegistry::TYPE_ORDER,
                $externalId,
            );

            $internalId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('id', $internalId);
        }

        $status = trim((string) $request->query('status', ''));

        if ($status === 'open') {
            $query->whereNotIn('order_status', OrderStatus::closedValues());
        } elseif ($status !== '') {
            $query->where('order_status', $status);
        }
    }
}
