<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bPermission;
use App\Enums\B2bRolePreset;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Models\B2bInvitation;
use App\Notifications\B2bInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The team page's write paths and the role *names* shown for the result:
 * one person must carry one label on every surface, and the actions the page
 * offers must be the ones the server accepts.
 */
class B2bTeamManagementTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    // ── One Company Administrator per company ───────────────────────

    /**
     * The company already has its administrator, so a second one cannot be
     * invited — the server refuses whatever the form sent, and nothing is
     * stored or emailed.
     */
    public function test_a_second_administrator_cannot_be_invited(): void
    {
        Notification::fake();

        $owner = $this->makeOwner($this->makeCompany('Alpha GmbH'));

        $this->actingAs($owner)
            ->post(route('b2b.invitations.store'), [
                'email' => 'chefin@example.com',
                'role' => 'owner',
                'permissions' => [B2bPermission::ViewVehicles->value],
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('b2b_invitations', ['email' => 'chefin@example.com']);
        Notification::assertNothingSent();
    }

    public function test_a_member_cannot_be_promoted_to_a_second_administrator(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $member = $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray());

        $this->actingAs($owner)
            ->patch(route('b2b.members.update', $member->id), [
                'role' => 'owner',
                'permissions' => B2bPermission::values(),
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasErrors();

        $this->assertSame(1, DB::table('user_b2b')->where('b2b_id', $company->b2b_id)->where('role', 'owner')->count());
    }

    /**
     * An administrator invitation sent before the rule (or carried over from
     * the old system) is still pending. Accepting it must not create a second
     * administrator — the check runs again at acceptance, not only at invite.
     */
    public function test_a_stale_administrator_invitation_cannot_be_accepted(): void
    {
        $company = $this->makeCompany();
        $this->makeOwner($company);
        $invitee = User::factory()->create(['user_type' => UserType::Firmenkunde, 'email' => 'spaet@example.com']);

        DB::table('b2b_invitations')->insert([
            'invitation_id' => (string) Str::uuid(),
            'b2b_id' => $company->b2b_id,
            'email' => 'spaet@example.com',
            'role' => 'owner',
            'permissions' => json_encode(B2bPermission::values()),
            'vehicle_scope' => 'all',
            'token_hash' => hash('sha256', 'stale-owner-token'),
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($invitee)->post(route('b2b.invitations.accept', 'stale-owner-token'));

        $this->assertContains($response->status(), [302, 422], 'refused, not a server error');
        $this->assertDatabaseMissing('user_b2b', ['b2b_id' => $company->b2b_id, 'user_id' => $invitee->id]);
        $this->assertSame(1, DB::table('user_b2b')->where('b2b_id', $company->b2b_id)->where('role', 'owner')->count());
    }

    /** The page stops offering the role once there is an administrator; the other roles stay. */
    public function test_the_members_page_does_not_offer_the_administrator_role_once_one_exists(): void
    {
        $owner = $this->makeOwner($this->makeCompany());

        $this->actingAs($owner)
            ->get(route('b2b.members.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.assign_owner', false)
                ->where('can.manage_members', true)
                ->where('rolePresets', fn ($presets) => collect($presets)->where('assigns_owner', false)->pluck('value')->sort()->values()->all()
                    === collect([B2bRolePreset::ReadOnly->value, B2bRolePreset::StandardUser->value])->sort()->values()->all())
            );
    }

    /** An owner invitation stored with a partial list before this fix still reads as the administrator. */
    public function test_a_legacy_owner_invitation_row_is_labelled_as_the_administrator(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);

        DB::table('b2b_invitations')->insert([
            'invitation_id' => (string) Str::uuid(),
            'b2b_id' => $company->b2b_id,
            'email' => 'alt@example.com',
            'role' => 'owner',
            'permissions' => json_encode([B2bPermission::ViewVehicles->value]),
            'vehicle_scope' => 'all',
            'token_hash' => hash('sha256', 'legacy-token'),
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('b2b.members.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invitations.0.preset_label', B2bRolePreset::CompanyAdministrator->label())
            );
    }

    public function test_an_invitation_email_names_the_company_role(): void
    {
        Notification::fake();

        $company = $this->makeCompany();

        $this->actingAs($this->makeOwner($company))
            ->post(route('b2b.invitations.store'), [
                'email' => 'kollege@example.com',
                'preset' => B2bRolePreset::StandardUser->value,
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(
            B2bInvitationNotification::class,
            fn (B2bInvitationNotification $notification) => (new \ReflectionProperty($notification, 'roleLabel'))->getValue($notification)
                === B2bRolePreset::StandardUser->label(),
        );
    }

    // ── One label per person, on every surface ───────────────────────

    /**
     * The company switcher reads `role_label` from the shared props; it used
     * to say "Inhaber"/"Mitglied" while the team page said
     * "Unternehmens-Administrator"/"Standardnutzer".
     */
    public function test_the_shared_props_carry_the_company_role_name(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $other = $this->makeCompany('Beta GmbH');
        $user = $this->makeOwner($company);

        DB::table('user_b2b')->insert([
            'user_id' => $user->id,
            'b2b_id' => $other->b2b_id,
            'role' => 'member',
            'permissions' => json_encode(B2bRolePreset::ReadOnly->permissions()->toArray()),
            'vehicle_scope' => 'all',
            'status' => 'active',
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);

        $props = $this->actingAs($user)->get(route('vehicles.index'))->viewData('page')['props'];
        $labels = collect($props['auth']['b2b']['memberships'])->pluck('role_label', 'company_name');

        $this->assertSame(B2bRolePreset::CompanyAdministrator->label(), $labels['Alpha GmbH']);
        $this->assertSame(B2bRolePreset::ReadOnly->label(), $labels['Beta GmbH']);
        $this->assertSame(B2bRolePreset::CompanyAdministrator->label(), $props['auth']['b2b']['active']['role_label']);
    }

    public function test_a_hand_picked_membership_is_individuell_in_the_shared_props(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::DeleteVehicleDocuments->value]);

        $props = $this->actingAs($member)->get(route('vehicles.index'))->viewData('page')['props'];

        $this->assertSame(B2bRolePreset::customLabel(), $props['auth']['b2b']['active']['role_label']);
    }

    public function test_the_admin_customer_page_names_each_members_company_role(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $operator = $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray());

        $members = $this->actingAs($this->makeAdmin())
            ->get(route('admin.customers.show', ['type' => 'b2b', 'id' => $company->b2b_id]))
            ->assertOk()
            ->viewData('page')['props']['customer']['members'];

        $labels = collect($members)->pluck('role_label', 'user_id');

        $this->assertSame(B2bRolePreset::CompanyAdministrator->label(), $labels[$owner->id]);
        $this->assertSame(B2bRolePreset::StandardUser->label(), $labels[$operator->id]);
    }

    // ── An empty custom permission set ───────────────────────────────

    public function test_an_empty_custom_permission_set_is_accepted(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $member = $this->makeMember($company, [B2bPermission::ViewVehicles->value]);

        $this->actingAs($owner)
            ->patch(route('b2b.members.update', $member->id), [
                'preset' => null,
                'role' => 'member',
                'permissions' => [],
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('[]', DB::table('user_b2b')->where('user_id', $member->id)->value('permissions'));

        Notification::fake();

        $this->actingAs($owner)
            ->post(route('b2b.invitations.store'), [
                'email' => 'leer@example.com',
                'role' => 'member',
                'permissions' => [],
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([], B2bInvitation::where('email', 'leer@example.com')->firstOrFail()->permissions);
    }

    public function test_a_custom_set_without_any_permissions_key_is_still_refused(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $member = $this->makeMember($company, [B2bPermission::ViewVehicles->value]);

        $this->actingAs($owner)
            ->patch(route('b2b.members.update', $member->id), [
                'role' => 'member',
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasErrors('permissions');
    }

    // ── Removing members ─────────────────────────────────────────────

    public function test_an_administrator_cannot_remove_themselves(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        // A second owner, so the last-owner rule is not what refuses it.
        $this->makeOwner($company);

        $this->actingAs($owner)
            ->delete(route('b2b.members.destroy', $owner->id))
            ->assertSessionHasErrors(['member' => 'Sie können sich nicht selbst aus dem Unternehmen entfernen. Bitten Sie einen anderen Administrator darum.']);

        $this->assertDatabaseHas('user_b2b', ['user_id' => $owner->id, 'b2b_id' => $company->b2b_id]);
    }

    public function test_a_member_manager_cannot_remove_themselves(): void
    {
        $company = $this->makeCompany();
        $this->makeOwner($company);
        $manager = $this->makeMember($company, [B2bPermission::ViewMembers->value, B2bPermission::ManageMembers->value]);

        $this->actingAs($manager)
            ->delete(route('b2b.members.destroy', $manager->id))
            ->assertSessionHasErrors('member');

        $this->assertDatabaseHas('user_b2b', ['user_id' => $manager->id, 'b2b_id' => $company->b2b_id]);
    }

    public function test_removing_someone_else_still_works_and_the_last_owner_is_still_protected(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $member = $this->makeMember($company, [B2bPermission::ViewVehicles->value]);
        $manager = $this->makeMember($company, [B2bPermission::ViewMembers->value, B2bPermission::ManageMembers->value]);

        $this->actingAs($owner)
            ->delete(route('b2b.members.destroy', $member->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('user_b2b', ['user_id' => $member->id]);

        $this->actingAs($manager)
            ->delete(route('b2b.members.destroy', $owner->id))
            ->assertSessionHasErrors('member');

        $this->assertDatabaseHas('user_b2b', ['user_id' => $owner->id]);
    }

    // ── Registering from an invitation ───────────────────────────────

    /**
     * The invitation page's "Konto erstellen" link carries the token, and the
     * register page it opens joins the company on submit — which is what the
     * page's copy now promises.
     */
    public function test_registering_from_the_invitation_page_joins_directly(): void
    {
        Notification::fake();

        $company = $this->makeCompany('Alpha GmbH');
        $this->actingAs($this->makeOwner($company))
            ->post(route('b2b.invitations.store'), [
                'email' => 'direkt@example.com',
                'preset' => B2bRolePreset::StandardUser->value,
                'vehicle_scope' => 'all',
            ]);

        $token = null;
        Notification::assertSentOnDemand(B2bInvitationNotification::class, function (B2bInvitationNotification $notification) use (&$token) {
            $token = Str::afterLast((new \ReflectionProperty($notification, 'acceptUrl'))->getValue($notification), '/');

            return true;
        });

        $this->app['auth']->guard()->logout();
        $this->app['auth']->forgetGuards();

        $this->get(route('b2b.invitations.show', $token))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('token', $token)
                ->where('account_exists', false)
            );

        $this->get(route('register', ['invitation' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('invitation.token', $token));

        $this->post(route('register'), [
            'email' => 'direkt@example.com',
            'password' => 'Sicher-Genug-123',
            'invitation' => $token,
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'direkt@example.com')->firstOrFail();

        $this->assertSame(UserType::Firmenkunde, $user->user_type);
        $this->assertDatabaseHas('user_b2b', ['user_id' => $user->id, 'b2b_id' => $company->b2b_id]);
    }
}
