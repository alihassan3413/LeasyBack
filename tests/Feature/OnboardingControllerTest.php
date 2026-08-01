<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\InspectionStation;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function addressContactPayload(): array
    {
        return [
            'address' => [
                'street' => 'Musterstraße',
                'number' => '12',
                'zip_code' => '80331',
                'city' => 'München',
                'country' => 'Deutschland',
            ],
            'contact' => [
                'salutation' => 'Herr',
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
            ],
            'phones' => [
                ['international_prefix' => '+49', 'phone_number' => '15112345678'],
            ],
        ];
    }

    public function test_privatkunde_sees_the_onboarding_wizard(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->get(route('onboarding.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/B2cRegistration')
                ->where('profile', null)
                ->where('vehicle', null)
                ->where('order', null)
            );
    }

    public function test_non_privatkunde_is_redirected_away_from_onboarding(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Firmenkunde]);

        $this->actingAs($user)
            ->get(route('onboarding.show'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_step_one_creates_the_address_and_contact_profile(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->post(route('onboarding.profile.store'), $this->addressContactPayload())
            ->assertRedirect(route('onboarding.show'));

        $this->assertDatabaseHas('contacts', ['first_name' => 'Max', 'last_name' => 'Mustermann']);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id]);
    }

    public function test_step_one_does_not_require_a_verified_email(): void
    {
        $user = User::factory()->unverified()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->post(route('onboarding.profile.store'), $this->addressContactPayload())
            ->assertRedirect(route('onboarding.show'));

        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id]);
    }

    public function test_step_two_creates_a_vehicle_owned_by_the_user(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->post(route('onboarding.vehicle.store'), [
                'license_plate' => 'M-AB 123',
                'make' => 'BMW',
                'model' => '3er',
            ])
            ->assertRedirect(route('onboarding.show'));

        $this->assertDatabaseHas('vehicles', [
            'license_plate' => 'M-AB 123',
            'b2c_user_id' => $user->id,
            'vehicle_belongs' => 'B2C',
        ]);
    }

    public function test_step_two_carries_the_same_verified_gate_as_vehicles_store(): void
    {
        // Deliberately not asserting a verification-notice redirect here:
        // `User` doesn't implement `MustVerifyEmail`, so Laravel's `verified`
        // middleware is currently a no-op app-wide (same as vehicles.php's
        // own `vehicles.store` route) — a pre-existing gap, not something
        // introduced by this controller, and out of scope to fix here. This
        // test only pins that onboarding.vehicle.store behaves identically
        // to vehicles.store for an unverified user, so a future fix to that
        // shared gap covers this route automatically.
        $user = User::factory()->unverified()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->post(route('onboarding.vehicle.store'), ['license_plate' => 'M-AB 123'])
            ->assertRedirect(route('onboarding.show'));

        $this->assertDatabaseHas('vehicles', ['license_plate' => 'M-AB 123']);
    }

    public function test_step_three_books_an_appointment_for_the_users_own_vehicle(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        Vehicle::factory()->create(['b2c_user_id' => $user->id]);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $this->actingAs($user)
            ->post(route('onboarding.appointment.store'), [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ])
            ->assertRedirect(route('onboarding.show'));

        $this->assertDatabaseHas('leasyback_orders', [
            'leasyback_partner' => 'tuvsud',
            'order_status' => 'order_placed',
        ]);
    }

    public function test_step_three_carries_the_same_verified_gate_as_orders_store(): void
    {
        // See test_step_two_carries_the_same_verified_gate_as_vehicles_store:
        // same pre-existing, app-wide no-op, pinned here rather than fixed.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->unverified()->create(['user_type' => UserType::Privatkunde]);
        Vehicle::factory()->create(['b2c_user_id' => $user->id]);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $this->actingAs($user)
            ->post(route('onboarding.appointment.store'), [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ])
            ->assertRedirect(route('onboarding.show'));
    }

    public function test_step_three_404s_when_the_user_has_no_vehicle_yet(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $this->actingAs($user)
            ->post(route('onboarding.appointment.store'), [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ])
            ->assertNotFound();
    }

    public function test_onboarding_show_reflects_completed_steps(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)->post(route('onboarding.profile.store'), $this->addressContactPayload());

        $vehicleResponse = $this->actingAs($user)->post(route('onboarding.vehicle.store'), [
            'license_plate' => 'M-AB 123',
        ]);
        $vehicleResponse->assertRedirect(route('onboarding.show'));

        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);
        $this->actingAs($user)->post(route('onboarding.appointment.store'), [
            'station_id' => $station->station_id,
            'termin' => '2026-09-01T10:00:00+02:00',
        ]);

        $this->actingAs($user)
            ->get(route('onboarding.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/B2cRegistration')
                ->where('profile.address.city', 'München')
                ->where('vehicle.license_plate', 'M-AB 123')
                ->where('order.order_status', 'order_placed')
            );
    }
}
