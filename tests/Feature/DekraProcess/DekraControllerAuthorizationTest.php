<?php

namespace Tests\Feature\DekraProcess;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression tests for a real, previously-live vulnerability: every
 * Sanctum-authenticated DekraController method had zero role checks —
 * any authenticated user, of any type, could create DEKRA clients/orders
 * or trigger a real send to the DEKRA API (docs/B2C_ADMIN_MIGRATION_AUDIT.md
 * §5's DekraProcess row). Now gated by AdminPolicy::manageDekraProcess.
 */
class DekraControllerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            ['POST', '/dekra/clients', ['client_name' => 'Test']],
            ['POST', '/dekra/dienstleistungsobjekt', []],
            ['POST', '/dekra/besichtigungs_orte', []],
            ['POST', '/dekra/kunden_auftrag', []],
            ['POST', '/dekra/anlage_liste', []],
            ['POST', '/dekra/partner', []],
            ['GET', '/dekra/auftrag/info/does-not-exist', []],
            ['POST', '/dekra/auftrag', []],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_non_admin_cannot_access_any_dekra_endpoint(string $method, string $uri, array $payload): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->json($method, $uri, $payload)
            ->assertForbidden();
    }

    public function test_admin_can_create_a_dekra_client(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->withHeaders($this->bearer($admin))
            ->postJson('/dekra/clients', ['client_name' => 'Test Client'])
            ->assertCreated();

        $this->assertDatabaseHas('clients', ['client_name' => 'Test Client']);
    }

    public function test_admin_can_look_up_auftrag_info(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->withHeaders($this->bearer($admin))
            ->getJson('/dekra/auftrag/info/does-not-exist')
            ->assertNotFound();
    }
}
