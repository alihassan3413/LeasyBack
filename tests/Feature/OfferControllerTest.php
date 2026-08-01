<?php

namespace Tests\Feature;

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

    public function test_owner_can_select_own_offer(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        $offer = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        $this->actingAs($owner)
            ->post(route('offers.select', $offer->offer_id))
            ->assertRedirect(route('dashboard'));

        $this->assertSame('selected', $offer->fresh()->offer_status);
    }

    /**
     * Web counterpart of the API's BOLA regression test: OfferPolicy::select
     * still gates this route, so a non-owner gets a 404, not a redirect.
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

        $this->actingAs($attacker)
            ->post(route('offers.select', $offer->offer_id))
            ->assertNotFound();

        $this->assertSame('published', $offer->fresh()->offer_status);
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

        $this->actingAs($owner)
            ->post(route('offers.select', $offerA->offer_id))
            ->assertRedirect(route('dashboard'));

        $this->assertSame('selected', $offerA->fresh()->offer_status);
        $this->assertSame('closed', $offerB->fresh()->offer_status);
    }
}
