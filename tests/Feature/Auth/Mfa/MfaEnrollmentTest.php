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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Turning a factor on, and an admin turning someone else's off.
 */
class MfaEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private MfaTotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        $this->totp = app(MfaTotpService::class);

        config(['mfa.enabled' => true, 'mfa.required' => false, 'mfa.required_for' => []]);
    }

    // ----------------------------------------------------------------- setup

    public function test_setup_returns_a_secret_and_a_provisioning_uri(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->postJson('/api/mfa/setup');

        $response->assertOk()->assertJsonPath('ok', true);

        $secret = $response->json('data.secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringContainsString('otpauth://totp/', $response->json('data.otpauth_uri'));
        $this->assertStringContainsString('secret='.$secret, $response->json('data.otpauth_uri'));
    }

    public function test_setup_returns_a_scannable_svg_qr_code(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->postJson('/api/mfa/setup');

        $qr = $response->json('data.qr_code');

        $this->assertNotEmpty($qr);
        $this->assertStringStartsWith('<svg', $qr);
        $this->assertStringEndsWith('</svg>', $qr);
        $this->assertStringContainsString('viewBox', $qr);

        // An XML declaration inside an HTML document renders as visible text.
        $this->assertStringNotContainsString('<?xml', $qr);
    }

    /**
     * The QR encodes the provisioning URI, so the secret is carried inside the
     * image rather than needing a client-side library to be handed it.
     */
    public function test_the_qr_code_is_generated_server_side(): void
    {
        $user = $this->user();

        $data = $this->actingAs($user)->postJson('/api/mfa/setup')->json('data');

        $this->assertSame(
            app(MfaTotpService::class)->qrSvg($data['otpauth_uri']),
            $data['qr_code'],
        );
    }

    /** The raw secret stays available, but only as the manual fallback. */
    public function test_the_manual_secret_is_still_offered_alongside_the_qr(): void
    {
        $user = $this->user();

        $data = $this->actingAs($user)->postJson('/api/mfa/setup')->json('data');

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $data['secret']);
        $this->assertStringContainsString('secret='.$data['secret'], $data['otpauth_uri']);
    }

    public function test_the_setup_page_carries_the_qr_code(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('mfa.setup'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/MfaSetup')
                ->where('enrolled', false)
                ->where('qrCode', fn ($svg) => str_starts_with((string) $svg, '<svg'))
                ->has('otpauthUri')
                ->has('secret'));
    }

    /** Nothing to scan once enrolled: no secret and no QR on the page. */
    public function test_an_enrolled_user_is_offered_no_qr_code(): void
    {
        $user = $this->enrolledUser();

        $this->actingAs($user)
            // actingAs() skips the challenge, so the session carries no proof
            // of one. Marking it is what a completed login does, and without
            // it EnsureMfaSatisfied correctly bounces an enrolled user.
            ->withSession(['mfa.verified_user_id' => $user->id])
            ->get(route('mfa.setup'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/MfaSetup')
                ->where('enrolled', true)
                ->where('qrCode', null)
                ->where('secret', null));
    }

    /** A secret exists but nothing is enrolled until a code proves it. */
    public function test_setup_alone_does_not_enable_mfa(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/mfa/setup')->assertOk();

        $user->refresh();
        $this->assertNotNull($user->mfa_secret);
        $this->assertNull($user->mfa_confirmed_at);
        $this->assertNull($user->mfa_method);
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $user = $this->user();

        $secret = $this->actingAs($user)->postJson('/api/mfa/setup')->json('data.secret');

        $stored = (string) DB::table('users')->where('id', $user->id)->value('mfa_secret');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame($secret, $user->fresh()->mfa_secret);
    }

    public function test_setup_requires_authentication(): void
    {
        $this->postJson('/api/mfa/setup')->assertStatus(401);
    }

    // --------------------------------------------------------------- confirm

    public function test_a_correct_totp_code_completes_enrollment_and_returns_recovery_codes(): void
    {
        $user = $this->user();
        $secret = $this->actingAs($user)->postJson('/api/mfa/setup')->json('data.secret');

        $response = $this->actingAs($user->fresh())->postJson('/api/mfa/confirm', [
            'method' => 'totp',
            'code' => $this->totp->codeAt($secret, $this->totp->stepAt()),
        ]);

        $response->assertOk()->assertJsonPath('data.method', 'totp');

        $codes = $response->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codes[0]);

        $user->refresh();
        $this->assertNotNull($user->mfa_confirmed_at);
        $this->assertSame('totp', $user->mfa_method);
    }

    public function test_a_wrong_code_does_not_complete_enrollment(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/api/mfa/setup');

        $this->actingAs($user->fresh())
            ->postJson('/api/mfa/confirm', ['method' => 'totp', 'code' => '000000'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->mfa_confirmed_at);
    }

    /** Recovery codes are shown once; they must not be readable afterwards. */
    public function test_recovery_codes_are_never_returned_again(): void
    {
        $user = $this->user();
        $secret = $this->actingAs($user)->postJson('/api/mfa/setup')->json('data.secret');

        $codes = $this->actingAs($user->fresh())->postJson('/api/mfa/confirm', [
            'method' => 'totp',
            'code' => $this->totp->codeAt($secret, $this->totp->stepAt()),
        ])->json('data.recovery_codes');

        $stored = (string) DB::table('users')->where('id', $user->id)->value('mfa_recovery_codes');

        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $stored);
        }

        // Nor through any user serialization.
        $this->assertArrayNotHasKey('mfa_recovery_codes', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('mfa_secret', $user->fresh()->toArray());
    }

    public function test_email_enrollment_works_end_to_end(): void
    {
        $user = $this->user();

        $ticket = $this->actingAs($user)->postJson('/api/mfa/enroll/send-email')->json('data.ticket');

        $code = '';
        Mail::assertSent(MfaCode::class, function (MfaCode $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->actingAs($user->fresh())->postJson('/api/mfa/confirm', [
            'method' => 'email',
            'ticket' => $ticket,
            'code' => $code,
        ])->assertOk();

        $user->refresh();
        $this->assertSame('email', $user->mfa_method);
        $this->assertNotNull($user->mfa_confirmed_at);
        $this->assertNotNull($user->mfa_email_confirmed_at);
    }

    /** An enroll ticket must not satisfy a login challenge or vice versa. */
    public function test_an_enrollment_ticket_cannot_be_used_to_sign_in(): void
    {
        $user = $this->user();
        $ticket = $this->actingAs($user)->postJson('/api/mfa/enroll/send-email')->json('data.ticket');

        $code = '';
        Mail::assertSent(MfaCode::class, function (MfaCode $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->postJson('/api/mfa/verify', ['ticket' => $ticket, 'code' => $code])->assertStatus(401);
    }

    // ---------------------------------------------------------- admin reset

    public function test_an_admin_can_reset_another_users_mfa(): void
    {
        $admin = $this->user(UserType::Admin);
        $victim = $this->enrolledUser();

        $this->actingAs($admin)->postJson("/api/mfa/reset/{$victim->id}")->assertOk();

        $victim->refresh();
        $this->assertNull($victim->mfa_secret);
        $this->assertNull($victim->mfa_confirmed_at);
        $this->assertNull($victim->mfa_method);
        $this->assertNull($victim->mfa_recovery_codes);
        $this->assertNull($victim->mfa_last_used_step);
    }

    /** An old printout must not survive a reset. */
    public function test_a_reset_invalidates_existing_recovery_codes(): void
    {
        $admin = $this->user(UserType::Admin);
        $victim = $this->enrolledUser();

        $codes = app(MfaRecoveryCodeService::class)->generate();
        $victim->forceFill(['mfa_recovery_codes' => $codes['hashed']])->save();

        $this->actingAs($admin)->postJson("/api/mfa/reset/{$victim->id}")->assertOk();

        $this->assertSame(0, app(MfaRecoveryCodeService::class)->remaining($victim->fresh()));
    }

    public function test_a_reset_destroys_any_live_challenge(): void
    {
        $admin = $this->user(UserType::Admin);
        $victim = $this->enrolledUser();

        app(MfaChallengeService::class)->issue($victim, MfaLoginChallenge::PURPOSE_VERIFY);
        $this->assertSame(1, MfaLoginChallenge::count());

        $this->actingAs($admin)->postJson("/api/mfa/reset/{$victim->id}")->assertOk();

        $this->assertSame(0, MfaLoginChallenge::count());
    }

    public function test_a_non_admin_cannot_reset_anyone(): void
    {
        $attacker = $this->user(UserType::Privatkunde);
        $victim = $this->enrolledUser();

        $this->actingAs($attacker)->postJson("/api/mfa/reset/{$victim->id}")->assertForbidden();

        $this->assertNotNull($victim->fresh()->mfa_confirmed_at);
    }

    public function test_a_guest_cannot_reset_anyone(): void
    {
        $victim = $this->enrolledUser();

        $this->postJson("/api/mfa/reset/{$victim->id}")->assertStatus(401);

        $this->assertNotNull($victim->fresh()->mfa_confirmed_at);
    }

    // ---------------------------------------------------------------- helpers

    private function user(UserType $type = UserType::Privatkunde): User
    {
        return User::factory()->create(['user_type' => $type, 'is_active' => true]);
    }

    private function enrolledUser(): User
    {
        $user = $this->user();

        $user->forceFill([
            'mfa_secret' => $this->totp->generateSecret(),
            'mfa_method' => 'totp',
            'mfa_confirmed_at' => now(),
            'mfa_last_used_step' => 12345,
        ])->save();

        return $user->fresh();
    }
}
