<?php

namespace Tests\Feature\Api;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function stationPayload(): array
    {
        return [
            'name' => 'Test Station',
            'strasse' => 'Teststrasse 1',
            'plz' => '10115',
            'ort' => 'Berlin',
        ];
    }

    /**
     * Regression test: createStation() previously had no role check at
     * all — any authenticated user could create inspection stations.
     */
    public function test_non_admin_cannot_create_inspection_station(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->postJson('/order/stations/create', $this->stationPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('inspection_stations', ['name' => 'Test Station']);
    }

    public function test_admin_can_create_inspection_station(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->withHeaders($this->bearer($admin))
            ->postJson('/order/stations/create', $this->stationPayload())
            ->assertCreated();

        $this->assertDatabaseHas('inspection_stations', ['name' => 'Test Station']);
    }
}
