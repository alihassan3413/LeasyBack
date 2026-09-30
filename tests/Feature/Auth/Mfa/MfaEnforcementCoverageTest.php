<?php

namespace Tests\Feature\Auth\Mfa;

use App\Enums\UserType;
use App\Http\Middleware\EnsureMfaSatisfied;
use App\Models\User;
use App\Services\Mfa\MfaTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The tests that keep MFA enforcement honest.
 *
 * The rest of the suite signs in through `actingAs()`, which satisfies the
 * second factor so that a test about invoices is about invoices. That
 * convenience is only safe while something else proves the gate is still
 * there — which is what this file is.
 *
 * It asserts three separate things, because each fails in a different way:
 * that MFA is switched on in this environment at all, that every route where a
 * user authenticates is actually covered, and that the gate refuses a caller
 * who has not passed a challenge.
 */
class MfaEnforcementCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ------------------------------------------------------------ switched on

    /**
     * If someone turns MFA off in phpunit.xml to make a suite green, every
     * assertion below would still pass while enforcing nothing. This is the
     * one that notices.
     */
    public function test_mfa_is_enabled_in_the_test_environment(): void
    {
        $this->assertTrue(
            (bool) config('mfa.enabled'),
            'MFA is disabled for tests — enforcement is no longer being exercised anywhere.',
        );

        $this->assertNotEmpty(
            (array) config('mfa.required_for'),
            'No role requires MFA in tests, so the gate never engages.',
        );
    }

    // --------------------------------------------------------------- coverage

    /**
     * The browser side is covered by the group, so nothing can be forgotten.
     */
    public function test_the_web_group_carries_the_mfa_gate(): void
    {
        $this->assertContains(
            EnsureMfaSatisfied::class,
            app('router')->getMiddlewareGroups()['web'],
            'The web middleware group no longer enforces MFA.',
        );
    }

    /**
     * The API side is applied per group rather than to `api` as a whole,
     * because that group also carries the signature-authenticated webhooks,
     * which have no user by design. Per-group means it can be forgotten — so
     * every route where a user authenticates is checked here instead.
     */
    public function test_every_authenticated_api_route_carries_the_mfa_gate(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = app('router')->gatherRouteMiddleware($route);

            $authenticatesAUser = collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:sanctum'));

            if (! $authenticatesAUser) {
                continue;
            }

            // The enrollment endpoints are the one exception: a user who owes
            // a factor has to be able to reach them in order to set one up.
            if (str_starts_with((string) $route->getName(), 'api.mfa.')) {
                continue;
            }

            if (! in_array(EnsureMfaSatisfied::class, $middleware, true)) {
                $missing[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], $missing, "These authenticated routes do not enforce MFA:\n".implode("\n", $missing));
    }

    // -------------------------------------------------------------- it bites

    /**
     * A browser session that never passed a challenge is refused.
     *
     * This is the counterpart to the `actingAs()` helper: it proves the helper
     * is granting something real, not papering over a gate that does nothing.
     */
    public function test_a_session_that_never_passed_a_challenge_is_refused(): void
    {
        $user = $this->enrolledAdmin();

        $this->actingAsWithoutMfa($user)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** The same account is let through once the session carries the proof. */
    public function test_the_same_session_is_admitted_once_the_challenge_is_passed(): void
    {
        $user = $this->enrolledAdmin();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertSuccessful();
    }

    /** A token that predates the requirement is revoked on sight. */
    public function test_a_token_without_a_completed_challenge_is_refused(): void
    {
        $user = $this->enrolledAdmin();
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(403);

        $this->assertSame(0, $user->fresh()->tokens()->count(), 'the stale token was not revoked');
    }

    public function test_a_token_from_a_completed_challenge_is_admitted(): void
    {
        $user = $this->enrolledAdmin();

        $this->withHeaders($this->mfaVerifiedHeaders($user))
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    private function enrolledAdmin(): User
    {
        $user = User::factory()->create(['user_type' => UserType::Admin, 'is_active' => true]);

        $user->forceFill([
            'mfa_secret' => app(MfaTotpService::class)->generateSecret(),
            'mfa_method' => 'totp',
            'mfa_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }
}
