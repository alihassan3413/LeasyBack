<?php

namespace Tests\Feature\Auth\Mfa;

use App\Enums\UserType;
use App\Models\User;
use App\Services\Mfa\MfaPolicy;
use App\Services\Mfa\MfaTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The staging shape: a second factor demanded of everyone, with one named
 * exception so the operator cannot lock themselves out.
 *
 * There is no super-admin tier in this application — `UserType::Admin` is the
 * only admin there is — so the exception is a person, addressed by email,
 * rather than a role. Every test here sets that configuration explicitly, and
 * the last one pins that production's own configuration is untouched by it.
 */
class MfaExemptionTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private const EXEMPT = 'company.admin@leasyback.test';

    private MfaPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        // Staging: everyone must enrol, except the one named account.
        config([
            'mfa.enabled' => true,
            'mfa.required' => true,
            'mfa.exempt_emails' => [self::EXEMPT],
        ]);

        $this->policy = app(MfaPolicy::class);
    }

    // ------------------------------------------------------- the exempt user

    public function test_the_exempt_company_admin_does_not_require_mfa(): void
    {
        $user = $this->user(UserType::Admin, self::EXEMPT);

        $this->assertFalse($this->policy->requires($user));
        $this->assertFalse($this->policy->mustEnroll($user));
        $this->assertTrue($this->policy->isExempt($user));
    }

    /** Typed with different capitalisation, it is still the same person. */
    public function test_the_exemption_ignores_the_case_of_the_address(): void
    {
        $user = $this->user(UserType::Admin, 'Company.Admin@LeasyBack.test');

        $this->assertFalse($this->policy->requires($user));
    }

    /**
     * The exemption excuses the requirement, not a factor that exists. Someone
     * on the list who enrolled anyway is still asked — so adding a name here
     * can never quietly downgrade an account that already had one.
     */
    public function test_an_exempt_user_who_enrolled_anyway_is_still_asked(): void
    {
        $user = $this->enrolled($this->user(UserType::Admin, self::EXEMPT));

        $this->assertTrue($this->policy->requires($user));
        $this->assertFalse($this->policy->mustEnroll($user));
    }

    // --------------------------------------------------- everyone else must

    public function test_another_admin_requires_mfa(): void
    {
        $user = $this->user(UserType::Admin, 'second.admin@leasyback.test');

        $this->assertTrue($this->policy->requires($user));
        $this->assertTrue($this->policy->mustEnroll($user));
        $this->assertFalse($this->policy->isExempt($user));
    }

    public function test_a_company_owner_requires_mfa(): void
    {
        $company = $this->makeCompany('Fuhrpark GmbH');
        $owner = $this->makeOwner($company);

        $this->assertTrue($this->policy->requires($owner));
        $this->assertTrue($this->policy->mustEnroll($owner));
    }

    public function test_a_private_customer_requires_mfa(): void
    {
        $user = $this->user(UserType::Privatkunde, 'kunde@example.test');

        $this->assertTrue($this->policy->requires($user));
        $this->assertTrue($this->policy->mustEnroll($user));
    }

    public function test_a_workshop_user_requires_mfa(): void
    {
        $user = $this->user(UserType::Werkstatt, 'werkstatt@example.test');

        $this->assertTrue($this->policy->requires($user));
        $this->assertTrue($this->policy->mustEnroll($user));
    }

    public function test_a_company_member_requires_mfa(): void
    {
        $company = $this->makeCompany('Fuhrpark GmbH');
        $member = $this->makeMember($company);

        $this->assertTrue($this->policy->requires($member));
    }

    // ------------------------------------------------- through a real login

    /**
     * Proved through the login itself rather than only against the policy: the
     * exempt account signs in with a password and lands on its dashboard,
     * while another admin on the same configuration is stopped at enrollment.
     */
    public function test_the_exempt_admin_signs_in_with_a_password_alone(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = $this->user(UserType::Admin, self::EXEMPT);
        $user->forceFill(['password' => 'secret-password'])->save();

        $this->post('/login', ['email' => self::EXEMPT, 'password' => 'secret-password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);

        // And reaches the application, rather than being pinned to setup.
        $this->get(route('admin.dashboard'))->assertSuccessful();
    }

    public function test_another_admin_is_stopped_at_enrollment(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = $this->user(UserType::Admin, 'second.admin@leasyback.test');
        $user->forceFill(['password' => 'secret-password'])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);

        $this->get(route('admin.dashboard'))->assertRedirect(route('mfa.setup'));
    }

    // ---------------------------------------------------- production is safe

    /**
     * The exemption is configuration, not behaviour baked into the policy.
     * With the production defaults — no list, nobody globally required — a
     * private customer is still left alone and an admin is still asked, so
     * nothing here changes what production does.
     */
    public function test_the_production_configuration_is_unchanged(): void
    {
        config([
            'mfa.enabled' => true,
            'mfa.required' => false,
            'mfa.required_for' => ['admin', 'b2b_owner'],
            'mfa.exempt_emails' => [],
        ]);

        $policy = app(MfaPolicy::class);

        // The same address that staging exempts is an ordinary admin here.
        $this->assertTrue($policy->requires($this->user(UserType::Admin, self::EXEMPT)));
        $this->assertTrue($policy->requires($this->user(UserType::Admin, 'ops@leasyback.test')));
        $this->assertFalse($policy->requires($this->user(UserType::Privatkunde, 'kunde2@example.test')));
        $this->assertFalse($policy->requires($this->user(UserType::Werkstatt, 'werkstatt2@example.test')));
    }

    /** Nothing is exempt anywhere until an environment names someone. */
    public function test_nobody_is_exempt_by_default(): void
    {
        // What config/mfa.php ships with, independent of this test's setUp().
        $this->assertSame([], array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MFA_EXEMPT_EMAILS', '')),
        ))));

        config(['mfa.exempt_emails' => []]);

        $this->assertFalse(app(MfaPolicy::class)->isExempt($this->user(UserType::Admin, self::EXEMPT)));
    }

    // ---------------------------------------------------------------- helpers

    private function user(UserType $type, string $email): User
    {
        return User::factory()->create([
            'user_type' => $type,
            'email' => $email,
            'is_active' => true,
        ]);
    }

    private function enrolled(User $user): User
    {
        $user->forceFill([
            'mfa_secret' => app(MfaTotpService::class)->generateSecret(),
            'mfa_method' => 'totp',
            'mfa_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }
}
