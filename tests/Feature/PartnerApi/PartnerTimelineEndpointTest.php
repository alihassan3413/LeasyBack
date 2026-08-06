<?php

namespace Tests\Feature\PartnerApi;

use App\Enums\OrderStatus;
use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Services\PartnerTimelineBuilder;
use App\Modules\UserProfile\Order\Models\B2bOrderNote;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerOrderHistory;
use Tests\TestCase;

class PartnerTimelineEndpointTest extends TestCase
{
    use BuildsPartnerClients;
    use BuildsPartnerOrderHistory;
    use RefreshDatabase;

    public function test_the_stage_sequence_matches_the_documented_fifteen_stage_flow(): void
    {
        // §3 of B2B_IMPLEMENTATION_HANDOFF.md, and B2B_ORDER_STAGE_SEQUENCE in
        // resources/js/lib/customerOrderFlow.ts. These codes are the partner
        // contract; this test is what makes changing one a deliberate act.
        $this->assertSame([
            'order_received',
            'collection_requested',
            'collection_scheduled',
            'vehicle_collected',
            'initial_appraisal',
            'quotations_preparing',
            'approval_required',
            'repair_approved',
            'workshop_commissioned',
            'vehicle_in_repair',
            'repair_completed',
            'final_appraisal',
            'vehicle_returned',
            'billing_completed',
            'order_completed',
        ], PartnerTimelineBuilder::STAGES);
    }

