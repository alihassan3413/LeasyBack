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
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_new_registration_is_privatkunde_and_active_by_default(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'privatkunde-check@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'privatkunde-check@example.com')->firstOrFail();

        $this->assertSame('Privatkunde', $user->user_type->value);
        $this->assertTrue($user->is_active);
    }

    public function test_registration_ignores_client_supplied_user_type(): void
    {
        // user_type isn't in RegisterRequest's rules and isn't mass-assignable
        // on the model — a client attempting to smuggle it in must have no effect.
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'no-smuggling@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'user_type' => 'Admin',
        ]);

        $user = User::where('email', 'no-smuggling@example.com')->firstOrFail();

        $this->assertSame('Privatkunde', $user->user_type->value);
    }

    public function test_registration_rejects_case_insensitive_duplicate_email(): void
    {
        User::factory()->create(['email' => 'Duplicate@Example.com']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'duplicate@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
