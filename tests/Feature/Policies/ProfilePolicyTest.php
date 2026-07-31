<?php

namespace Tests\Feature\Policies;

use App\Enums\UserType;
use App\Models\LeasybackUserProfile;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * No live route exercises `viewProfile`/`viewPreferences` yet (`show()`
     * is always self-scoped via the authenticated user, so there's no
     * IDOR surface to test through HTTP). Asserted directly against the
     * Policy — the same rule a future admin-view-by-id endpoint would rely
     * on, per docs/B2C_ADMIN_PERMISSION_MATRIX.md ("view own profile" ✅
     * any for Admin).
     */
    public function test_view_profile_ability(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $created = LeasybackUserProfile::factory()->create(['user_id' => $owner->id]);
        // Re-fetched through the App\Models shim (as every real controller
        // does) since the Policy type-hints that class specifically.
        $profile = LeasybackUserProfile::find($created->profile_id);

        $this->assertTrue($owner->can('viewProfile', $profile));
        $this->assertFalse($stranger->can('viewProfile', $profile));
        $this->assertTrue($admin->can('viewProfile', $profile));
    }

    public function test_view_preferences_ability(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $created = UserPreference::factory()->create(['user_id' => $owner->id]);
        $preference = UserPreference::find($created->preference_id);

        $this->assertTrue($owner->can('viewPreferences', $preference));
        $this->assertFalse($stranger->can('viewPreferences', $preference));
        $this->assertTrue($admin->can('viewPreferences', $preference));
    }

    public function test_create_and_update_abilities_deny_admin(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $privatkunde = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->assertFalse($admin->can('createProfile', LeasybackUserProfile::class));
        $this->assertFalse($admin->can('updateProfile', LeasybackUserProfile::class));
        $this->assertFalse($admin->can('createPreferences', UserPreference::class));
        $this->assertFalse($admin->can('updatePreferences', UserPreference::class));

        $this->assertTrue($privatkunde->can('createProfile', LeasybackUserProfile::class));
        $this->assertTrue($privatkunde->can('updateProfile', LeasybackUserProfile::class));
        $this->assertTrue($privatkunde->can('createPreferences', UserPreference::class));
        $this->assertTrue($privatkunde->can('updatePreferences', UserPreference::class));
    }
}
