<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\User;
use App\Services\Mfa\MfaTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * With MFA required for everyone, a freshly registered customer is detoured
 * through MFA setup. Finishing setup must bring them back into their
 * registration funnel, not drop them on the dashboard.
 *
 * Note on helpers: TestCase::actingAs() enrolls the user in MFA automatically,
 * so the "has not enrolled yet" side is driven with actingAsWithoutMfa().
 * The user is signed in explicitly before each step because the test client
 * does not carry the registration's login across requests the way a
 * browser's session cookie does.
 */
class MfaOnboardingFunnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['mfa.enabled' => true, 'mfa.required' => true, 'mfa.exempt_emails' => []]);
    }

    public function test_a_new_privatkunde_returns_to_onboarding_after_mfa_setup(): void
    {
        $this->post('/register', [
            'user_type' => 'Privatkunde',
            'email' => 'b2c-funnel@example.com',
            'password' => 'Testpass1234',
        ])->assertRedirect(route('onboarding.show', absolute: false));

        $user = User::where('email', 'b2c-funnel@example.com')->firstOrFail();

        $this->assertReturnsToFunnelAfterMfa($user, route('onboarding.show'));
    }

    public function test_a_new_firmenkunde_returns_to_company_registration_after_mfa_setup(): void
    {
        $this->post('/register', [
            'user_type' => 'Firmenkunde',
            'email' => 'b2b-funnel@example.com',
            'password' => 'Testpass1234',
        ])->assertRedirect(route('onboarding.b2b.show', absolute: false));

        $user = User::where('email', 'b2b-funnel@example.com')->firstOrFail();

        $this->assertReturnsToFunnelAfterMfa($user, route('onboarding.b2b.show'));
    }

    public function test_continue_falls_back_to_the_home_page_when_nothing_was_interrupted(): void
    {
        $user = User::factory()->create(['user_type' => 'Privatkunde']);

        $this->enrollWithTotp($user);

        $this->actingAs($user->fresh())
            ->get(route('mfa.setup.continue'))
            ->assertRedirect(route($user->fresh()->homeRouteName()));
    }

    public function test_continue_is_not_a_way_out_of_mandatory_setup(): void
    {
        $user = User::factory()->create(['user_type' => 'Privatkunde']);

        $this->actingAsWithoutMfa($user)
            ->get(route('mfa.setup.continue'))
            ->assertRedirect(route('mfa.setup'));
    }

    public function test_the_setup_page_offers_the_continue_url(): void
    {
        $user = User::factory()->create(['user_type' => 'Privatkunde']);

        $this->actingAsWithoutMfa($user)
            ->get(route('mfa.setup'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/MfaSetup')
                ->where('continueUrl', route('mfa.setup.continue', absolute: false)));
    }

    /**
     * The whole detour: the funnel page is interrupted by MFA setup, the
     * interrupted page is remembered, setup is completed, and "Weiter"
     * leads back to the funnel page.
     */
    private function assertReturnsToFunnelAfterMfa(User $user, string $funnelUrl): void
    {
        // 1. The funnel is interrupted by MFA setup…
        $this->actingAsWithoutMfa($user)
            ->get($funnelUrl)
            ->assertRedirect(route('mfa.setup'));

        // 2. …and the interrupted page is remembered.
        $intended = (string) session('url.intended');
        $this->assertSame($funnelUrl, $intended);

        // 3. The user finishes setup.
        $this->enrollWithTotp($user);

        // 4. "Weiter" goes back into the funnel, not to the dashboard.
        $this->actingAs($user->fresh())
            ->withSession(['url.intended' => $intended])
            ->get(route('mfa.setup.continue'))
            ->assertRedirect($funnelUrl);

        // 5. And the funnel page actually opens now.
        $this->actingAs($user->fresh())
            ->get($funnelUrl)
            ->assertOk();
    }

    /** Completes browser enrollment with a real authenticator code. */
    private function enrollWithTotp(User $user): void
    {
        $totp = app(MfaTotpService::class);

        // Opening the setup page creates the candidate secret.
        $setup = $this->actingAsWithoutMfa($user)->get(route('mfa.setup'));
        $this->assertSame(200, $setup->status(), 'Setup page redirected to: '.$setup->headers->get('Location'));

        $secret = (string) $user->fresh()->mfa_secret;
        $code = $totp->codeAt($secret, intdiv(time(), 30));

        $this->actingAsWithoutMfa($user->fresh())
            ->post(route('mfa.setup.confirm'), [
                'method' => 'totp',
                'code' => $code,
            ])
            ->assertRedirect(route('mfa.setup'));

        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
    }
}