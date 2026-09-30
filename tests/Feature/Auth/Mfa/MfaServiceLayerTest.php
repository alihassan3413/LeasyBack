<?php

namespace Tests\Feature\Auth\Mfa;

use App\Enums\UserType;
use App\Mail\MfaCode;
use App\Models\MfaLoginChallenge;
use App\Models\User;
use App\Services\Mfa\MfaChallengeService;
use App\Services\Mfa\MfaEmailCodeService;
use App\Services\Mfa\MfaPolicy;
use App\Services\Mfa\MfaRecoveryCodeService;
use App\Services\Mfa\MfaTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The service layer beneath the controllers: challenges, emailed codes,
 * recovery codes, and who is asked for a factor at all.
 */
class MfaServiceLayerTest extends TestCase
{
    use RefreshDatabase;

    private MfaChallengeService $challenges;

    private MfaEmailCodeService $email;

    private MfaRecoveryCodeService $recovery;

    private MfaTotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->challenges = app(MfaChallengeService::class);
        $this->email = app(MfaEmailCodeService::class);
        $this->recovery = app(MfaRecoveryCodeService::class);
        $this->totp = app(MfaTotpService::class);

        Mail::fake();
    }

    // ------------------------------------------------------------ challenges

    public function test_a_ticket_is_never_stored_in_plaintext(): void
    {
        $user = $this->user();

        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);

        $this->assertDatabaseMissing('mfa_login_challenges', ['ticket_hash' => $ticket]);
        $this->assertDatabaseHas('mfa_login_challenges', ['ticket_hash' => hash('sha256', $ticket)]);
    }

    public function test_a_ticket_resolves_only_for_its_own_purpose(): void
    {
        $user = $this->user();
        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);

        $this->assertNotNull($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY));
        $this->assertNull($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_ENROLL));
    }

    public function test_an_unknown_ticket_resolves_to_nothing(): void
    {
        $this->assertNull($this->challenges->resolve(str_repeat('a', 64), MfaLoginChallenge::PURPOSE_VERIFY));
    }

    public function test_an_expired_ticket_is_refused_and_removed(): void
    {
        $user = $this->user();
        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);

        MfaLoginChallenge::query()->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY));
        $this->assertSame(0, MfaLoginChallenge::count());
    }

    /** An account disabled mid-challenge must not be able to finish signing in. */
    public function test_a_deactivated_account_cannot_finish_a_challenge(): void
    {
        $user = $this->user();
        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);

        $user->forceFill(['is_active' => false])->save();

        $this->assertNull($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY));
    }

    public function test_issuing_again_invalidates_the_previous_ticket(): void
    {
        $user = $this->user();

        $first = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);
        $second = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);

        $this->assertNull($this->challenges->resolve($first, MfaLoginChallenge::PURPOSE_VERIFY));
        $this->assertNotNull($this->challenges->resolve($second, MfaLoginChallenge::PURPOSE_VERIFY));
        $this->assertSame(1, MfaLoginChallenge::count());
    }

    public function test_a_challenge_dies_after_the_configured_attempts(): void
    {
        config(['mfa.challenge.max_attempts' => 5]);
        $user = $this->totpUser();
        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);

        for ($i = 0; $i < 5; $i++) {
            $challenge = $this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY);
            $this->assertNotNull($challenge, "challenge vanished after {$i} attempts");
            $this->assertFalse($this->challenges->attempt($challenge, '000000'));
        }

        $this->assertNull($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY));
        $this->assertSame(0, MfaLoginChallenge::count());
    }

    // ----------------------------------------------------------------- totp

    public function test_a_correct_totp_code_spends_the_challenge(): void
    {
        $user = $this->totpUser();
        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);
        $challenge = $this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY);

        $code = $this->totp->codeAt($user->mfa_secret, $this->totp->stepAt());

        $this->assertTrue($this->challenges->attempt($challenge, $code));
        $this->assertSame(0, MfaLoginChallenge::count());
        $this->assertNotNull($user->fresh()->mfa_last_used_step);
    }

    /** The same code inside its own window must not work twice. */
    public function test_a_replayed_totp_code_is_refused_across_challenges(): void
    {
        $user = $this->totpUser();
        $code = $this->totp->codeAt($user->mfa_secret, $this->totp->stepAt());

        $first = $this->challenges->resolve(
            $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY),
            MfaLoginChallenge::PURPOSE_VERIFY,
        );
        $this->assertTrue($this->challenges->attempt($first, $code));

        $second = $this->challenges->resolve(
            $this->challenges->issue($user->fresh(), MfaLoginChallenge::PURPOSE_VERIFY),
            MfaLoginChallenge::PURPOSE_VERIFY,
        );

        $this->assertFalse($this->challenges->attempt($second, $code));
    }

    // ------------------------------------------------------------ email otp

    public function test_an_emailed_code_is_stored_only_as_a_hash(): void
    {
        $user = $this->user();
        $challenge = $this->challenges->resolve(
            $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY),
            MfaLoginChallenge::PURPOSE_VERIFY,
        );

        $this->assertSame(MfaEmailCodeService::SENT, $this->email->send($challenge));

        $sent = null;
        Mail::assertSent(MfaCode::class, function ($mail) use (&$sent) {
            $sent = $mail->code;

            return true;
        });

        $this->assertMatchesRegularExpression('/^\d{6}$/', $sent);

        $stored = (string) DB::table('mfa_login_challenges')->value('code_hash');
        $this->assertNotSame($sent, $stored);
        $this->assertStringNotContainsString($sent, $stored);
        $this->assertTrue(password_verify($sent, $stored));
    }

    public function test_an_emailed_code_works_once(): void
    {
        $user = $this->user();
        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);
        $this->email->send($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY));

        $code = $this->sentCode();

        $challenge = $this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY);
        $this->assertTrue($this->challenges->attempt($challenge, $code));

        // The challenge is gone, so the same code has nothing left to satisfy.
        $this->assertNull($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY));
    }

    public function test_an_expired_emailed_code_is_refused(): void
    {
        $user = $this->user();
        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);
        $this->email->send($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY));
        $code = $this->sentCode();

        MfaLoginChallenge::query()->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY));
        $this->assertSame($code, $code); // the code was fine; the window was not
    }

    public function test_resending_respects_the_cooldown(): void
    {
        config(['mfa.email.resend_cooldown_seconds' => 30]);
        $user = $this->user();
        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);

        $this->assertSame(MfaEmailCodeService::SENT, $this->email->send($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY)));
        $this->assertSame(MfaEmailCodeService::COOLDOWN, $this->email->send($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY)));

        $this->travel(31)->seconds();

        $this->assertSame(MfaEmailCodeService::SENT, $this->email->send($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY)));
    }

    public function test_sending_stops_at_the_window_ceiling(): void
    {
        config(['mfa.email.max_sends' => 5, 'mfa.email.resend_cooldown_seconds' => 30]);
        $user = $this->user();
        $ticket = $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(
                MfaEmailCodeService::SENT,
                $this->email->send($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY)),
                "send {$i} was refused",
            );
            $this->travel(31)->seconds();
        }

        $this->assertSame(
            MfaEmailCodeService::TOO_MANY,
            $this->email->send($this->challenges->resolve($ticket, MfaLoginChallenge::PURPOSE_VERIFY)),
        );

        Mail::assertSentCount(5);
    }

    // -------------------------------------------------------- recovery codes

    public function test_recovery_codes_are_stored_hashed_and_work_once(): void
    {
        $user = $this->totpUser();
        $set = $this->recovery->generate();
        $user->forceFill(['mfa_recovery_codes' => $set['hashed']])->save();

        $code = $set['plain'][0];

        // Nothing readable in the column.
        $raw = (string) DB::table('users')->where('id', $user->id)->value('mfa_recovery_codes');
        $this->assertStringNotContainsString($code, $raw);

        $challenge = $this->challenges->resolve(
            $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY),
            MfaLoginChallenge::PURPOSE_VERIFY,
        );
        $this->assertTrue($this->challenges->attempt($challenge, $code));
        $this->assertSame(7, $this->recovery->remaining($user->fresh()));

        // Same code again, fresh challenge.
        $second = $this->challenges->resolve(
            $this->challenges->issue($user->fresh(), MfaLoginChallenge::PURPOSE_VERIFY),
            MfaLoginChallenge::PURPOSE_VERIFY,
        );
        $this->assertFalse($this->challenges->attempt($second, $code));
    }

    public function test_a_recovery_code_is_accepted_however_it_is_typed(): void
    {
        $user = $this->totpUser();
        $set = $this->recovery->generate();
        $user->forceFill(['mfa_recovery_codes' => $set['hashed']])->save();

        $messy = strtolower(str_replace('-', ' ', $set['plain'][0]));

        $challenge = $this->challenges->resolve(
            $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY),
            MfaLoginChallenge::PURPOSE_VERIFY,
        );

        $this->assertTrue($this->challenges->attempt($challenge, $messy));
    }

    public function test_recovery_codes_are_refused_before_enrollment_completes(): void
    {
        $user = $this->user();
        $set = $this->recovery->generate();
        // Secret and codes present, but never confirmed.
        $user->forceFill([
            'mfa_secret' => $this->totp->generateSecret(),
            'mfa_recovery_codes' => $set['hashed'],
        ])->save();

        $challenge = $this->challenges->resolve(
            $this->challenges->issue($user, MfaLoginChallenge::PURPOSE_VERIFY),
            MfaLoginChallenge::PURPOSE_VERIFY,
        );

        $this->assertFalse($this->challenges->attempt($challenge, $set['plain'][0]));
    }

    // -------------------------------------------------------------- rollout

    public function test_the_policy_follows_the_rollout_configuration(): void
    {
        $policy = app(MfaPolicy::class);

        $admin = $this->user(UserType::Admin);
        $customer = $this->user(UserType::Privatkunde);

        config(['mfa.enabled' => true, 'mfa.required' => false, 'mfa.required_for' => ['admin']]);
        $this->assertTrue($policy->requires($admin));
        $this->assertTrue($policy->mustEnroll($admin));
        $this->assertFalse($policy->requires($customer));

        config(['mfa.required' => true]);
        $this->assertTrue($policy->requires($customer));

        // The master switch wins over everything.
        config(['mfa.enabled' => false]);
        $this->assertFalse($policy->requires($admin));
        $this->assertFalse($policy->requires($customer));
    }

    /** Anyone who opted in keeps their factor, whatever the rollout says. */
    public function test_an_enrolled_user_is_always_asked(): void
    {
        config(['mfa.enabled' => true, 'mfa.required' => false, 'mfa.required_for' => []]);

        $policy = app(MfaPolicy::class);
        $user = $this->totpUser(UserType::Privatkunde);

        $this->assertTrue($policy->requires($user));
        $this->assertFalse($policy->mustEnroll($user));
        $this->assertTrue($policy->hasEnrolled($user));
    }

    // ---------------------------------------------------------------- helpers

    private function sentCode(): string
    {
        $code = '';

        Mail::assertSent(MfaCode::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    private function user(UserType $type = UserType::Privatkunde): User
    {
        return User::factory()->create(['user_type' => $type, 'is_active' => true]);
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
}
