<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        $response = $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('onboarding.show', absolute: false));
    }

    public function test_firmenkunde_and_werkstatt_registration_redirects_straight_to_dashboard(): void
    {
        // Only Privatkunde (B2C) goes through the onboarding wizard.
        $response = $this->post('/register', [
            'user_type' => 'Firmenkunde',
            'email' => 'firmenkunde-redirect@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_name_defaults_to_the_email_local_part_when_omitted(): void
    {
        // The web form (matching leasyback_web) has no Name field at all —
        // mirrors Api\RegisterRequest's same fallback.
        $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'jane.doe@example.com',
            'password' => 'password',
        ]);

        $user = User::where('email', 'jane.doe@example.com')->firstOrFail();

        $this->assertSame('jane.doe', $user->name);
    }

    public function test_registration_is_active_by_default(): void
    {
        $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'privatkunde-check@example.com',
            'password' => 'password',
        ]);

        $user = User::where('email', 'privatkunde-check@example.com')->firstOrFail();

        $this->assertSame('Privatkunde', $user->user_type->value);
        $this->assertTrue($user->is_active);
    }

    public function test_users_can_register_as_firmenkunde(): void
    {
        $this->post('/register', [
            'user_type' => 'Firmenkunde',
            'email' => 'firmenkunde-check@example.com',
            'password' => 'password',
        ]);

        $this->assertSame('Firmenkunde', User::where('email', 'firmenkunde-check@example.com')->firstOrFail()->user_type->value);
    }

    public function test_users_can_register_as_werkstatt(): void
    {
        // Note: "Werksatatt" is the real stored enum value (see UserType::Werkstatt).
        $this->post('/register', [
            'user_type' => 'Werksatatt',
            'email' => 'werkstatt-check@example.com',
            'password' => 'password',
        ]);

        $this->assertSame('Werksatatt', User::where('email', 'werkstatt-check@example.com')->firstOrFail()->user_type->value);
    }

    public function test_registration_rejects_admin_as_a_selectable_user_type(): void
    {
        // Admin is deliberately excluded from UserType::registrableValues() —
        // this must fail validation, not silently downgrade to Privatkunde.
        $response = $this->post('/register', [
            'user_type' => 'Admin',
            'email' => 'no-admin-smuggling@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('user_type');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'no-admin-smuggling@example.com']);
    }

    public function test_registration_rejects_case_insensitive_duplicate_email(): void
    {
        User::factory()->create(['email' => 'Duplicate@Example.com']);

        $response = $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'duplicate@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
