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
}
