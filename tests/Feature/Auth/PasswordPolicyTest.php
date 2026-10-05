<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * One password policy, enforced identically wherever a password is set.
 *
 * The complexity requirements had silently disappeared from the account page
 * while the other five screens still enforced them, so the weakest screen in
 * the application decided what a password had to be. These tests set a
 * 12-character password that is long enough but has no capital and no digit,
 * and require every entry point to refuse it — so a screen cannot drift again
 * without a failing test.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** Long enough, but no upper case and no digit. */
    private const WEAK = 'abcdefghijkl';

    private const STRONG = 'Abcdefgh1234';

    private const CURRENT = 'CurrentPass123';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_web_registration_requires_mixed_case_and_a_number(): void
    {
        $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'weak@example.com',
            'password' => self::WEAK,
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    public function test_api_registration_requires_mixed_case_and_a_number(): void
    {
        $this->postJson('/api/auth/register', [
            'user_type' => 'Privatkunde',
            'user_email' => 'api-weak@example.com',
            'password' => self::WEAK,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'api-weak@example.com']);
    }

    /**
     * The account page — this is the screen the requirements had been lost on.
     */
    public function test_the_account_page_requires_mixed_case_and_a_number(): void
    {
        $user = $this->userWithKnownPassword();

        $this->actingAs($user)->put('/settings/profile/password', [
            'current_password' => self::CURRENT,
            'password' => self::WEAK,
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_the_password_settings_screen_requires_mixed_case_and_a_number(): void
    {
        $user = $this->userWithKnownPassword();

        $this->actingAs($user)->put('/settings/password', [
            'current_password' => self::CURRENT,
            'password' => self::WEAK,
            'password_confirmation' => self::WEAK,
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_the_password_reset_screen_requires_mixed_case_and_a_number(): void
    {
        $user = $this->userWithKnownPassword();

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => self::WEAK,
            'password_confirmation' => self::WEAK,
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_the_api_change_password_endpoint_requires_mixed_case_and_a_number(): void
    {
        $user = $this->userWithKnownPassword();

        $this->actingAs($user)->postJson('/api/auth/changepassword', [
            'current_password' => self::CURRENT,
            'new_password' => self::WEAK,
        ])->assertStatus(422)->assertJsonValidationErrors('new_password');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    /**
     * The policy must still let a real password through — without this, the
     * refusals above would also pass if everything were rejected.
     */
    public function test_a_password_meeting_the_policy_is_accepted_everywhere(): void
    {
        $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'strong@example.com',
            'password' => self::STRONG,
        ])->assertSessionHasNoErrors();

        $account = $this->userWithKnownPassword('account@example.com');
        $this->actingAs($account)->put('/settings/profile/password', [
            'current_password' => self::CURRENT,
            'password' => self::STRONG,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::STRONG, $account->fresh()->password));

        $settings = $this->userWithKnownPassword('settings@example.com');
        $this->actingAs($settings)->put('/settings/password', [
            'current_password' => self::CURRENT,
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::STRONG, $settings->fresh()->password));

        $api = $this->userWithKnownPassword('api-change@example.com');
        $this->actingAs($api)->postJson('/api/auth/changepassword', [
            'current_password' => self::CURRENT,
            'new_password' => self::STRONG,
        ])->assertOk();
        $this->assertTrue(Hash::check(self::STRONG, $api->fresh()->password));

    }

    public function test_a_password_meeting_the_policy_resets_successfully(): void
    {
        $reset = $this->userWithKnownPassword('reset@example.com');

        $response = $this->post('/reset-password', [
            'token' => Password::createToken($reset),
            'email' => $reset->email,
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::STRONG, $reset->fresh()->password));
    }

    /**
     * 128 characters is the ceiling, which also guards bcrypt's own 72-byte
     * truncation from being reached by an unbounded field.
     */
    public function test_an_overlong_password_is_refused(): void
    {
        $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'overlong@example.com',
            'password' => 'Aa1'.str_repeat('x', 126),
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'overlong@example.com']);
    }

    private function userWithKnownPassword(string $email = 'policy@example.com'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make(self::CURRENT),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
