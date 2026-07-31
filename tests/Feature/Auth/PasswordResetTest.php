<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', 'Dein Passwort wurde erfolgreich zurückgesetzt.')
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_password_reset_request_gives_a_generic_response_for_a_nonexistent_email(): void
    {
        Notification::fake();

        $response = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        // Same generic status regardless of whether the account exists — no enumeration.
        $response->assertSessionHas('status', 'Ein Link zum Zurücksetzen wird gesendet, falls dieser Account existiert.');
        Notification::assertNothingSent();
    }

    public function test_password_reset_fails_with_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasErrors([
            'email' => 'Dieser Link zum Zurücksetzen ist ungültig oder abgelaufen. Bitte fordere einen neuen an.',
        ]);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_reset_fails_with_an_expired_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            // config/auth.php: passwords.users.expire = 60 (minutes).
            Carbon::setTestNow(now()->addMinutes(61));

            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            $response->assertSessionHasErrors([
                'email' => 'Dieser Link zum Zurücksetzen ist ungültig oder abgelaufen. Bitte fordere einen neuen an.',
            ]);
            $this->assertTrue(Hash::check('password', $user->fresh()->password));

            Carbon::setTestNow();

            return true;
        });
    }
}
