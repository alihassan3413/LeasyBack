<?php

namespace Tests\Feature\Settings;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Profile\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PreferencesControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'timezone' => 'Europe/Berlin',
            'sprache' => 'de',
            'benachrichtigungseinstellungen_push' => true,
            'benachrichtigungseinstellungen_email' => false,
        ];
    }

    public function test_unauthenticated_is_redirected_to_login(): void
    {
        $this->get(route('preferences.edit'))->assertRedirect(route('login'));
    }

    public function test_owner_can_view_create_and_update_own_preferences(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($owner)
            ->get(route('preferences.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('preferences', null));

        $this->actingAs($owner)->post(route('preferences.store'), $this->payload())->assertRedirect(route('preferences.edit'));

        $this->actingAs($owner)
            ->get(route('preferences.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preferences.timezone', 'Europe/Berlin')
                ->where('preferences.sprache', 'de')
            );

        $preference = UserPreference::firstOrFail();

        $this->actingAs($owner)
            ->put(route('preferences.update'), array_merge($this->payload(), [
                'preference_id' => $preference->preference_id,
                'timezone' => 'Europe/London',
            ]))
            ->assertRedirect(route('preferences.edit'));

        $this->assertSame('Europe/London', $preference->fresh()->timezone);
    }

    public function test_creating_preferences_twice_flashes_a_conflict_error(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($owner)->post(route('preferences.store'), $this->payload())->assertRedirect();

        $this->actingAs($owner)
            ->from(route('preferences.edit'))
            ->post(route('preferences.store'), $this->payload())
            ->assertRedirect(route('preferences.edit'))
            ->assertSessionHasErrors(['preferences']);
    }

    public function test_non_owner_cannot_update_another_users_preferences(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $intruder = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $preference = UserPreference::factory()->create(['user_id' => $owner->id, 'timezone' => 'Europe/Berlin']);

        $this->actingAs($intruder)
            ->from(route('preferences.edit'))
            ->put(route('preferences.update'), array_merge($this->payload(), [
                'preference_id' => $preference->preference_id,
                'timezone' => 'America/New_York',
            ]))
            ->assertRedirect(route('preferences.edit'))
            ->assertSessionHasErrors(['preferences']);

        $this->assertSame('Europe/Berlin', $preference->fresh()->timezone);
    }

    public function test_admin_cannot_create_or_update_preferences(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->post(route('preferences.store'), $this->payload())->assertForbidden();

        $this->actingAs($admin)
            ->put(route('preferences.update'), array_merge($this->payload(), [
                'preference_id' => '11111111-1111-4111-8111-111111111111',
            ]))
            ->assertForbidden();
    }

    /**
     * Regression test for the ProfileService::findForUser() gap this
     * checkpoint worked around: that method returns null for the whole
     * bundle whenever no `user_profiles` row exists, which would have
     * hidden real preferences data if the Preferences page reused it. This
     * page uses the new findPreferencesForUser() instead, which only looks
     * at the independent `user_preferences` table.
     */
    public function test_preferences_are_visible_even_without_an_address_profile(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        UserPreference::factory()->create(['user_id' => $owner->id, 'timezone' => 'Europe/Berlin']);

        $this->actingAs($owner)
            ->get(route('preferences.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('preferences.timezone', 'Europe/Berlin'));
    }
}
