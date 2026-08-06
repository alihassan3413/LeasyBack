<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Enums\PartnerEnvironment;
use App\Modules\PartnerApi\Models\PartnerApiToken;
use App\Modules\PartnerApi\Services\PartnerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

/**
 * The Partner API's authentication boundary: which credentials get in, which
 * are refused, and with which machine-readable reason.
 *
 * Every refusal asserts the specific `error.code`, not just the status. A
 * partner integrating against this API branches on that code, so a change
 * from `token_expired` to a generic 401 is a breaking change and should fail
 * a test rather than a support ticket.
 */
class PartnerAuthenticationTest extends TestCase
{
    use BuildsPartnerClients, RefreshDatabase;

    public function test_a_valid_token_reaches_health_and_reports_its_environment(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        $response = $this->getJson('/api/v1/partner/health', $this->bearer($token));

        $response->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.environment', PartnerEnvironment::Sandbox->value)
            ->assertJsonPath('data.version', 'v1');

        $this->assertNotNull($response->json('request_id'));
        $this->assertSame($client->id, $client->fresh()->id);
    }

    public function test_me_returns_the_calling_identity_and_never_the_token_hash(): void
    {
        $company = $this->makePartnerCompany('Isolierte GmbH');
        [$client, $token] = $this->makeAuthenticatedPartner($company, ['vehicles.read', 'orders.read']);

        $response = $this->getJson('/api/v1/partner/me', $this->bearer($token));

        $response->assertOk()
            ->assertJsonPath('data.client.slug', $client->slug)
            ->assertJsonPath('data.client.environment', 'sandbox')
            ->assertJsonPath('data.company.id', $company->b2b_id)
            ->assertJsonPath('data.company.name', 'Isolierte GmbH')
            ->assertJsonPath('data.token.abilities', ['vehicles.read', 'orders.read']);

        $body = $response->getContent();
        $this->assertStringNotContainsString('token_hash', $body);
        $this->assertStringNotContainsString(hash('sha256', $token), $body);
    }

    public function test_a_missing_token_is_refused_with_missing_token(): void
    {
        $this->getJson('/api/v1/partner/me', ['Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'missing_token')
            ->assertJsonPath('error.type', 'authentication_error')
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_an_unknown_token_is_refused_with_invalid_token(): void
    {
        $this->makeAuthenticatedPartner();

        $this->getJson('/api/v1/partner/me', $this->bearer('lbp_sbx_'.str_repeat('a', 64)))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_token');
    }

    public function test_a_revoked_token_is_refused_with_token_revoked(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        app(PartnerTokenService::class)->revokeAll($client);

        $this->getJson('/api/v1/partner/me', $this->bearer($token))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'token_revoked');
    }

    public function test_an_expired_token_is_refused_with_token_expired(): void
    {
        $client = $this->makePartnerClient();
        $token = $this->issueToken($client, expiresAt: now()->subMinute())->plainTextToken;

        $this->getJson('/api/v1/partner/me', $this->bearer($token))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'token_expired');
    }

    public function test_a_token_with_a_future_expiry_still_works(): void
    {
        $client = $this->makePartnerClient();
        $token = $this->issueToken($client, expiresAt: now()->addDay())->plainTextToken;

        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertOk();
    }

    public function test_a_deactivated_client_is_refused_with_client_inactive(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        $client->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/partner/me', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'client_inactive');
    }

    public function test_a_deactivated_integration_user_is_refused(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        $client->user->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/partner/me', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'integration_user_inactive');
    }

    public function test_a_deactivated_company_is_refused(): void
    {
        $company = $this->makePartnerCompany();
        [, $token] = $this->makeAuthenticatedPartner($company);

        $company->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/partner/me', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'company_inactive');
    }

    public function test_a_successful_call_records_last_used_at(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        $this->assertNull($client->tokens()->first()->last_used_at);

        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertOk();

        $stored = $client->tokens()->first();
        $this->assertNotNull($stored->last_used_at);
        $this->assertNotNull($stored->last_used_ip);
    }

    public function test_the_plaintext_token_is_never_persisted(): void
    {
        $client = $this->makePartnerClient();
        $issued = $this->issueToken($client);

        $this->assertDatabaseMissing('partner_api_tokens', ['token_hash' => $issued->plainTextToken]);
        $this->assertDatabaseHas('partner_api_tokens', [
            'id' => $issued->token->id,
            'token_hash' => hash('sha256', $issued->plainTextToken),
        ]);
        $this->assertStringStartsWith('lbp_sbx_', $issued->plainTextToken);
    }

    public function test_a_production_token_carries_the_production_segment_and_environment(): void
    {
        $client = $this->makePartnerClient(environment: PartnerEnvironment::Production, slug: 'prod-partner');
        $issued = $this->issueToken($client);

        $this->assertStringStartsWith('lbp_live_', $issued->plainTextToken);

        $this->getJson('/api/v1/partner/health', $this->bearer($issued->plainTextToken))
            ->assertOk()
            ->assertJsonPath('data.environment', 'production');
    }

    public function test_a_token_whose_client_was_deleted_is_simply_invalid(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        PartnerApiToken::where('partner_integration_client_id', $client->id)->get();
        $client->delete();

        $this->getJson('/api/v1/partner/me', $this->bearer($token))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_token');
    }

    public function test_an_unknown_partner_endpoint_returns_the_partner_error_envelope(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        // Deliberately a path no phase will ever claim. This used to point at
        // /vehicles, which phase 2 implemented — an unimplemented endpoint is
        // not a stable stand-in for an unknown one.
        $this->getJson('/api/v1/partner/no-such-endpoint', $this->bearer($token))
            ->assertStatus(404)
            ->assertJsonPath('error.type', 'not_found')
            ->assertJsonPath('error.code', 'resource_not_found');
    }
}
