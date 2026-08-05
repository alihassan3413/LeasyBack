<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Models\B2B;
use App\Modules\UserProfile\Order\Models\InspectionStation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Profile\Models\Address;
use App\Modules\UserProfile\Profile\Models\Contact;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function stationPayload(): array
    {
        return [
            'name' => 'Test Station',
            'strasse' => 'Teststrasse 1',
            'plz' => '10115',
            'ort' => 'Berlin',
        ];
    }

    /**
     * Regression test: createStation() previously had no role check at
     * all — any authenticated user could create inspection stations.
     */
    public function test_non_admin_cannot_create_inspection_station(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->postJson('/order/stations/create', $this->stationPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('inspection_stations', ['name' => 'Test Station']);
    }

    public function test_admin_can_create_inspection_station(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->withHeaders($this->bearer($admin))
            ->postJson('/order/stations/create', $this->stationPayload())
            ->assertCreated();

        $this->assertDatabaseHas('inspection_stations', ['name' => 'Test Station']);
    }

    /**
     * Regression test for the OrderService extraction: Privatkunde bookings
     * are still sent to TÜV SÜD immediately and saved as order_placed.
     */
    public function test_privatkunde_order_is_sent_to_tuvsud_immediately(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $response = $this->withHeaders($this->bearer($owner))
            ->postJson("/order/tuvsud/create/{$vehicle->vehicle_id}", [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('leasyback_orders', [
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_placed',
        ]);
    }

    /**
     * Regression test: Firmenkunde collection orders are still staged as
     * order_requested (pending Admin approval), no external call made.
     * Booked through the B2B collection endpoint — a B2B vehicle no longer
     * goes through the TÜV SÜD station flow.
     */
    public function test_firmenkunde_order_is_staged_for_approval(): void
    {
        $b2bUser = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $b2b = B2B::create([
            'contact_id' => Contact::factory()->create()->contact_id,
            'address_id' => Address::factory()->create()->address_id,
            'company_name' => fake()->company(),
        ]);
        $vehicle = Vehicle::factory()->forB2b($b2b->b2b_id)->create();
        DB::table('user_b2b')->insert([
            'user_id' => $b2bUser->id,
            'b2b_id' => $vehicle->b2b_id,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $response = $this->withHeaders($this->bearer($b2bUser))
            ->postJson("/order/b2b/create/{$vehicle->vehicle_id}", [
                'requested_collection_date' => now()->addWeek()->toDateString(),
                'collection_address' => ['street' => 'Werkstr', 'zip_code' => '80331', 'city' => 'München'],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('leasyback_orders', [
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_requested',
        ]);
    }

    /**
     * Regression test: the unfinished-order guard (now delegated to
     * VehicleService::hasUnfinishedOrder()) still blocks a new booking.
     */
    public function test_cannot_create_order_when_vehicle_has_an_unfinished_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_placed',
        ]);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $response = $this->withHeaders($this->bearer($owner))
            ->postJson("/order/tuvsud/create/{$vehicle->vehicle_id}", [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ]);

        $response->assertStatus(409);
    }

    public function test_can_create_an_other_provider_order(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $station = InspectionStation::factory()->create(['provider' => 'dekra']);

        $response = $this->withHeaders($this->bearer($owner))
            ->postJson("/order/others/create/{$vehicle->vehicle_id}", [
                'provider' => 'dekra',
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('leasyback_orders', [
            'vehicle_id' => $vehicle->vehicle_id,
            'leasyback_partner' => 'dekra',
            'order_status' => 'order_placed',
        ]);
    }
}
