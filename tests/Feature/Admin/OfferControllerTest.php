<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function offerPayload(): array
    {
        return [
            'repair_cost_net' => 100,
            'repair_cost_gross' => 119,
            'depreciation_value_net' => 50,
            'depreciation_value_gross' => 59.5,
            'workshop_repair_quote_net' => 0,
            'workshop_repair_quote_gross' => 0,
            'missing_parts_cost_net' => 0,
            'missing_parts_cost_gross' => 0,
            'additional_notes' => 'Test offer',
        ];
    }

    public function test_admin_can_create_a_draft_offer_for_an_order(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.orders.offers.store', $order->id), $this->offerPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('leasyback_offers', [
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => 1,
            'offer_status' => 'draft',
            'additional_notes' => 'Test offer',
        ]);
    }

    /**
     * CreateOfferModal shows a running "Gesamtsumme" while the admin types,
     * and that preview is only honest if the stored total is the same sum.
     * LeasybackOffer's `saving` hook adds the four positions up — net and
     * gross each summed independently, not gross derived from net — so the
     * modal previews it exactly that way. This pins the arithmetic the
     * preview is promising.
     */
    public function test_the_stored_total_is_the_sum_of_the_four_positions(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();

        $this->actingAs($admin)->post(route('admin.orders.offers.store', $order->id), [
            'repair_cost_net' => 100,
            'repair_cost_gross' => 119,
            'depreciation_value_net' => 50,
            'depreciation_value_gross' => 59.5,
            'workshop_repair_quote_net' => 200,
            'workshop_repair_quote_gross' => 238,
            'missing_parts_cost_net' => 25,
            'missing_parts_cost_gross' => 29.75,
            'additional_notes' => null,
        ])->assertRedirect();

        $offer = LeasybackOffer::where('order_id', $order->id)->firstOrFail();

        $this->assertSame('375.00', (string) $offer->final_total_net);
        $this->assertSame('446.25', (string) $offer->final_total_gross);
    }

    public function test_offer_sequence_increments_per_order(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();
        LeasybackOffer::factory()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer, 'offer_sequence' => 1]);

        $this->actingAs($admin)->post(route('admin.orders.offers.store', $order->id), $this->offerPayload());

        $this->assertDatabaseHas('leasyback_offers', [
            'order_id' => $order->id,
            'offer_sequence' => 2,
        ]);
    }

    public function test_non_admin_cannot_create_an_offer(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $order = LeasybackOrder::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.orders.offers.store', $order->id), $this->offerPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('leasyback_offers', 0);
    }

    public function test_admin_can_publish_a_draft_offer(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertRedirect();

        $offer->refresh();
        $this->assertSame('published', $offer->offer_status);
        $this->assertNotNull($offer->published_at);
    }

    public function test_publishing_a_non_draft_offer_fails(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->published()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame('published', $offer->fresh()->offer_status);
    }

    /**
     * "Im Auftrag des Kunden annehmen" — the v1 Admin behaviour, added on an
     * explicit product decision. It is a separate route/ability from the
     * customer's offers.select, which stays owner-only (OfferPolicy::select).
     */
    public function test_admin_can_accept_a_published_offer_on_the_customers_behalf(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.offers.select', $offer->offer_id))
            ->assertRedirect();

        $offer->refresh();
        $this->assertSame('selected', $offer->offer_status);
        $this->assertSame($admin->id, $offer->selected_by_user_id);
    }

    /** The audit trail has to tell an admin acceptance apart from a customer one. */
    public function test_accepting_on_behalf_is_recorded_as_an_admin_action(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->actingAs($admin)->patch(route('admin.orders.offers.select', $offer->offer_id));

        $this->assertDatabaseHas('leasyback_offer_audit_log', [
            'offer_id' => $offer->offer_id,
            'action' => 'selected_by_admin_on_behalf',
            'changed_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseMissing('leasyback_offer_audit_log', [
            'offer_id' => $offer->offer_id,
            'action' => 'selected_by_customer',
        ]);
    }

    public function test_accepting_on_behalf_closes_the_sibling_offers(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();
        $chosen = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => 1,
        ]);
        $sibling = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => 2,
        ]);

        $this->actingAs($admin)->patch(route('admin.orders.offers.select', $chosen->offer_id));

        $this->assertSame('selected', $chosen->fresh()->offer_status);
        $this->assertSame('closed', $sibling->fresh()->offer_status);
    }

    public function test_admin_cannot_accept_a_draft_offer_on_behalf(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.offers.select', $offer->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame('draft', $offer->fresh()->offer_status);
    }

    public function test_non_admin_cannot_accept_an_offer_on_a_customers_behalf(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->actingAs($owner)
            ->patch(route('admin.orders.offers.select', $offer->offer_id))
            ->assertForbidden();

        $this->assertSame('published', $offer->fresh()->offer_status);
    }

    public function test_admin_can_cancel_an_offer_with_a_reason(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.offers.cancel', $offer->offer_id), ['cancellation_reason' => 'Kunde hat abgelehnt'])
            ->assertRedirect();

        $offer->refresh();
        $this->assertSame('cancelled', $offer->offer_status);
        $this->assertSame('Kunde hat abgelehnt', $offer->cancellation_reason);
    }

    public function test_non_admin_cannot_publish_or_cancel_an_offer(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer]);

        $this->actingAs($user)
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('admin.orders.offers.cancel', $offer->offer_id))
            ->assertForbidden();

        $this->assertSame('draft', $offer->fresh()->offer_status);
    }
}
