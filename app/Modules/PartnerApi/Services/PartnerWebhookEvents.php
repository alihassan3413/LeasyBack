<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Data\PartnerDocument;
use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Http\Resources\PartnerDocumentResource;
use App\Modules\PartnerApi\Http\Resources\PartnerOfferResource;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;

/**
 * The vocabulary the rest of the application speaks to webhooks in.
 *
 * Every business write path depends on this class and nothing else in the
 * module: `$this->webhooks->orderStatusChanged($order, $from)`. That keeps the
 * emit sites one line long and keeps payload shape decisions here, where they
 * can be reviewed together, rather than scattered across seven services.
 *
 * **Payloads are notifications, not resources.** They carry ids, references and
 * the same machine codes the phase-3 read endpoints use, so a partner knows
 * *what* changed and can go read the current truth over an authenticated
 * endpoint. The two exceptions earn it: an offer carries its frozen snapshot,
 * because the snapshot is the thing that was decided and re-reading it later
 * gives a different answer for a superseded offer; and a document carries its
 * metadata, because "a file exists" without knowing which file is not
 * actionable.
 *
 * What is deliberately absent everywhere:
 * - internal notes (`b2b_order_notes` where `visibility` is internal, and
 *   `leasyback_order_logistics.internal_note`) — the whole point of §16;
 * - storage paths and file bytes — PartnerDocumentResource never reads
 *   `PartnerDocument::$path`, and a webhook is not a delivery mechanism for a
 *   PDF;
 * - the workshop comparison — PartnerOfferResource omits
 *   `workshop_quotation_id` and everything reachable through it (§9);
 * - gross amounts, which the B2B process does not have;
 * - billing figures. `order.billing_completed` says billing is done, not what
 *   it came to: the Partner API exposes no billing endpoint, and a webhook must
 *   not be a side door into data no endpoint serves.
 */
class PartnerWebhookEvents
{
    public function __construct(private readonly PartnerWebhookEmitter $emitter) {}

    public function vehicleCreated(Vehicle $vehicle): void
    {
        $this->emitVehicle(PartnerWebhookEvent::VehicleCreated, $vehicle);
    }

    public function vehicleUpdated(Vehicle $vehicle): void
    {
        $this->emitVehicle(PartnerWebhookEvent::VehicleUpdated, $vehicle);
    }

    public function orderCreated(LeasybackOrder $order, ?Vehicle $vehicle = null): void
    {
        $vehicle = $this->vehicleFor($order, $vehicle);

        $this->emitter->emit(
            PartnerWebhookEvent::OrderCreated,
            $this->companyOf($vehicle),
            ['order' => $this->orderData($order, $vehicle)],
            $order->id,
            $order->vehicle_id,
        );
    }

    /**
     * Every real transition, plus — for the four that mean something specific
     * enough to warrant a dedicated handler — a second, narrower event.
     *
     * Both are sent because they answer different questions. A partner building
     * a state machine wants every edge; a partner who only cares that the
     * appraisal is in wants one subscription that fires once. Sending only the
     * generic one would force the second partner to filter, and sending only
     * the specific ones would leave the transitions with no specific meaning
     * unannounced.
     */
    public function orderStatusChanged(LeasybackOrder $order, string $fromStatus, ?Vehicle $vehicle = null): void
    {
        $vehicle = $this->vehicleFor($order, $vehicle);
        $companyId = $this->companyOf($vehicle);

        $data = [
            'order' => $this->orderData($order, $vehicle),
            'previous_status' => $fromStatus,
        ];

        $this->emitter->emit(
            PartnerWebhookEvent::OrderStatusChanged,
            $companyId,
            $data,
            $order->id,
            $order->vehicle_id,
        );

        $specific = PartnerWebhookEvent::forStatus()[$order->order_status] ?? null;

        if ($specific !== null) {
            $this->emitter->emit($specific, $companyId, $data, $order->id, $order->vehicle_id);
        }
    }

    public function collectionConfirmed(LeasybackOrder $order, ?string $confirmedDate, ?Vehicle $vehicle = null): void
    {
        $this->emitCollection(PartnerWebhookEvent::OrderCollectionConfirmed, $order, $confirmedDate, null, $vehicle);
    }

    public function collectionRescheduled(
        LeasybackOrder $order,
        ?string $confirmedDate,
        ?string $previousDate,
        ?Vehicle $vehicle = null,
    ): void {
        $this->emitCollection(
            PartnerWebhookEvent::OrderCollectionRescheduled,
            $order,
            $confirmedDate,
            $previousDate,
            $vehicle,
        );
    }

    /**
     * Billing state only. See the class docblock: no amounts, because no
     * endpoint serves them.
     */
    public function billingCompleted(LeasybackOrder $order, ?Vehicle $vehicle = null): void
    {
        $vehicle = $this->vehicleFor($order, $vehicle);

        $this->emitter->emit(
            PartnerWebhookEvent::OrderBillingCompleted,
            $this->companyOf($vehicle),
            [
                'order' => $this->orderData($order, $vehicle),
                'billing' => ['status' => 'processed', 'processed_at' => now()->utc()->toIso8601ZuluString()],
            ],
            $order->id,
            $order->vehicle_id,
        );
    }

    /**
     * A document a customer can now see.
     *
     * Emitted from publication, not upload: an unpublished report is Admin's
     * work in progress and announcing it would let a partner poll for a
     * download that answers 404.
     */
    public function documentAvailable(PartnerDocument $document, ?LeasybackOrder $order, ?Vehicle $vehicle): void
    {
        $this->emitDocument(PartnerWebhookEvent::DocumentAvailable, $document, $order, $vehicle, null);
    }

