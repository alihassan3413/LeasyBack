<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Checkpoint 6: verifies config/cors.php is actually production-safe in
 * behavior, not just on paper — no allow_any_origin, explicit allow-list
 * plus a localhost dev pattern, no credentialed cross-origin requests.
 */
class CorsTest extends TestCase
{
    public function test_allowed_frontend_origin_is_reflected(): void
    {
        $response = $this->withHeaders([
            'Origin' => config('cors.allowed_origins')[0],
        ])->postJson('/auth/login', ['user_email' => 'x@example.com', 'password' => 'x']);

        $response->assertHeader('Access-Control-Allow-Origin', config('cors.allowed_origins')[0]);
    }

    public function test_localhost_dev_origin_is_reflected(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->postJson('/auth/login', ['user_email' => 'x@example.com', 'password' => 'x']);

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_untrusted_origin_does_not_get_an_allow_origin_header(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://attacker-controlled.example',
        ])->postJson('/auth/login', ['user_email' => 'x@example.com', 'password' => 'x']);

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_wildcard_origin_is_not_configured(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
    }
}
