<?php

namespace App\Modules\PartnerApi\Http\Resources;

use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Support\OrderStatusLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vehicle as a partner sees it.
 *
 * An explicit allow-list, not `$this->resource->toArray()`. The vehicles table
 * carries columns that are ours rather than the partner's — `b2b_id`,
 * `b2c_user_id`, `created_by_user_id`, `assigned_profile_id` — and a resource
 * built by exclusion would start leaking them the day a column is added.
 *
 * `vehicle_belongs` is absent for the same reason it is not accepted on input:
 * every vehicle this API can reach is B2B, so the field would only ever carry
 * one value and inviting a partner to branch on it would be misleading.
 *
 * @mixin Vehicle
 */
class PartnerVehicleResource extends JsonResource
{
    public ?string $externalId = null;

    /**
     * The partner's own id for this vehicle.
     *
     * Passed in rather than looked up here: a list endpoint resolves a whole
     * page's mappings in one query, and a resource that fetched its own would
     * turn that back into one query per row.
     */
    public function withExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestOrder = $this->latestOrder();

        return [
            'id' => $this->vehicle_id,
            'external_id' => $this->externalId,
            'license_plate' => $this->license_plate,
            'vin' => $this->vin,
            'make' => $this->make,
            'model' => $this->model,
            'first_registration_date' => $this->first_registration_date?->toDateString(),
            'leasing_end_date' => $this->leasing_end_date?->toDateString(),
            'leasinggeber' => $this->leasinggeber,
            'mileage' => $this->mileage,
            'contract_number' => $this->contract_number,
            'cost_centre' => $this->cost_centre,
            'driver_name' => $this->driver_name,
            'driver_contact' => $this->driver_contact,
            // The vehicle's position in the process, as one flat pair: the
            // machine value a partner branches on, and a label they can put in
            // front of a human without maintaining their own translation of
            // our status vocabulary. Null until the first order exists.
            'status' => $latestOrder?->order_status,
            'status_label' => $latestOrder === null ? null : OrderStatusLabel::for($latestOrder->order_status),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The most recent order, when the caller eager-loaded it.
     *
     * Never lazy-loads: an unloaded relation means the caller did not ask for
     * status, and silently issuing a query per row here is the N+1 this class
     * is shaped to avoid.
     */
    private function latestOrder(): ?LeasybackOrder
    {
        if (! $this->resource->relationLoaded('orders')) {
            return null;
        }

        return $this->resource->getRelation('orders')->first();
    }
}
