<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        // Locks in a real fix (Checkpoint 5): this used to be trans('auth.failed'),
        // which rendered as the literal string "auth.failed" — this app has no
        // lang/ directory for it to translate against.
        $response->assertSessionHasErrors([
            'email' => 'Diese Anmeldedaten stimmen nicht mit unseren Aufzeichnungen überein.',
        ]);
        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_remember_me_sets_a_remember_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $this->assertAuthenticated();
        $rememberCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

        $this->assertNotNull($rememberCookie, 'Expected a remember_web_* cookie to be set.');
        $this->assertNotEmpty($user->fresh()->remember_token);
    }

    public function test_login_without_remember_does_not_set_a_remember_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $rememberCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

        $this->assertNull($rememberCookie);
    }

    public function test_login_is_rate_limited_after_too_many_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        // 6th attempt, even with the CORRECT password, must be blocked by the lockout.
        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');

        // Locks in a real fix (Checkpoint 5): trans('auth.throttle', [...])
        // used to render as the literal string "auth.throttle" with the
        // :seconds/:minutes placeholders never substituted — placeholder
        // substitution only happens against a *found* translation line, and
        // this app has no lang/ directory. The exact seconds-remaining count
        // is timing-dependent, so only the fixed prefix is asserted here.
        $message = session('errors')->get('email')[0];
        $this->assertStringStartsWith('Zu viele Anmeldeversuche. Bitte versuche es in ', $message);
        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_authenticate_via_the_login_screen(): void
    {
        // Checkpoint 6: Auth::attempt() previously had no is_active constraint
        // at all — a deactivated account could still start a new session.
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // Same generic failure, same exact message, as a wrong password —
        // Auth::attempt's query constraint means the row simply isn't found
        // as a match, so there is nothing to distinguish (no enumeration).
        $response->assertSessionHasErrors([
            'email' => 'Diese Anmeldedaten stimmen nicht mit unseren Aufzeichnungen überein.',
        ]);
        $this->assertGuest();
    }

    public function test_deactivated_user_is_logged_out_of_an_existing_session(): void
    {
        // Checkpoint 6: mirrors the Sanctum-token fix from Checkpoint 4 — a
        // session started while active must stop working the moment the
        // account is deactivated, not just block new logins.
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertOk();

        $user->is_active = false;
        $user->save();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status', 'Dieser Account wurde deaktiviert.');
        $this->assertGuest();
    }
}
