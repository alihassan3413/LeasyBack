<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'user_type' => UserType::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'user_type' => UserType::Privatkunde,
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));
    }

    public function test_admin_can_impersonate_a_customer(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $response = $this->actingAs($admin)->post(route('admin.impersonate.store', $customer->id));

        $response->assertRedirect(route('dashboard'));
        $this->assertSame($customer->id, Auth::id());
        $this->assertSame($admin->id, session(ImpersonationController::SESSION_KEY));
    }

    public function test_impersonation_state_is_shared_with_the_frontend(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin)->post(route('admin.impersonate.store', $customer->id));

        $this->get(route('dashboard'))->assertInertia(
            fn ($page) => $page->where('impersonation.active', true)->where('impersonation.admin_name', $admin->name)
        );
    }

    public function test_stopping_impersonation_returns_to_the_admin(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin)->post(route('admin.impersonate.store', $customer->id));

        $response = $this->delete(route('impersonate.destroy'));

        $response->assertRedirect(route('admin.customers.index'));
        $this->assertSame($admin->id, Auth::id());
        $this->assertFalse(session()->has(ImpersonationController::SESSION_KEY));
    }

    public function test_admin_cannot_impersonate_another_admin(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin)->post(route('admin.impersonate.store', $other->id))->assertSessionHasErrors('impersonate');

        $this->assertSame($admin->id, Auth::id());
    }

    public function test_admin_cannot_impersonate_a_deactivated_account(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['is_active' => false]);

        $this->actingAs($admin)->post(route('admin.impersonate.store', $customer->id))->assertSessionHasErrors('impersonate');

        $this->assertSame($admin->id, Auth::id());
    }

    public function test_impersonation_cannot_be_nested(): void
    {
        $admin = $this->admin();
        $first = $this->customer();
        $second = $this->customer();

        $this->actingAs($admin)->post(route('admin.impersonate.store', $first->id));
        $this->post(route('admin.impersonate.store', $second->id))->assertForbidden();

        $this->assertSame($first->id, Auth::id());
        $this->assertSame($admin->id, session(ImpersonationController::SESSION_KEY));
    }

    public function test_a_customer_cannot_impersonate_anyone(): void
    {
        $customer = $this->customer();
        $target = $this->customer();

        $this->actingAs($customer)->post(route('admin.impersonate.store', $target->id))->assertForbidden();

        $this->assertSame($customer->id, Auth::id());
    }

    public function test_stopping_without_an_active_impersonation_is_a_no_op(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->delete(route('impersonate.destroy'))->assertRedirect(route('dashboard'));

        $this->assertSame($customer->id, Auth::id());
    }
}
