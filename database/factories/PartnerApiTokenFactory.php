<?php

namespace Database\Factories;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Models\PartnerApiToken;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PartnerApiToken>
 */
class PartnerApiTokenFactory extends Factory
{
    protected $model = PartnerApiToken::class;

    /**
     * The default hash is random rather than the hash of a known plaintext:
     * a factory-made token is for state assertions, not for authenticating.
     * Tests that need to make a real request issue one through
     * PartnerTokenService, which is the only path that returns a plaintext.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'partner_integration_client_id' => PartnerIntegrationClient::factory(),
            'name' => 'default',
            'token_hash' => hash('sha256', Str::random(40)),
            'abilities' => PartnerAbility::values(),
            'expires_at' => null,
            'revoked_at' => null,
            'issued_by' => 'factory',
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    /**
     * @param  list<string>  $abilities
     */
    public function withAbilities(array $abilities): static
    {
        return $this->state(fn () => ['abilities' => $abilities]);
    }
}
