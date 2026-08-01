<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the AdminPolicy/Gate refactor of AdminController —
 * these endpoints previously each carried their own private
 * `user_type->value !== 'Admin'` check; this proves the centralized Gate
 * wiring didn't silently drop the guard on any of them.
 */
class AdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_admin_can_view_dashboard_summary(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->withHeaders($this->bearer($admin))
            ->getJson('/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonStructure(['total_b2c_customers', 'total_vehicles', 'total_orders']);
    }

    public function test_non_admin_cannot_view_dashboard_summary(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->getJson('/admin/dashboard/summary')
            ->assertForbidden();
    }

    public function test_non_admin_cannot_list_b2c_customers(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->getJson('/admin/users/b2c')
            ->assertForbidden();
    }

    public function test_non_admin_cannot_list_b2b_customers(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->getJson('/admin/users/b2b')
            ->assertForbidden();
    }

    public function test_admin_can_update_b2c_status(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde, 'is_active' => true]);

        $this->withHeaders($this->bearer($admin))
            ->patchJson("/admin/b2c/{$customer->id}/status", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'is_active' => false]);
    }

    public function test_non_admin_cannot_update_b2c_status(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde, 'is_active' => true]);

        $this->withHeaders($this->bearer($user))
            ->patchJson("/admin/b2c/{$customer->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'is_active' => true]);
    }

    public function test_non_admin_cannot_update_b2b_status(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->patchJson('/admin/b2b/'.fake()->uuid().'/status', ['is_active' => false])
            ->assertForbidden();
    }

    public function test_non_admin_cannot_list_vehicles(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->getJson('/admin/list/vehicles')
            ->assertForbidden();
    }

    /**
     * Regression test for the AdminQueryService::b2cList() extraction: the
     * success path was previously only exercised by
     * updateB2cStatus()'s own db-write flow, never a plain list call.
     */
    public function test_admin_can_list_b2c_customers(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        User::factory()->create(['user_type' => UserType::Privatkunde, 'email' => 'jane@example.com', 'is_active' => true]);
        User::factory()->create(['user_type' => UserType::Privatkunde, 'email' => 'other@example.com', 'is_active' => false]);

        $response = $this->withHeaders($this->bearer($admin))->getJson('/admin/users/b2c');

        $response->assertOk();
        $this->assertSame(2, $response->json('total'));
        $this->assertSame(1, $response->json('total_active'));
        $this->assertSame(1, $response->json('total_inactive'));
    }

    /**
     * Regression test for the new `search` parameter — leasyback_web's own
     * admin panel fetched the whole list and searched client-side; this
     * proves the new server-side search actually filters.
     */
    public function test_admin_can_search_b2c_customers_by_email(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        User::factory()->create(['user_type' => UserType::Privatkunde, 'email' => 'jane@example.com']);
        User::factory()->create(['user_type' => UserType::Privatkunde, 'email' => 'other@example.com']);

        $response = $this->withHeaders($this->bearer($admin))->getJson('/admin/users/b2c?search=jane');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('jane@example.com', $data[0]['user_email']);
    }
}
