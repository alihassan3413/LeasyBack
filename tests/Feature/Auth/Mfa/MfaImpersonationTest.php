<?php

namespace Tests\Feature\Auth\Mfa;

use App\Enums\UserType;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * An admin impersonating a customer is not asked for the customer's second
 * factor — the admin is the one signed in, and the admin's own factor is what
 * the session carries. Everyone else's MFA is untouched.
 */
class MfaImpersonationTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['mfa.enabled' => true, 'mfa.required' => false, 'mfa.required_for' => ['admin', 'b2b_owner'], 'mfa.exempt_emails' => []]);
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin, 'is_active' => true, 'email_verified_at' => now()]);
    }

    /** A company owner: in scope for MFA, not enrolled yet, so pinned to setup. */
    private function ownerWhoMustEnroll(): User
    {
        return $this->makeOwner($this->makeCompany());
    }

    /** A company owner who enrolled, so a session without a passed challenge is signed out. */
    private function ownerWhoMustVerify(): User
    {
        $owner = $this->makeOwner($this->makeCompany());
        $owner->forceFill(['mfa_method' => 'email', 'mfa_confirmed_at' => now()])->save();

        return $owner;
    }

    /**
     * The admin signs in with their own factor passed (TestCase::actingAs marks
     * the session as a finished login does), then takes over $target. Every
     * customer below is signed in with actingAsWithoutMfa() otherwise, so the
     * gate is genuinely engaged.
     */
    private function impersonate(User $admin, User $target): void
    {
        $this->actingAs($admin)
            ->withSession(['mfa.verified_user_id' => $admin->id])
            ->post(route('admin.impersonate.store', $target->id))
            ->assertRedirect();

        $this->assertAuthenticatedAs($target);
        $this->assertSame($target->id, session(ImpersonationController::TARGET_SESSION_KEY));
    }

    // ------------------------------------------------- normal sign-in is unchanged

    public function test_a_user_who_must_enroll_is_still_pinned_to_mfa_setup(): void
    {
        $this->actingAsWithoutMfa($this->ownerWhoMustEnroll())
            ->get(route('dashboard'))
            ->assertRedirect(route('mfa.setup'));
    }

    public function test_an_enrolled_user_without_a_passed_challenge_is_still_signed_out(): void
    {
        $this->actingAsWithoutMfa($this->ownerWhoMustVerify())
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // ---------------------------------------------------------- while impersonated

    public function test_an_impersonated_user_who_must_enroll_is_not_sent_to_setup(): void
    {
        $owner = $this->ownerWhoMustEnroll();
        $this->impersonate($this->admin(), $owner);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('vehicles.index'))->assertOk();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_an_impersonated_enrolled_user_is_not_challenged(): void
    {
        $owner = $this->ownerWhoMustVerify();
        $this->impersonate($this->admin(), $owner);

        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($owner);
    }

    /** The way back must never be blocked by the customer's MFA. */
    public function test_the_admin_can_always_return_from_an_impersonation(): void
    {
        $admin = $this->admin();
        $this->impersonate($admin, $this->ownerWhoMustEnroll());

        $this->delete(route('impersonate.destroy'))->assertRedirect(route('admin.customers.index'));

        $this->assertAuthenticatedAs($admin);
    }

    // ------------------------------------------- session data cannot fake it

    /** A session merely naming a non-admin as the impersonator is no impersonation. */
    public function test_a_non_admin_impersonator_id_does_not_bypass_mfa(): void
    {
        $owner = $this->ownerWhoMustEnroll();
        $accomplice = User::factory()->create(['user_type' => UserType::Firmenkunde, 'is_active' => true]);

        $this->actingAsWithoutMfa($owner)
            ->withSession([
                ImpersonationController::SESSION_KEY => $accomplice->id,
                ImpersonationController::TARGET_SESSION_KEY => $owner->id,
                'mfa.verified_user_id' => $accomplice->id,
            ])
            ->get(route('dashboard'))
            ->assertRedirect(route('mfa.setup'));
    }

    public function test_an_impersonator_key_without_the_matching_target_does_not_bypass_mfa(): void
    {
        $owner = $this->ownerWhoMustEnroll();
        $admin = $this->admin();

        // Only the admin key (as a session predating TARGET_SESSION_KEY would hold).
        $this->actingAsWithoutMfa($owner)
            ->withSession([ImpersonationController::SESSION_KEY => $admin->id, 'mfa.verified_user_id' => $admin->id])
            ->get(route('dashboard'))
            ->assertRedirect(route('mfa.setup'));

        // A target key naming somebody else.
        $this->actingAsWithoutMfa($owner)
            ->withSession([
                ImpersonationController::SESSION_KEY => $admin->id,
                ImpersonationController::TARGET_SESSION_KEY => $admin->id,
                'mfa.verified_user_id' => $admin->id,
            ])
            ->get(route('dashboard'))
            ->assertRedirect(route('mfa.setup'));
    }

    /** The admin's own factor is the proof; an admin id alone, without it, proves nothing. */
    public function test_an_admin_who_did_not_pass_mfa_in_this_session_grants_no_bypass(): void
    {
        $owner = $this->ownerWhoMustEnroll();
        $admin = $this->admin();

        $this->actingAsWithoutMfa($owner)
            ->withSession([ImpersonationController::SESSION_KEY => $admin->id, ImpersonationController::TARGET_SESSION_KEY => $owner->id])
            ->get(route('dashboard'))
            ->assertRedirect(route('mfa.setup'));
    }

    public function test_a_deactivated_or_demoted_admin_grants_no_bypass(): void
    {
        $owner = $this->ownerWhoMustEnroll();
        $admin = $this->admin();
        $this->impersonate($admin, $owner);

        $admin->forceFill(['is_active' => false])->save();
        $this->get(route('dashboard'))->assertRedirect(route('mfa.setup'));

        $admin->forceFill(['is_active' => true, 'user_type' => UserType::Firmenkunde])->save();
        $this->get(route('dashboard'))->assertRedirect(route('mfa.setup'));
    }

    // ---------------------------------------------------- after it ends

    public function test_mfa_applies_again_as_soon_as_the_impersonation_ends(): void
    {
        $owner = $this->ownerWhoMustEnroll();
        $this->impersonate($this->admin(), $owner);
        $this->get(route('dashboard'))->assertOk();

        $this->delete(route('impersonate.destroy'));

        $this->assertFalse(session()->has(ImpersonationController::SESSION_KEY));
        $this->assertFalse(session()->has(ImpersonationController::TARGET_SESSION_KEY));

        // The same customer in this session again, now as a normal sign-in.
        $this->actingAsWithoutMfa($owner)->get(route('dashboard'))->assertRedirect(route('mfa.setup'));
    }
}
