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

    /**
     * The publishable key is meant to reach the browser — Stripe.js cannot
     * mount a card field without it. The other two values in the same config
     * block must never follow it there, which is the actual point of this
     * test: `stripe` is shared as an explicit one-key array rather than as
     * `config('services.stripe')`, and this fails the moment someone
     * "simplifies" it into the latter.
     */
    public function test_only_the_stripe_publishable_key_is_shared(): void
    {
        config([
            'services.stripe.key' => 'pk_test_shared',
            'services.stripe.secret' => 'sk_test_never_shared',
            'services.stripe.webhook_secret' => 'whsec_never_shared',
        ]);

        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stripe.key', 'pk_test_shared')
                ->missing('stripe.secret')
                ->missing('stripe.webhook_secret')
            );
    }

    /**
     * Null rather than '' so the frontend can distinguish "payments are not
     * configured in this environment" from a blank key, and refuse to render
     * a card field that could never work.
     */
    public function test_stripe_key_is_null_when_unconfigured(): void
    {
        config(['services.stripe.key' => '']);

        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stripe.key', null)
            );
    }
}
