<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\InspectionStation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_tuvsud_order(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('leasyback_orders', [
            'vehicle_id' => $vehicle->vehicle_id,
            'leasyback_partner' => 'tuvsud',
            'order_status' => 'order_placed',
        ]);
    }

    public function test_owner_can_create_an_other_provider_order_via_station_choice(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $station = InspectionStation::factory()->create(['provider' => 'dekra']);

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('leasyback_orders', [
            'vehicle_id' => $vehicle->vehicle_id,
            'leasyback_partner' => 'dekra',
            'order_status' => 'order_placed',
        ]);
    }

    public function test_non_owner_cannot_create_an_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $this->actingAs($intruder)
            ->from(route('dashboard'))
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('leasyback_orders', ['vehicle_id' => $vehicle->vehicle_id]);
    }

    public function test_cannot_create_order_when_vehicle_has_an_unfinished_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_placed',
        ]);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertSame(1, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
    }
}
