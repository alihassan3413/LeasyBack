<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * Regression test for the fixed BOLA: customerSelect() used to have no
     * ownership check at all — any authenticated user could select any
     * published offer by guessing its id, closing out every competing
     * offer for that order in the process.
     */
    public function test_non_owner_cannot_select_another_customers_offer(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $attacker = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        $offer = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->withHeaders($this->bearer($attacker))
            ->postJson("/vehicle/offers/customer/select/{$offer->offer_id}")
            ->assertNotFound();

        $this->assertSame('published', $offer->fresh()->offer_status);
    }

    public function test_owner_can_select_own_offer(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        $offer = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->withHeaders($this->bearer($owner))
            ->postJson("/vehicle/offers/customer/select/{$offer->offer_id}")
            ->assertOk();

        $this->assertSame('selected', $offer->fresh()->offer_status);
    }

    public function test_selecting_an_offer_closes_sibling_offers_for_the_same_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        $offerA = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer, 'offer_sequence' => 1,
        ]);
        $offerB = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer, 'offer_sequence' => 2,
        ]);

        $this->withHeaders($this->bearer($owner))
            ->postJson("/vehicle/offers/customer/select/{$offerA->offer_id}")
            ->assertOk();

        $this->assertSame('selected', $offerA->fresh()->offer_status);
        $this->assertSame('closed', $offerB->fresh()->offer_status);
    }

    /**
     * Regression tests for the AdminPolicy refactor: create/publish/cancel/
     * adminList used to each inline-check user_type themselves; they now
     * authorize through the same OfferPolicy Checkpoint 6 already built and
     * tested (OfferPolicyTest), so this just proves the swap didn't drop
     * the guard on any of the four endpoints.
     */
    public function test_non_admin_cannot_create_an_offer(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $user->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->withHeaders($this->bearer($user))
            ->postJson("/admin/offers/create/{$order->auftragsnummer}", [
                'repair_cost_net' => 100, 'repair_cost_gross' => 119,
                'depreciation_value_net' => 100, 'depreciation_value_gross' => 119,
                'workshop_repair_quote_net' => 100, 'workshop_repair_quote_gross' => 119,
                'missing_parts_cost_net' => 0, 'missing_parts_cost_gross' => 0,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_create_an_offer(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $vehicle = Vehicle::factory()->create();
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->withHeaders($this->bearer($admin))
            ->postJson("/admin/offers/create/{$order->auftragsnummer}", [
                'repair_cost_net' => 100, 'repair_cost_gross' => 119,
                'depreciation_value_net' => 100, 'depreciation_value_gross' => 119,
                'workshop_repair_quote_net' => 100, 'workshop_repair_quote_gross' => 119,
                'missing_parts_cost_net' => 0, 'missing_parts_cost_gross' => 0,
            ])
            ->assertCreated();
    }

    public function test_non_admin_cannot_publish_an_offer(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->withHeaders($this->bearer($user))
            ->postJson("/admin/offers/publish/{$offer->offer_id}")
            ->assertForbidden();
    }

    public function test_non_admin_cannot_cancel_an_offer(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->withHeaders($this->bearer($user))
            ->postJson("/admin/offers/cancel/{$offer->offer_id}")
            ->assertForbidden();
    }

    public function test_non_admin_cannot_list_all_offers_for_an_order(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $user->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->withHeaders($this->bearer($user))
            ->getJson("/admin/offers/list/{$order->auftragsnummer}")
            ->assertForbidden();
    }
}