    /**
     * A document that was available and is not the current one any more —
     * unpublished, or replaced by a newer file for the same order and type.
     */
    public function documentReplaced(
        PartnerDocument $document,
        ?LeasybackOrder $order,
        ?Vehicle $vehicle,
        string $reason,
    ): void {
        $this->emitDocument(PartnerWebhookEvent::DocumentReplaced, $document, $order, $vehicle, $reason);
    }

    public function offerPublished(
        LeasybackOffer $offer,
        B2bOfferPresentation $presentation,
        LeasybackOrder $order,
        ?Vehicle $vehicle = null,
    ): void {
        $this->emitOffer(PartnerWebhookEvent::OfferPublished, $offer, $presentation, $order, $vehicle);
    }

    public function offerUpdated(
        LeasybackOffer $offer,
        B2bOfferPresentation $presentation,
        LeasybackOrder $order,
        ?Vehicle $vehicle = null,
    ): void {
        $this->emitOffer(PartnerWebhookEvent::OfferUpdated, $offer, $presentation, $order, $vehicle);
    }

    public function offerAccepted(
        LeasybackOffer $offer,
        B2bOfferPresentation $presentation,
        LeasybackOrder $order,
        ?Vehicle $vehicle = null,
    ): void {
        $this->emitOffer(PartnerWebhookEvent::OfferAccepted, $offer, $presentation, $order, $vehicle);
    }

    public function offerRejected(
        LeasybackOffer $offer,
        B2bOfferPresentation $presentation,
        LeasybackOrder $order,
        ?Vehicle $vehicle = null,
    ): void {
        $this->emitOffer(PartnerWebhookEvent::OfferRejected, $offer, $presentation, $order, $vehicle);
    }

    public function offerExpired(
        LeasybackOffer $offer,
        B2bOfferPresentation $presentation,
        LeasybackOrder $order,
        ?Vehicle $vehicle = null,
    ): void {
        $this->emitOffer(PartnerWebhookEvent::OfferExpired, $offer, $presentation, $order, $vehicle);
    }

    private function emitVehicle(PartnerWebhookEvent $type, Vehicle $vehicle): void
    {
        $this->emitter->emit(
            $type,
            $this->companyOf($vehicle),
            ['vehicle' => $this->vehicleData($vehicle)],
            null,
            $vehicle->vehicle_id,
        );
    }

    private function emitCollection(
        PartnerWebhookEvent $type,
        LeasybackOrder $order,
        ?string $confirmedDate,
        ?string $previousDate,
        ?Vehicle $vehicle,
    ): void {
        $vehicle = $this->vehicleFor($order, $vehicle);

        $collection = ['confirmed_date' => $confirmedDate];

        if ($type === PartnerWebhookEvent::OrderCollectionRescheduled) {
            $collection['previous_date'] = $previousDate;
        }

        $this->emitter->emit(
            $type,
            $this->companyOf($vehicle),
            ['order' => $this->orderData($order, $vehicle), 'collection' => $collection],
            $order->id,
            $order->vehicle_id,
        );
    }

    private function emitDocument(
        PartnerWebhookEvent $type,
        PartnerDocument $document,
        ?LeasybackOrder $order,
        ?Vehicle $vehicle,
        ?string $reason,
    ): void {
        $vehicle = $vehicle ?? Vehicle::where('vehicle_id', $document->vehicleId)->first();

        if ($vehicle === null) {
            return;
        }

        $data = ['document' => PartnerDocumentResource::toArray($document)];

        if ($order !== null) {
            $data['order'] = $this->orderData($order, $vehicle);
        }

        if ($reason !== null) {
            $data['reason'] = $reason;
        }

        $this->emitter->emit(
            $type,
            $this->companyOf($vehicle),
            $data,
            $order?->id,
            $document->vehicleId,
        );
    }

    private function emitOffer(
        PartnerWebhookEvent $type,
        LeasybackOffer $offer,
        B2bOfferPresentation $presentation,
        LeasybackOrder $order,
        ?Vehicle $vehicle,
    ): void {
        $vehicle = $this->vehicleFor($order, $vehicle);

        $this->emitter->emit(
            $type,
            $this->companyOf($vehicle),
            // The snapshot as it stands at emit, frozen into the event row. A
            // later edit to the presentation cannot change what a retry sends,
            // which is the property that makes an accepted offer's webhook a
            // record of what was accepted.
            ['offer' => PartnerOfferResource::toArray($offer, $presentation, $order)],
            $order->id,
            $order->vehicle_id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function orderData(LeasybackOrder $order, ?Vehicle $vehicle): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->auftragsnummer,
            'status' => $order->order_status,
            'vehicle' => $vehicle === null ? null : [
                'id' => $vehicle->vehicle_id,
                'license_plate' => $vehicle->license_plate,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicleData(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->vehicle_id,
            'license_plate' => $vehicle->license_plate,
            'vin' => $vehicle->vin,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'leasing_end_date' => $vehicle->leasing_end_date instanceof \DateTimeInterface
                ? $vehicle->leasing_end_date->format('Y-m-d')
                : $vehicle->leasing_end_date,
        ];
    }

    private function vehicleFor(LeasybackOrder $order, ?Vehicle $vehicle): ?Vehicle
    {
        return $vehicle ?? Vehicle::where('vehicle_id', $order->vehicle_id)->first();
    }

    /**
     * The company an event belongs to, and the only thing fan-out scopes on.
     *
     * Null for a B2C vehicle, which is how the entire B2C surface stays out of
     * this system without a single check at any emit site: a B2C order has no
     * company, so `emit()` returns immediately.
     */
    private function companyOf(?Vehicle $vehicle): ?string
    {
        if ($vehicle === null || $vehicle->vehicle_belongs !== 'B2B') {
            return null;
        }

        return $vehicle->b2b_id;
    }
}
