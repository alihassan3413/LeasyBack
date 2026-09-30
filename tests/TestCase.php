<?php

namespace Tests;

use App\Models\User;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Services\Mfa\MfaPolicy;
use App\Services\Mfa\MfaTotpService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStripeGateway;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Bound for every test, not only the payment suite: the repair charge
         * fires from a status transition, so any test that drives an order to
         * `delivered` would otherwise construct the real client and attempt a
         * network call against whatever key happens to be in .env.
         *
         * Tests that need to assert on the requests resolve this same instance
         * out of the container.
         */
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);

        /*
         * No test may reach a real third-party API (TÜV SÜD, DEKRA, Lexware,
         * TIM, partner webhooks). A request the test did not explicitly fake
         * with Http::fake() throws instead of leaving the machine — one test
         * booking with TÜV SÜD this way used the real endpoint and the token
         * from .env. Tests that exercise an integration fake its endpoint.
         */
        Http::preventStrayRequests();
    }

    /**
     * Sign a user in the way a finished login leaves them.
     *
     * MFA is deliberately left enabled in the test environment, so
     * EnsureMfaSatisfied runs on every authenticated request a test makes.
     * `actingAs()` jumps straight past the login screen, which means it also
     * jumps past the challenge — so the marker a passed challenge leaves in
     * the session is set here too. Without it, every suite that signs in as an
     * admin would be answering the question "is MFA enforced?" instead of
     * whatever it was written to test.
     *
     * This does not hide a broken gate. The middleware still executes on every
     * request, and the suites in tests/Feature/Auth/Mfa drive the real login
     * to prove the gate bites — see `actingAsWithoutMfa()` for the other side.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->satisfyMfa($user);

        parent::actingAs($user, $guard);

        $this->withSession(['mfa.verified_user_id' => $user->getAuthIdentifier()]);

        return $this;
    }

    /**
     * Put the account in the state a finished login leaves it in.
     *
     * Only touches accounts the rollout would send to enrollment — an admin or
     * a company owner. Everyone else is left exactly as the test built them,
     * so this cannot quietly change the subject of a test that had nothing to
     * do with MFA.
     */
    private function satisfyMfa(Authenticatable $user): void
    {
        if (! $user instanceof User || ! app(MfaPolicy::class)->mustEnroll($user)) {
            return;
        }

        $user->forceFill([
            'mfa_secret' => app(MfaTotpService::class)->generateSecret(),
            'mfa_method' => 'totp',
            'mfa_confirmed_at' => now(),
        ])->save();
    }

    /**
     * A bearer token for a caller that has completed a second factor.
     *
     * `mfa-verified` is not decoration: EnsureMfaSatisfied reads the token
     * name to tell a token issued after a challenge from one minted before the
     * requirement existed, and revokes the latter. A test that calls
     * `createToken()` directly is describing the second kind, which is why
     * these are explicit rather than hidden inside the middleware.
     */
    protected function mfaVerifiedToken(User $user): string
    {
        return $user->createToken('mfa-verified')->plainTextToken;
    }

    /**
     * The `Authorization` header for such a caller.
     *
     * @return array<string, string>
     */
    protected function mfaVerifiedHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->mfaVerifiedToken($user)];
    }

    /**
     * Authenticate an API call as a caller that has completed a second factor.
     *
     * The replacement for `actingAs($user, 'sanctum')` on routes that carry
     * the `mfa` middleware. Sanctum's acting-as hands back a TransientToken,
     * which stands for a session-authenticated caller — and an API request has
     * no session for proof of a challenge to live in, so the gate rightly
     * cannot clear it. A real bearer token can carry that proof in its name.
     */
    protected function actingAsApi(User $user): static
    {
        // Sanctum's guard is configured as ['web'], so a session left open by
        // an earlier actingAs() would answer before the bearer token is even
        // read — and the request would run as whoever that was. Cleared first
        // so the token is the only thing identifying the caller.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->withHeaders($this->mfaVerifiedHeaders($user));
    }

    /**
     * Sign a user in having presented only a password.
     *
     * The counterpart to actingAs(): use this to assert that a route is closed
     * to someone who never completed a challenge.
     */
    public function actingAsWithoutMfa(Authenticatable $user, ?string $guard = null): static
    {
        parent::actingAs($user, $guard);

        $this->withSession(['mfa.verified_user_id' => null]);

        return $this;
    }
}
