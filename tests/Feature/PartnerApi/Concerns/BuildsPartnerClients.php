<?php

namespace Tests\Feature\PartnerApi\Concerns;

use App\Enums\UserType;
use App\Models\Address;
use App\Models\B2B;
use App\Models\Contact;
use App\Models\User;
use App\Modules\PartnerApi\Data\IssuedPartnerToken;
use App\Modules\PartnerApi\Enums\PartnerEnvironment;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use App\Modules\PartnerApi\Services\PartnerClientProvisioner;
use App\Modules\PartnerApi\Services\PartnerTokenService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Scaffolding for the Partner API tests.
 *
 * Tokens are always minted through PartnerTokenService rather than written
 * with a factory: the plaintext is the only thing a request can send, and the
 * service is the only thing that produces one. A test that hand-wrote a hash
 * would be asserting against its own idea of the format.
 */
trait BuildsPartnerClients
{
    protected function makePartnerCompany(string $name = 'Partner Kunde GmbH'): B2B
    {
        return B2B::create([
            'contact_id' => Contact::factory()->create()->contact_id,
            'address_id' => Address::factory()->create()->address_id,
            'company_name' => $name,
            'contact_email' => fake()->unique()->safeEmail(),
        ]);
    }

    protected function makePartnerClient(
        ?B2B $company = null,
        PartnerEnvironment $environment = PartnerEnvironment::Sandbox,
        string $slug = 'test-partner',
        bool $active = true,
    ): PartnerIntegrationClient {
        $company ??= $this->makePartnerCompany();
        $user = $this->makeIntegrationUser($company);

        $client = new PartnerIntegrationClient([
            'slug' => $slug,
            'name' => 'Test Partner',
            'environment' => $environment,
            'is_active' => $active,
        ]);

        $client->b2b_id = $company->b2b_id;
        $client->user_id = $user->id;
        $client->save();

        return $client->fresh(['company', 'user']);
    }

    /**
     * @param  list<string>|null  $abilities
     */
    protected function issueToken(
        PartnerIntegrationClient $client,
        ?array $abilities = null,
        ?CarbonInterface $expiresAt = null,
    ): IssuedPartnerToken {
        return app(PartnerTokenService::class)->issue(
            client: $client,
            name: 'test',
            abilities: $abilities,
            expiresAt: $expiresAt,
        );
    }

    /**
     * A ready-to-use client plus its plaintext token.
     *
     * @param  list<string>|null  $abilities
     * @return array{0: PartnerIntegrationClient, 1: string}
     */
    protected function makeAuthenticatedPartner(
        ?B2B $company = null,
        ?array $abilities = null,
        string $slug = 'test-partner',
    ): array {
        $client = $this->makePartnerClient(company: $company, slug: $slug);

        return [$client, $this->issueToken($client, $abilities)->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makeIntegrationUser(B2B $company): User
    {
        $user = User::factory()->create([
            'user_type' => UserType::Firmenkunde,
            'active_b2b_id' => $company->b2b_id,
        ]);

        DB::table('user_b2b')->insert([
            'user_id' => $user->id,
            'b2b_id' => $company->b2b_id,
            'role' => 'member',
            'permissions' => json_encode(PartnerClientProvisioner::INTEGRATION_USER_PERMISSIONS),
            'vehicle_scope' => 'all',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }
}
