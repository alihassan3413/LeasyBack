<?php

namespace Tests\Feature\PartnerApi\Concerns;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Support\Carbon;

/**
 * Order state the phase-3 read endpoints report on.
 *
 * Written directly rather than driven through TransitionOrderStatus on
 * purpose: these tests assert what the *reader* makes of a given history, and
 * the writer has its own tests. A transition helper here would couple every
 * timeline assertion to the status graph's current shape.
 */
trait BuildsPartnerOrderHistory
{
    protected function makeB2bOrder(string $b2bId, OrderStatus $status = OrderStatus::OrderRequested): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->forB2b($b2bId)->create();

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status->value,
            'leasyback_partner' => 'leasyback',
            'created_at' => now()->subDays(10),
        ]);
    }

    /**
     * One transition, with the audit columns a partner must never see back
     * populated so their absence in a response means something.
     */
    protected function recordTransition(
        LeasybackOrder $order,
        ?string $from,
        string $to,
        ?Carbon $at = null,
    ): OrderStatusUpdate {
        return OrderStatusUpdate::create([
            'auftragsnummer' => $order->auftragsnummer,
            'old_status' => $from,
            'new_status' => $to,
            'updated_by' => 'Admin Mustermann',
            'auth_source' => 'admin',
            'caller_ip' => '203.0.113.7',
            'created_at' => $at ?? now(),
        ]);
    }

    protected function makeReportDocument(
        LeasybackOrder $order,
        string $type,
        bool $published = true,
        string $title = 'Bericht',
    ): VehicleReportDocument {
        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => $type,
            'document_title' => $title,
            'published' => $published,
            'path' => 'vehicle-reports/'.$type.'-'.fake()->uuid().'.pdf',
        ]);
    }

    /**
     * A presented customer offer: the `leasyback_offers` row plus the
     * `b2b_offer_presentations` snapshot that makes it a B2B one.
     *
     * @param  array<string, mixed>  $presentation
     * @return array{0: LeasybackOffer, 1: B2bOfferPresentation}
     */
    protected function makePresentedOffer(
        LeasybackOrder $order,
        string $status = 'published',
        array $presentation = [],
    ): array {
        $offer = LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => $status,
            'published_at' => $status === 'draft' ? null : now()->subDays(2),
            'selected_at' => $status === 'selected' ? now()->subDay() : null,
        ]);

        $row = B2bOfferPresentation::create([
            'offer_id' => $offer->offer_id,
            'order_id' => $order->id,
            'workshop_quotation_id' => null,
            'lines' => [[
                'appraisal_position_id' => (string) fake()->uuid(),
                'component' => 'Stoßfänger vorne',
                'damage_description' => 'Kratzer, 20 cm',
                'appraisal_amount_net' => '400.00',
                'repair_amount_net' => '260.00',
                'saving_net' => '140.00',
                'repair_method' => 'Smart Repair',
                'not_repairable' => false,
                'damage_image_document_ids' => ['secret-image-id'],
            ]],
            'appraisal_total_net' => '400.00',
            'repair_total_net' => '260.00',
            'saving_net' => '140.00',
            'valid_until' => now()->addWeek()->toDateString(),
            'presented_at' => $status === 'draft' ? null : now()->subDays(2),
            ...$presentation,
        ]);

        return [$offer, $row];
    }
}
