<?php

namespace App\Modules\PartnerApi\Services;

use App\Models\Vehicle;
use App\Modules\PartnerApi\Exceptions\PartnerApiException;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Every "which vehicle/order is this, and may this token see it" answer in the
 * Partner API.
 *
 * Route model binding is deliberately not used for either entity: it resolves
 * by primary key first and authorises second, so a mis-wired route would load
 * another company's row before anything checked. Here the scope *is* the
 * lookup — VehicleScopeService::scopeQuery() applies the same company and
 * member-level narrowing the portal's policies and listings use, so a vehicle
 * outside the token's company is simply not in the query.
 *
 * Anything the token cannot reach is a 404, never a 403. A 403 would confirm
 * that the id exists, which is exactly the disclosure cross-company isolation
 * is meant to prevent — a partner enumerating uuids must not be able to tell
 * "not yours" from "no such thing".
 */
class PartnerResourceLocator
{
    public function __construct(
        private readonly VehicleScopeService $scope,
        private readonly PartnerContext $context,
        private readonly PartnerExternalReferenceRegistry $references,
    ) {}

    /**
     * A vehicle query already narrowed to everything this token may see.
     *
     * @return Builder<Vehicle>
     */
    public function vehicleQuery(): Builder
    {
        $query = Vehicle::query();

        $this->scope->scopeQuery($query, $this->context->user());

        // Belt and braces on top of the member scope above: the Partner API's
        // company is the token's, never the acting user's resolved context.
        // If those two ever disagreed, this narrows to the token's answer.
        return $query->where('vehicle_belongs', 'B2B')
            ->where('b2b_id', $this->context->companyId());
    }

    public function findVehicleOrFail(string $vehicleId): Vehicle
    {
        $vehicle = $this->vehicleQuery()->where('vehicle_id', $vehicleId)->first();

        if ($vehicle === null) {
            throw PartnerApiException::notFound(
                'vehicle_not_found',
                'No vehicle with that id exists in the company this token belongs to.',
            );
        }

        return $vehicle;
    }

    /**
     * An order query narrowed to the vehicles this token may see.
     *
     * Scoped through the vehicle rather than by a company column on the order:
     * `leasyback_orders` has no owner of its own, and deriving one here would
     * be a second definition of who owns what.
     *
     * @return Builder<LeasybackOrder>
     */
    public function orderQuery(): Builder
    {
        return LeasybackOrder::query()
            ->whereIn('vehicle_id', $this->vehicleQuery()->select('vehicle_id'));
    }

    public function findOrderOrFail(string $orderId): LeasybackOrder
    {
        $order = $this->orderQuery()->where('id', $orderId)->first();

        if ($order === null) {
            throw PartnerApiException::notFound(
                'order_not_found',
                'No order with that id exists in the company this token belongs to.',
            );
        }

        return $order;
    }

    /**
     * Reject an external id already spoken for before doing the work.
     *
     * The database index is still the authority — this only turns the common
     * case (a partner reusing an id they already assigned) into a clear 409
     * before a vehicle or an order is written and rolled back.
     */
    public function assertExternalIdAvailable(string $type, ?string $externalId): void
    {
        if ($externalId === null) {
            return;
        }

        $existing = $this->references->internalId($this->context->client(), $type, $externalId);

        if ($existing !== null) {
            throw PartnerApiException::conflict(
                'external_reference_conflict',
                "The external {$type} reference '{$externalId}' is already mapped to a different record.",
                ['external_id' => $externalId, 'reference_type' => $type],
            );
        }
    }

    /**
     * Map a partner id onto a record we just created.
     *
     * The registry raises a plain RuntimeException on either uniqueness
     * direction, including the one the database catches when two requests race
     * past assertExternalIdAvailable(). Translated here so the partner sees the
     * documented 409 rather than the catch-all 500 the renderer would produce.
     */
    public function registerExternalId(string $type, ?string $externalId, string $internalId): void
    {
        if ($externalId === null) {
            return;
        }

        try {
            $this->references->register($this->context->client(), $type, $externalId, $internalId);
        } catch (RuntimeException $e) {
            throw PartnerApiException::conflict(
                'external_reference_conflict',
                $e->getMessage(),
                ['external_id' => $externalId, 'reference_type' => $type],
            );
        }
    }

    public function externalIdFor(string $type, string $internalId): ?string
    {
        return $this->references->externalId($this->context->client(), $type, $internalId);
    }

    /** Our id for a partner's id, or null if this partner has never mapped it. */
    public function internalIdFor(string $type, string $externalId): ?string
    {
        return $this->references->internalId($this->context->client(), $type, $externalId);
    }

    /**
     * @param  list<string>  $internalIds
     * @return array<string, string>
     */
    public function externalIdsFor(string $type, array $internalIds): array
    {
        return $this->references->externalIdsFor($this->context->client(), $type, $internalIds);
    }
}