    public function test_status_reports_the_current_stage(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::VehicleCollected);
        $this->recordTransition($order, 'confirmed', 'vehicle_collected', now()->subDays(2));

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.status', $order->id))
            ->assertOk()
            ->assertJsonPath('data.order.reference', $order->auftragsnummer)
            ->assertJsonPath('data.status.code', 'vehicle_collected')
            ->assertJsonPath('data.status.label', 'Fahrzeug abgeholt')
            ->assertJsonPath('data.status.is_open', true)
            ->assertJsonPath('data.status.is_cancelled', false)
            ->assertJsonPath('data.status.stage', 'vehicle_collected')
            ->assertJsonPath('data.status.stage_sequence', 4)
            ->assertJsonPath('data.status.stage_count', 15);
    }

    public function test_the_timeline_marks_past_stages_completed_and_future_ones_upcoming(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::VehicleCollected);
        $this->recordTransition($order, 'order_requested', 'confirmed', now()->subDays(5));
        $this->recordTransition($order, 'confirmed', 'vehicle_collected', now()->subDays(2));

        $response = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.timeline', $order->id))
            ->assertOk()
            ->assertJsonPath('data.current_stage', 'vehicle_collected')
            ->assertJsonPath('data.is_cancelled', false)
            ->assertJsonCount(15, 'data.stages');

        $stages = collect($response->json('data.stages'))->keyBy('code');

        $this->assertSame('completed', $stages['order_received']['state']);
        $this->assertSame('completed', $stages['collection_scheduled']['state']);
        $this->assertSame('current', $stages['vehicle_collected']['state']);
        $this->assertSame('upcoming', $stages['initial_appraisal']['state']);
        $this->assertNull($stages['initial_appraisal']['occurred_at']);
        $this->assertSame(4, $stages['vehicle_collected']['sequence']);

        // The stage's timestamp is the real transition, not the order's
        // creation date or "now".
        $this->assertSame(
            now()->subDays(5)->toIso8601String(),
            $stages['collection_scheduled']['occurred_at'],
        );
    }

    public function test_the_timeline_history_is_the_status_trail_without_audit_metadata(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::VehicleCollected);
        $this->recordTransition($order, 'order_requested', 'confirmed', now()->subDays(5));
        $this->recordTransition($order, 'confirmed', 'vehicle_collected', now()->subDays(2));

        $response = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.timeline', $order->id))
            ->assertOk()
            ->assertJsonCount(2, 'data.history')
            // Newest first.
            ->assertJsonPath('data.history.0.status', 'vehicle_collected')
            ->assertJsonPath('data.history.0.previous_status', 'confirmed')
            ->assertJsonPath('data.history.1.status', 'confirmed');

        // Who moved it, from where, and under which auth is ours.
        $body = $response->json('data.history');
        foreach ($body as $entry) {
            $this->assertSame(
                ['status', 'status_label', 'previous_status', 'occurred_at'],
                array_keys($entry),
            );
        }

        $raw = $response->getContent();
        $this->assertStringNotContainsString('Admin Mustermann', $raw);
        $this->assertStringNotContainsString('203.0.113.7', $raw);
        $this->assertStringNotContainsString('auth_source', $raw);
    }

    public function test_an_internal_order_note_never_reaches_the_timeline(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::VehicleCollected);
        $this->recordTransition($order, 'confirmed', 'vehicle_collected');

        B2bOrderNote::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'visibility' => B2bOrderNote::VISIBILITY_INTERNAL,
            'body' => 'Kunde zahlt schlecht, Vorkasse verlangen.',
            'author_name' => 'Admin',
        ]);

        foreach (['partner.v1.orders.timeline', 'partner.v1.orders.status'] as $route) {
            $response = $this->withHeaders($this->bearer($token))
                ->getJson(route($route, $order->id))
                ->assertOk();

            $this->assertStringNotContainsString('Vorkasse', $response->getContent());
            $this->assertStringNotContainsString('internal_note', $response->getContent());
        }
    }

    public function test_a_published_offer_moves_the_timeline_to_approval_required(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        $this->recordTransition($order, 'vehicle_collected', 'inspected', now()->subDays(3));
        $this->makePresentedOffer($order, 'published');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.status', $order->id))
            ->assertOk()
            // `inspected` alone is stage 6; a published offer advances it to 7.
            ->assertJsonPath('data.status.stage', 'approval_required')
            ->assertJsonPath('data.status.stage_sequence', 7);
    }

    public function test_an_accepted_offer_moves_the_timeline_to_repair_approved(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        $this->recordTransition($order, 'vehicle_collected', 'inspected', now()->subDays(3));
        $this->makePresentedOffer($order, 'selected');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.status', $order->id))
            ->assertOk()
            ->assertJsonPath('data.status.stage', 'repair_approved')
            ->assertJsonPath('data.status.stage_sequence', 8);
    }

    public function test_an_unpublished_report_document_cannot_date_a_stage(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        $this->recordTransition($order, 'vehicle_collected', 'inspected', now()->subDays(3));

        $draft = $this->makeReportDocument($order, 'gutachten', published: false);
        $draft->forceFill(['created_at' => now()->subDay()])->save();

        $response = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.timeline', $order->id))
            ->assertOk();

        $stages = collect($response->json('data.stages'))->keyBy('code');

        // The draft's own date would have been yesterday; the stage falls back
        // to the `inspected` transition instead.
        $this->assertSame(
            now()->subDays(3)->toIso8601String(),
            $stages['initial_appraisal']['occurred_at'],
        );
    }

    public function test_a_cancelled_order_stops_at_the_stage_it_reached(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Cancelled);
        $this->recordTransition($order, 'confirmed', 'vehicle_collected', now()->subDays(4));
        $this->recordTransition($order, 'vehicle_collected', 'cancelled', now()->subDay());

        $response = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.timeline', $order->id))
            ->assertOk()
            ->assertJsonPath('data.is_cancelled', true)
            ->assertJsonPath('data.current_stage', null);

        $stages = collect($response->json('data.stages'))->keyBy('code');

        $this->assertSame('cancelled', $stages['vehicle_collected']['state']);
        $this->assertSame(now()->subDay()->toIso8601String(), $stages['vehicle_collected']['occurred_at']);
        $this->assertSame('completed', $stages['collection_scheduled']['state']);
        $this->assertSame('upcoming', $stages['initial_appraisal']['state']);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.status', $order->id))
            ->assertOk()
            ->assertJsonPath('data.status.is_open', false)
            ->assertJsonPath('data.status.is_cancelled', true)
            ->assertJsonPath('data.status.stage', null);
    }

    public function test_another_companys_order_has_no_timeline(): void
    {
        $other = $this->makePartnerCompany('Fremde GmbH');
        $foreign = $this->makeB2bOrder($other->b2b_id, OrderStatus::VehicleCollected);
        $this->recordTransition($foreign, 'confirmed', 'vehicle_collected');

        [, $token] = $this->makeAuthenticatedPartner();

        foreach (['partner.v1.orders.timeline', 'partner.v1.orders.status'] as $route) {
            $this->withHeaders($this->bearer($token))
                ->getJson(route($route, $foreign->id))
                ->assertNotFound()
                ->assertJsonPath('error.code', 'order_not_found');
        }
    }

    public function test_a_b2c_orders_timeline_is_not_reachable(): void
    {
        $b2cOrder = LeasybackOrder::factory()->create();

        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.timeline', $b2cOrder->id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'order_not_found');
    }

    public function test_the_timeline_requires_the_timeline_scope(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner(
            abilities: [PartnerAbility::ReadOrders->value, PartnerAbility::ReadVehicles->value],
        );
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::VehicleCollected);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.timeline', $order->id))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope')
            ->assertJsonPath('error.details.required_ability', 'timeline.read');
    }

    public function test_the_timeline_requires_the_company_permission(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::VehicleCollected);

        $this->setCompanyPermissions($client, ['company.view']);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.status', $order->id))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_company_permission')
            ->assertJsonPath('error.details.required_permission', 'vehicles.view');
    }
}
