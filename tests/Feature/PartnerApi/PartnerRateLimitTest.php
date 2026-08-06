<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Services\PartnerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

/**
 * Per-token rate limiting.
 *
 * The limit is deliberately per *token* rather than per client or per IP: two
 * partners behind the same egress IP must not throttle each other, and a
 * partner mid-rotation must not find their new credential already spent.
 */
class PartnerRateLimitTest extends TestCase
{
    use BuildsPartnerClients, RefreshDatabase;

    public function test_requests_beyond_the_limit_are_refused_with_a_retry_after(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $client->forceFill(['rate_limit_per_minute' => 3])->save();

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertOk();
        }

        $response = $this->getJson('/api/v1/partner/health', $this->bearer($token));

        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limit_exceeded')
            ->assertJsonPath('error.type', 'rate_limit_error')
            ->assertHeader('X-RateLimit-Limit', '3')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_successful_responses_advertise_the_remaining_budget(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $client->forceFill(['rate_limit_per_minute' => 5])->save();

        $this->getJson('/api/v1/partner/health', $this->bearer($token))
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '4');

        $this->getJson('/api/v1/partner/health', $this->bearer($token))
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '3');
    }

    public function test_a_client_without_an_override_uses_the_configured_default(): void
    {
        config(['partner_api.rate_limit.per_minute' => 2]);

        [, $token] = $this->makeAuthenticatedPartner();

        $this->getJson('/api/v1/partner/health', $this->bearer($token))
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '2');
        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertStatus(429);
    }

    public function test_one_partner_exhausting_its_budget_does_not_throttle_another(): void
    {
        config(['partner_api.rate_limit.per_minute' => 1]);

        [, $alphaToken] = $this->makeAuthenticatedPartner(slug: 'alpha-partner');
        [, $betaToken] = $this->makeAuthenticatedPartner(slug: 'beta-partner');

        $this->getJson('/api/v1/partner/health', $this->bearer($alphaToken))->assertOk();
        $this->getJson('/api/v1/partner/health', $this->bearer($alphaToken))->assertStatus(429);

        $this->getJson('/api/v1/partner/health', $this->bearer($betaToken))->assertOk();
    }

    public function test_a_rotated_credential_starts_with_a_fresh_budget(): void
    {
        config(['partner_api.rate_limit.per_minute' => 1]);

        [$client, $token] = $this->makeAuthenticatedPartner();

        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertStatus(429);

        $rotated = app(PartnerTokenService::class)
            ->rotate($client)
            ->plainTextToken;

        $this->getJson('/api/v1/partner/health', $this->bearer($rotated))->assertOk();
    }

    public function test_credential_guessing_is_bounded_by_failed_attempts_per_ip(): void
    {
        config(['partner_api.rate_limit.auth_failures_per_minute' => 2]);

        $this->getJson('/api/v1/partner/health', $this->bearer('lbp_sbx_wrong-one'))->assertStatus(401);
        $this->getJson('/api/v1/partner/health', $this->bearer('lbp_sbx_wrong-two'))->assertStatus(401);

        $response = $this->getJson('/api/v1/partner/health', $this->bearer('lbp_sbx_wrong-three'));

        $response->assertStatus(429)->assertJsonPath('error.code', 'rate_limit_exceeded');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_successful_calls_do_not_consume_the_failure_budget(): void
    {
        config(['partner_api.rate_limit.auth_failures_per_minute' => 2]);

        [, $token] = $this->makeAuthenticatedPartner();

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertOk();
        }

        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertOk();
    }

    public function test_a_deactivated_client_polling_is_not_locked_out_by_the_failure_budget(): void
    {
        config(['partner_api.rate_limit.auth_failures_per_minute' => 2]);

        [$client, $token] = $this->makeAuthenticatedPartner();
        $client->forceFill(['is_active' => false])->save();

        // A 403 is a genuine credential in a suspended state, not an attack:
        // it must keep returning the reason rather than degrading to a 429.
        for ($i = 0; $i < 4; $i++) {
            $this->getJson('/api/v1/partner/me', $this->bearer($token))
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'client_inactive');
        }
    }
}
