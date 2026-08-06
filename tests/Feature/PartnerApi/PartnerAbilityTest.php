<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

/**
 * Scope enforcement.
 *
 * Phase 1 ships no ability-gated endpoint — /health and /me are deliberately
 * scope-free so a partner can verify a new credential before any feature is
 * enabled for them. The gate itself is exercised here against routes defined
 * in the test, which is the same middleware stack the phase 2 endpoints will
 * declare. Doing it now means the first real endpoint inherits proven
 * enforcement instead of being the thing that proves it.
 */
class PartnerAbilityTest extends TestCase
{
    use BuildsPartnerClients, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'partner.request-id', 'partner.auth', 'partner.throttle'])
            ->prefix('api/v1/partner')
            ->group(function () {
                Route::get('scoped-single', fn () => response()->json(['data' => ['ok' => true]]))
                    ->middleware('partner.ability:vehicles.read');

                Route::get('scoped-both', fn () => response()->json(['data' => ['ok' => true]]))
                    ->middleware('partner.ability:vehicles.read,orders.write');

                Route::get('scoped-typo', fn () => response()->json(['data' => ['ok' => true]]))
                    ->middleware('partner.ability:vehicles.reed');
            });
    }

    public function test_a_token_carrying_the_ability_passes(): void
    {
        [, $token] = $this->makeAuthenticatedPartner(abilities: ['vehicles.read']);

        $this->getJson('/api/v1/partner/scoped-single', $this->bearer($token))->assertOk();
    }

    public function test_a_token_without_the_ability_is_refused_with_insufficient_scope(): void
    {
        [, $token] = $this->makeAuthenticatedPartner(abilities: ['orders.read']);

        $this->getJson('/api/v1/partner/scoped-single', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'insufficient_scope')
            ->assertJsonPath('error.type', 'authorization_error')
            ->assertJsonPath('error.details.required_ability', 'vehicles.read')
            ->assertJsonPath('error.details.granted_abilities', ['orders.read']);
    }

    public function test_a_token_with_no_abilities_at_all_is_refused(): void
    {
        [, $token] = $this->makeAuthenticatedPartner(abilities: []);

        $this->getJson('/api/v1/partner/scoped-single', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'insufficient_scope');
    }

    public function test_every_listed_ability_is_required_not_just_one(): void
    {
        [, $token] = $this->makeAuthenticatedPartner(abilities: ['vehicles.read']);

        $this->getJson('/api/v1/partner/scoped-both', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('error.details.required_ability', 'orders.write');
    }

    public function test_an_unknown_ability_in_a_route_definition_fails_closed(): void
    {
        [, $token] = $this->makeAuthenticatedPartner(abilities: [PartnerAbility::WILDCARD]);

        $this->getJson('/api/v1/partner/scoped-typo', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'insufficient_scope');
    }

    public function test_a_wildcard_token_passes_every_defined_ability(): void
    {
        [, $token] = $this->makeAuthenticatedPartner(abilities: [PartnerAbility::WILDCARD]);

        $this->getJson('/api/v1/partner/scoped-both', $this->bearer($token))->assertOk();
    }

    public function test_me_expands_a_wildcard_rather_than_answering_with_a_star(): void
    {
        [, $token] = $this->makeAuthenticatedPartner(abilities: [PartnerAbility::WILDCARD]);

        $this->getJson('/api/v1/partner/me', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.token.abilities', PartnerAbility::values());
    }

    public function test_scope_free_endpoints_stay_reachable_with_an_empty_scope_set(): void
    {
        [, $token] = $this->makeAuthenticatedPartner(abilities: []);

        $this->getJson('/api/v1/partner/health', $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/partner/me', $this->bearer($token))->assertOk();
    }

    public function test_the_ability_gate_refuses_an_unauthenticated_request(): void
    {
        Route::middleware(['api', 'partner.request-id'])
            ->get('api/v1/partner/scoped-unauthenticated', fn () => response()->json(['data' => []]))
            ->middleware('partner.ability:vehicles.read');

        $this->getJson('/api/v1/partner/scoped-unauthenticated', ['Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'missing_token');
    }
}
