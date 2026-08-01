<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_dashboard(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Dashboard')
                ->has('summary.total_b2c_customers')
                ->has('summary.total_vehicles')
                ->has('summary.total_orders')
            );
    }

    public function test_non_admin_cannot_view_the_dashboard(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }
}
