<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The ping behind "Angemeldet bleiben" in the inactivity warning. */
class SessionKeepAliveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_keeps_the_session_alive(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('session.keep-alive'))
            ->assertOk()
            ->assertJson(['lifetime' => config('session.lifetime')])
            ->assertSessionHas('last_seen_at');
    }

    public function test_a_guest_is_refused(): void
    {
        $this->postJson(route('session.keep-alive'))->assertUnauthorized();
    }
}
