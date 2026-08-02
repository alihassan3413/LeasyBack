<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HandleInertiaRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_auth_user_prop_exposes_only_the_intended_fields(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Firmenkunde]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.name', $user->name)
                ->where('auth.user.email', $user->email)
                ->where('auth.user.user_type', UserType::Firmenkunde->value)
                ->has('auth.user.email_verified_at')
                ->where('auth.user.id', $user->id)
                ->missing('auth.user.avatar')
                ->missing('auth.user.created_at')
                ->missing('auth.user.updated_at')
            );
    }

    public function test_shared_auth_user_prop_is_null_when_guest(): void
    {
        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user', null)
            );
    }
}
