<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Enums\PartnerEnvironment;
use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

/**
 * A token reaches exactly one company, and the company is never something the
 * caller can state.
 *
 * This is the property every later phase depends on: phase 2's vehicle
 * listing is only safe if the company it scopes to cannot be influenced by
 * request input. The tests here assert that at the context layer rather than
 * on an endpoint that does not exist yet — the endpoints will read
 * PartnerContext, so pinning its behaviour pins theirs.
 */
class PartnerCompanyIsolationTest extends TestCase
{
    use BuildsPartnerClients, RefreshDatabase;

    public function test_two_partners_each_see_only_their_own_company(): void
    {
        $alpha = $this->makePartnerCompany('Alpha GmbH');
        $beta = $this->makePartnerCompany('Beta GmbH');

        [, $alphaToken] = $this->makeAuthenticatedPartner($alpha, slug: 'alpha-partner');
        [, $betaToken] = $this->makeAuthenticatedPartner($beta, slug: 'beta-partner');

        $this->getJson('/api/v1/partner/me', $this->bearer($alphaToken))
            ->assertOk()
            ->assertJsonPath('data.company.id', $alpha->b2b_id)
            ->assertJsonPath('data.company.name', 'Alpha GmbH');

        $betaResponse = $this->getJson('/api/v1/partner/me', $this->bearer($betaToken))->assertOk();

        $betaResponse->assertJsonPath('data.company.id', $beta->b2b_id);
        $this->assertStringNotContainsString($alpha->b2b_id, $betaResponse->getContent());
        $this->assertStringNotContainsString('Alpha GmbH', $betaResponse->getContent());
    }

    public function test_the_resolved_company_ignores_a_company_id_the_caller_supplies(): void
    {
        $own = $this->makePartnerCompany('Eigene GmbH');
        $foreign = $this->makePartnerCompany('Fremde GmbH');

        [, $token] = $this->makeAuthenticatedPartner($own);

        // The ownership guard refuses the well-known keys outright; this route
        // is registered without it so the *context* itself is what gets tested
        // — belt and braces, in case a later phase drops the guard.
        Route::middleware(['api', 'partner.request-id', 'partner.auth', 'partner.throttle'])
            ->get('api/v1/partner/context-probe', function (PartnerContext $context) {
                return response()->json(['company_id' => $context->companyId()]);
            });

        $this->getJson(
            '/api/v1/partner/context-probe?b2b_id='.$foreign->b2b_id.'&company_id='.$foreign->b2b_id,
            $this->bearer($token),
        )
            ->assertOk()
            ->assertJsonPath('company_id', $own->b2b_id);
    }

    public function test_the_shared_b2b_context_resolves_the_tokens_company_even_when_active_b2b_id_is_stale(): void
    {
        $own = $this->makePartnerCompany('Eigene GmbH');
        $other = $this->makePartnerCompany('Andere GmbH');

        [$client, $token] = $this->makeAuthenticatedPartner($own);

        // Simulate the integration account having been added to a second
        // company and left pointing at it — the exact state that would leak
        // if the API trusted `users.active_b2b_id`.
        DB::table('user_b2b')->insert([
            'user_id' => $client->user_id,
            'b2b_id' => $other->b2b_id,
            'role' => 'member',
            'permissions' => json_encode(['vehicles.view']),
            'vehicle_scope' => 'all',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $client->user_id)->update(['active_b2b_id' => $other->b2b_id]);

        Route::middleware(['api', 'partner.request-id', 'partner.auth', 'partner.throttle'])
            ->get('api/v1/partner/b2b-probe', function (PartnerContext $context, B2bContext $b2b) {
                return response()->json([
                    'partner_company' => $context->companyId(),
                    'b2b_company' => $b2b->activeCompanyId($context->user()),
                ]);
            });

        $this->getJson('/api/v1/partner/b2b-probe', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('partner_company', $own->b2b_id)
            ->assertJsonPath('b2b_company', $own->b2b_id);
    }

    public function test_a_sandbox_and_a_production_client_for_the_same_partner_are_separate_identities(): void
    {
        $sandboxCompany = $this->makePartnerCompany('Sandkasten GmbH');
        $productionCompany = $this->makePartnerCompany('Produktion GmbH');

        $sandbox = $this->makePartnerClient($sandboxCompany, PartnerEnvironment::Sandbox, 'same-partner');
        $production = $this->makePartnerClient($productionCompany, PartnerEnvironment::Production, 'same-partner');

        $sandboxToken = $this->issueToken($sandbox)->plainTextToken;
        $productionToken = $this->issueToken($production)->plainTextToken;

        $this->getJson('/api/v1/partner/me', $this->bearer($sandboxToken))
            ->assertOk()
            ->assertJsonPath('data.company.id', $sandboxCompany->b2b_id)
            ->assertJsonPath('data.client.environment', 'sandbox');

        $this->getJson('/api/v1/partner/me', $this->bearer($productionToken))
            ->assertOk()
            ->assertJsonPath('data.company.id', $productionCompany->b2b_id)
            ->assertJsonPath('data.client.environment', 'production');
    }

    public function test_the_partner_context_refuses_to_answer_outside_an_authenticated_request(): void
    {
        $this->expectException(\RuntimeException::class);

        app(PartnerContext::class)->companyId();
    }

    public function test_a_partner_token_cannot_authenticate_the_regular_sanctum_api(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        // The credential is stored in partner_api_tokens, not
        // personal_access_tokens: it must be worthless everywhere else.
        $this->getJson('/api/auth/me', $this->bearer($token))->assertStatus(401);
    }
}
