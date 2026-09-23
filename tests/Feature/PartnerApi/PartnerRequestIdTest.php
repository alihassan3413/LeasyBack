<?php

namespace Tests\Feature\PartnerApi;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

/**
 * X-Request-ID correlation.
 *
 * The id has to survive the paths that matter most for support: a 401 has no
 * authenticated context to hang it off, and a malformed inbound value must
 * not be the reason a working request fails.
 */
class PartnerRequestIdTest extends TestCase
{
    use BuildsPartnerClients, RefreshDatabase;

    public function test_an_inbound_request_id_is_echoed_in_the_header_and_the_body(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $response = $this->getJson('/api/v1/partner/health', [
            ...$this->bearer($token),
            'X-Request-ID' => 'partner-trace-123',
        ]);

        $response->assertOk()
            ->assertHeader('X-Request-ID', 'partner-trace-123')
            ->assertJsonPath('request_id', 'partner-trace-123');
    }

    public function test_a_request_without_one_is_assigned_a_generated_id(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $response = $this->getJson('/api/v1/partner/health', $this->bearer($token));

        $generated = $response->headers->get('X-Request-ID');

        $this->assertTrue(Str::isUuid($generated));
        $this->assertSame($generated, $response->json('request_id'));
    }

    public function test_unauthenticated_failures_still_carry_a_request_id(): void
    {
        $response = $this->getJson('/api/v1/partner/me', [
            'Accept' => 'application/json',
            'X-Request-ID' => 'trace-on-a-401',
        ]);

        $response->assertStatus(401)
            ->assertHeader('X-Request-ID', 'trace-on-a-401')
            ->assertJsonPath('request_id', 'trace-on-a-401');
    }

    public function test_a_malformed_request_id_is_replaced_rather_than_rejected(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $response = $this->getJson('/api/v1/partner/health', [
            ...$this->bearer($token),
            'X-Request-ID' => "bad\r\nInjected-Header: yes",
        ]);

        $response->assertOk();
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        $this->assertNull($response->headers->get('Injected-Header'));
    }

    public function test_an_over_long_request_id_is_replaced(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $response = $this->getJson('/api/v1/partner/health', [
            ...$this->bearer($token),
            'X-Request-ID' => str_repeat('a', 200),
        ]);

        $response->assertOk();
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
    }

    public function test_two_requests_get_two_different_generated_ids(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $first = $this->getJson('/api/v1/partner/health', $this->bearer($token))->json('request_id');
        $second = $this->getJson('/api/v1/partner/health', $this->bearer($token))->json('request_id');

        $this->assertNotSame($first, $second);
    }
}
