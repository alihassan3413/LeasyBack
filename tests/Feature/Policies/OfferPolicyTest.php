<?php

namespace Tests\Feature\Policies;

use App\Enums\UserType;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer as CanonicalOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function offerFor(int $ownerId): LeasybackOffer
    {
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $ownerId]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        $created = CanonicalOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        return LeasybackOffer::find($created->offer_id);
    }

    /**
     * Direct Policy-level regression for the fixed customerSelect BOLA.
     */
    public function test_owner_can_select_own_offer(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $offer = $this->offerFor($owner->id);

        $this->assertTrue($owner->can('select', $offer));
    }

    public function test_non_owner_cannot_select_offer(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $offer = $this->offerFor($owner->id);

        $this->assertFalse($stranger->can('select', $offer));
    }

    public function test_admin_cannot_select_via_the_customer_ability(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $offer = $this->offerFor($owner->id);

        $this->assertFalse($admin->can('select', $offer));
    }

    /**
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Offer row: Werkstatt is ❌
     * across the board.
     */
    public function test_werkstatt_cannot_select_or_view_offers(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $werkstatt = User::factory()->create(['user_type' => UserType::Werkstatt]);
        $offer = $this->offerFor($owner->id);

        $this->assertFalse($werkstatt->can('select', $offer));
        $this->assertFalse($werkstatt->can('viewAny', LeasybackOffer::class));
    }

    public function test_only_admin_can_manage_offers(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $werkstatt = User::factory()->create(['user_type' => UserType::Werkstatt]);

        foreach (['viewAny', 'create', 'publish', 'cancel'] as $ability) {
            $this->assertTrue($admin->can($ability, LeasybackOffer::class), "Admin should be able to {$ability}");
            $this->assertFalse($customer->can($ability, LeasybackOffer::class), "Customer should not be able to {$ability}");
            $this->assertFalse($werkstatt->can($ability, LeasybackOffer::class), "Werkstatt should not be able to {$ability}");
        }
    }
}
