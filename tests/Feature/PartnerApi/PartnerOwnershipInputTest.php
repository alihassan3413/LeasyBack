<?php

namespace Tests\Feature\PartnerApi;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

/**
 * Ownership is never request input.
 *
 * Refusing rather than ignoring is the deliberate part: a partner that sends
 * `b2b_id` and gets a 200 back will ship code believing the field routes their
 * data, and will discover otherwise at the worst possible moment.
 */
class PartnerOwnershipInputTest extends TestCase
{
    use BuildsPartnerClients, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'partner.request-id', 'partner.auth', 'partner.throttle', 'partner.no-ownership'])
            ->post('api/v1/partner/echo', fn () => response()->json(['data' => ['ok' => true]]));
    }

    public function test_a_query_parameter_naming_a_company_is_refused(): void
    {
        $foreign = $this->makePartnerCompany('Fremde GmbH');
        [, $token] = $this->makeAuthenticatedPartner();

        $this->getJson('/api/v1/partner/me?b2b_id='.$foreign->b2b_id, $this->bearer($token))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'ownership_input_not_allowed')
            ->assertJsonPath('error.type', 'invalid_request_error')
            ->assertJsonPath('error.details.rejected_parameters', ['b2b_id']);
    }

    public function test_a_body_field_naming_a_company_is_refused(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->postJson('/api/v1/partner/echo', ['company_id' => 'anything'], $this->bearer($token))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'ownership_input_not_allowed')
            ->assertJsonPath('error.details.rejected_parameters', ['company_id']);
    }

    public function test_every_rejected_key_is_reported_not_just_the_first(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->postJson(
            '/api/v1/partner/echo',
            ['b2b_id' => 'x', 'user_id' => 1, 'created_by_user_id' => 2],
            $this->bearer($token),
        )
            ->assertStatus(400)
            ->assertJsonPath('error.details.rejected_parameters', ['b2b_id', 'created_by_user_id', 'user_id']);
    }

    public function test_the_configured_key_list_is_what_is_enforced(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        config(['partner_api.rejected_input_keys' => ['tenant_id']]);

        $this->postJson('/api/v1/partner/echo', ['b2b_id' => 'x'], $this->bearer($token))->assertOk();
        $this->postJson('/api/v1/partner/echo', ['tenant_id' => 'x'], $this->bearer($token))->assertStatus(400);
    }

    public function test_an_ordinary_payload_passes_untouched(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->postJson(
            '/api/v1/partner/echo',
            ['license_plate' => 'M-AB 1234', 'external_vehicle_id' => 'ext-1'],
            $this->bearer($token),
        )->assertOk();
    }
}
