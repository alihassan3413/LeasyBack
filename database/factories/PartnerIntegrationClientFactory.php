<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\Address;
use App\Models\B2B;
use App\Models\Contact;
use App\Models\User;
use App\Modules\PartnerApi\Enums\PartnerEnvironment;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use App\Modules\PartnerApi\Services\PartnerClientProvisioner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<PartnerIntegrationClient>
 */
class PartnerIntegrationClientFactory extends Factory
{
    protected $model = PartnerIntegrationClient::class;

    /**
     * A client is only meaningful with a company and an integration user
     * behind it, so the factory builds both — including the `user_b2b`
     * membership row, without which the integration user would authenticate
     * successfully and then be unable to see the company's own data.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'environment' => PartnerEnvironment::Sandbox,
            'b2b_id' => fn () => $this->makeCompany()->b2b_id,
            'user_id' => null,
            'is_active' => true,
            'contact_email' => fake()->unique()->safeEmail(),
            'rate_limit_per_minute' => null,
            'metadata' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PartnerIntegrationClient $client) {
            if ($client->user_id === null) {
                $client->user_id = $this->makeIntegrationUser($client->b2b_id)->id;
            }
        });
    }

    public function production(): static
    {
        return $this->state(fn () => ['environment' => PartnerEnvironment::Production]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forCompany(B2B $company): static
    {
        return $this->state(fn () => ['b2b_id' => $company->b2b_id]);
    }

    private function makeCompany(): B2B
    {
        return B2B::create([
            'contact_id' => Contact::factory()->create()->contact_id,
            'address_id' => Address::factory()->create()->address_id,
            'company_name' => fake()->company(),
            'contact_email' => fake()->unique()->safeEmail(),
        ]);
    }

    private function makeIntegrationUser(string $b2bId): User
    {
        $user = User::factory()->create(['user_type' => UserType::Firmenkunde]);

        DB::table('user_b2b')->insert([
            'user_id' => $user->id,
            'b2b_id' => $b2bId,
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
