<?php

namespace Tests\Feature\Auth\Mfa;

use App\Enums\UserType;
use App\Mail\MfaCode;
use App\Models\MfaLoginChallenge;
use App\Models\User;
use App\Services\Mfa\MfaChallengeService;
use App\Services\Mfa\MfaRecoveryCodeService;
use App\Services\Mfa\MfaTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Signing in with a second factor, through both the token API and the browser.
 *
 * The property these tests exist to defend is narrow and absolute: a correct
 * password on its own must never produce anything that can be used. Everything
 * else here is a way of trying to break that.
 */
class MfaLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private MfaTotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        $this->totp = app(MfaTotpService::class);

        config(['mfa.enabled' => true, 'mfa.required' => false, 'mfa.required_for' => ['admin']]);
    }

    // ------------------------------------------------------------- api login

    public function test_a_user_without_mfa_logs_in_as_before(): void
    {
        $user = $this->user(UserType::Privatkunde);

        $response = $this->postJson('/api/auth/login', [
            'user_email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNull($response->json('data.mfa_required'));
    }

    public function test_an_enrolled_user_gets_a_ticket_instead_of_a_token(): void
    {
        $user = $this->totpUser();

        $response = $this->postJson('/api/auth/login', [
            'user_email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.mfa_method', 'totp');

        $this->assertNotEmpty($response->json('data.ticket'));
    }

    /**
     * The whole point. A password alone must not yield a credential — not a
     * token in the body, and not a token quietly minted server side.
     */
    public function test_a_password_alone_creates_no_usable_token(): void
    {
        $user = $this->totpUser();

        $response = $this->postJson('/api/auth/login', [
            'user_email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertNull($response->json('data.token'));
        $this->assertSame(0, PersonalAccessToken::count(), 'a token was created before MFA');
    }

    public function test_a_correct_totp_code_completes_the_login(): void
    {
        $user = $this->totpUser();
        $ticket = $this->ticketFor($user);

        $response = $this->postJson('/api/mfa/verify', [
            'ticket' => $ticket,
            'code' => $this->totp->codeAt($user->mfa_secret, $this->totp->stepAt()),
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertNotEmpty($response->json('data.token'));

        // The name is what the middleware reads to trust this token.
        $this->assertSame('mfa-verified', PersonalAccessToken::firstOrFail()->name);
    }

    public function test_a_wrong_totp_code_is_rejected_and_issues_nothing(): void
    {
        $user = $this->totpUser();
        $ticket = $this->ticketFor($user);

        $this->postJson('/api/mfa/verify', ['ticket' => $ticket, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_forged_ticket_is_rejected(): void
    {
        $this->totpUser();

        $this->postJson('/api/mfa/verify', ['ticket' => str_repeat('z', 64), 'code' => '123456'])
            ->assertStatus(401);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    /** One user's ticket must not be completable with another's code. */
    public function test_a_ticket_cannot_be_completed_with_another_users_code(): void
    {
        $mine = $this->totpUser();
        $theirs = $this->totpUser();

        $ticket = $this->ticketFor($mine);
        $code = $this->totp->codeAt($theirs->mfa_secret, $this->totp->stepAt());

        $this->postJson('/api/mfa/verify', ['ticket' => $ticket, 'code' => $code])->assertStatus(422);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_challenge_dies_after_five_wrong_codes(): void
    {
        config(['mfa.challenge.max_attempts' => 5]);
        $user = $this->totpUser();
        $ticket = $this->ticketFor($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/mfa/verify', ['ticket' => $ticket, 'code' => '000000'])->assertStatus(422);
        }

        // Even the right code cannot save a spent challenge.
        $this->postJson('/api/mfa/verify', [
            'ticket' => $ticket,
            'code' => $this->totp->codeAt($user->mfa_secret, $this->totp->stepAt()),
        ])->assertStatus(401);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_replayed_totp_code_cannot_be_used_twice(): void
    {
        $user = $this->totpUser();
        $code = $this->totp->codeAt($user->mfa_secret, $this->totp->stepAt());

        $this->postJson('/api/mfa/verify', ['ticket' => $this->ticketFor($user), 'code' => $code])->assertOk();

        // Same code, same window, brand new ticket.
        $this->postJson('/api/mfa/verify', ['ticket' => $this->ticketFor($user->fresh()), 'code' => $code])
            ->assertStatus(422);

        $this->assertSame(1, PersonalAccessToken::count());
    }

    // ------------------------------------------------------------- email otp

    public function test_an_emailed_code_completes_the_login(): void
    {
        $user = $this->emailUser();
        $ticket = $this->ticketFor($user);

        $this->postJson('/api/mfa/send-email', ['ticket' => $ticket])->assertOk();

        $code = '';
        Mail::assertSent(MfaCode::class, function (MfaCode $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->postJson('/api/mfa/verify', ['ticket' => $ticket, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_an_expired_emailed_code_is_rejected(): void
    {
        $user = $this->emailUser();
        $ticket = $this->ticketFor($user);
        $this->postJson('/api/mfa/send-email', ['ticket' => $ticket])->assertOk();

        $code = '';
        Mail::assertSent(MfaCode::class, function (MfaCode $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->travel(11)->minutes();

        $this->postJson('/api/mfa/verify', ['ticket' => $ticket, 'code' => $code])->assertStatus(401);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_code_is_never_returned_through_the_api(): void
    {
        $user = $this->emailUser();
        $ticket = $this->ticketFor($user);

        $response = $this->postJson('/api/mfa/send-email', ['ticket' => $ticket]);

        $code = '';
        Mail::assertSent(MfaCode::class, function (MfaCode $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->assertStringNotContainsString($code, $response->getContent());
    }

    // -------------------------------------------------------- recovery codes

    public function test_a_recovery_code_works_exactly_once(): void
    {
        $user = $this->totpUser();
        $codes = app(MfaRecoveryCodeService::class)->generate();
        $user->forceFill(['mfa_recovery_codes' => $codes['hashed']])->save();

        $this->postJson('/api/mfa/verify', [
            'ticket' => $this->ticketFor($user),
            'code' => $codes['plain'][0],
        ])->assertOk();

        $this->postJson('/api/mfa/verify', [
            'ticket' => $this->ticketFor($user->fresh()),
            'code' => $codes['plain'][0],
        ])->assertStatus(422);

        $this->assertSame(1, PersonalAccessToken::count());
    }

    // ------------------------------------------------------------ enforcement

    /** A token issued before MFA was switched on must stop working. */
    public function test_a_pre_mfa_token_is_refused_once_the_requirement_applies(): void
    {
        $user = $this->user(UserType::Admin);
        $token = $user->createToken('auth-token')->plainTextToken;

        // Not yet enrolled, so the enrollment endpoints stay reachable.
        $this->withToken($token)->postJson('/api/mfa/setup')->assertOk();

        $user->forceFill([
            'mfa_secret' => $this->totp->generateSecret(),
            'mfa_method' => 'totp',
            'mfa_confirmed_at' => now(),
        ])->save();

        // Now enrolled: the old token is not MFA-verified and is revoked.
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(403);
        $this->assertSame(0, PersonalAccessToken::where('name', 'auth-token')->count());
    }

    // ------------------------------------------------------------ web login

    public function test_the_browser_login_stops_at_the_challenge(): void
    {
        $user = $this->totpUser(UserType::Admin);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('mfa.verify'));

        $this->assertGuest();
    }

    public function test_the_browser_login_completes_after_a_correct_code(): void
    {
        $user = $this->totpUser(UserType::Admin);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->post(route('mfa.verify.store'), [
            'code' => $this->totp->codeAt($user->mfa_secret, $this->totp->stepAt()),
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_code_leaves_the_browser_unauthenticated(): void
    {
        $user = $this->totpUser(UserType::Admin);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->post(route('mfa.verify.store'), ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    /** Someone who owes a factor but has none is pinned to setup, not locked out. */
    public function test_a_user_who_must_enroll_is_sent_to_setup(): void
    {
        $user = $this->user(UserType::Admin);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertRedirect(route('mfa.setup'));
    }

    // ---------------------------------------------------------------- helpers

    private function ticketFor(User $user): string
    {
        return app(MfaChallengeService::class)
            ->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);
    }

    private function user(UserType $type = UserType::Privatkunde): User
    {
        return User::factory()->create([
            'user_type' => $type,
            'is_active' => true,
            'password' => self::PASSWORD,
        ]);
    }

    private function totpUser(UserType $type = UserType::Privatkunde): User
    {
        $user = $this->user($type);

        $user->forceFill([
            'mfa_secret' => $this->totp->generateSecret(),
            'mfa_method' => 'totp',
            'mfa_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    private function emailUser(UserType $type = UserType::Privatkunde): User
    {
        $user = $this->user($type);

        $user->forceFill([
            'mfa_method' => 'email',
            'mfa_confirmed_at' => now(),
            'mfa_email_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }
}
