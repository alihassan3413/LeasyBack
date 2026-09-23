<?php

namespace App\Modules\PartnerApi\Http\Resources;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Support\OrderStatusLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as a partner sees it.
 *
 * `request_payload` and `response_body` are deliberately absent. They hold the
 * TÜV SÜD request and its raw reply — our credentials' blast radius, a third
 * party's response format, and internal contact details — none of which is the
 * partner's, and all of which would become an accidental contract the moment
 * one integration parsed it.
 *
 * @mixin LeasybackOrder
 */
class PartnerOrderResource extends JsonResource
{
    public ?string $externalId = null;

    public ?string $vehicleExternalId = null;

    public function withExternalIds(?string $externalId, ?string $vehicleExternalId): self
    {
        $this->externalId = $externalId;
        $this->vehicleExternalId = $vehicleExternalId;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = (string) $this->order_status;

        return [
            'id' => $this->id,
            'external_id' => $this->externalId,
            // Our human-facing order number. Partners quote it to support, and
            // it is the key every document and timeline entry hangs off, so it
            // is exposed alongside the uuid rather than instead of it.
            'reference' => $this->auftragsnummer,
            'vehicle' => [
                'id' => $this->vehicle_id,
                'external_id' => $this->vehicleExternalId,
            ],
            'status' => $status,
            'status_label' => OrderStatusLabel::for($status),
            // Saves every partner from hardcoding our terminal statuses to
            // answer "is this still moving" — and keeps that answer correct
            // for them when the status graph gains a stage.
            'is_open' => ! in_array($status, OrderStatus::closedValues(), true),
            'created_at' => $this->created_at?->toIso8601String(),
            'placed_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
