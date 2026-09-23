<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bPermission;
use App\Enums\B2bRolePreset;
use App\Models\B2B;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The delegation ceiling (B2bMembership::mayGrant): a non-owner member holding
 * `members.manage` administers members strictly within their own authority —
 * for existing members and for invitations alike. An owner is unrestricted.
 */
class B2bMemberDelegationTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    /** What the managers in this test hold. */
    private const MANAGER_PERMISSIONS = ['vehicles.view', 'orders.create', 'members.view', 'members.manage'];

    private B2B $company;

    private User $owner;

    /** Manager with company-wide vehicle scope. */
    private User $manager;

    /** Manager restricted to their own vehicles. */
    private User $ownScopeManager;

    /** A member well within the managers' authority. */
    private User $member;

    /** A member holding rights the managers do not hold. */
    private User $seniorMember;

    private User $readOnly;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->company = $this->makeCompany('Delegation GmbH');
        $this->owner = $this->makeOwner($this->company);
        $this->manager = $this->makeMember($this->company, self::MANAGER_PERMISSIONS, 'all');
        $this->ownScopeManager = $this->makeMember($this->company, self::MANAGER_PERMISSIONS, 'own');
        $this->member = $this->makeMember($this->company, ['vehicles.view'], 'own');
        $this->seniorMember = $this->makeMember($this->company, ['vehicles.view', 'offers.select', 'analytics.view'], 'all');
        $this->readOnly = $this->makeMember($this->company, B2bRolePreset::ReadOnly->permissions()->toArray());
    }

    // --------------------------------------------------------------- owner

    public function test_the_owner_can_grant_every_valid_permission_and_scope(): void
    {
        $all = B2bPermission::values();

        $this->updateMember($this->owner, $this->member, ['role' => 'member', 'permissions' => $all, 'vehicle_scope' => 'all'])
            ->assertSessionHasNoErrors();
        $this->assertStored($this->member, $all, 'all', 'member');

        $this->invite($this->owner, 'voll@firma.test', ['role' => 'member', 'permissions' => $all, 'vehicle_scope' => 'all'])
            ->assertSessionHasNoErrors();
        $this->assertInvitation('voll@firma.test', $all, 'all', 'member');
    }

    /**
     * A company may only ever have one Company Administrator (B2bRole::Owner)
     * — setUp()'s $this->owner already holds it, so granting the
     * CompanyAdministrator preset to anyone else must be refused, whether by
     * promoting an existing member or by inviting someone new.
     */
    public function test_a_second_company_administrator_cannot_be_granted(): void
    {
        $this->updateMember($this->owner, $this->seniorMember, ['preset' => B2bRolePreset::CompanyAdministrator->value, 'vehicle_scope' => 'all'])
            ->assertSessionHasErrors();
        $this->assertNotSame('owner', $this->storedRole($this->seniorMember));

        $this->invite($this->owner, 'admin@firma.test', ['preset' => B2bRolePreset::CompanyAdministrator->value, 'vehicle_scope' => 'all'])
            ->assertSessionHasErrors();
        $this->assertNoInvitation('admin@firma.test');
    }

    // ------------------------------------------------------ within authority

    public function test_a_manager_can_grant_permissions_they_hold(): void
    {
        $granted = ['vehicles.view', 'orders.create'];

        $this->updateMember($this->manager, $this->member, ['role' => 'member', 'permissions' => $granted, 'vehicle_scope' => 'all'])
            ->assertSessionHasNoErrors();
        $this->assertStored($this->member, $granted, 'all', 'member');

        $this->invite($this->manager, 'kollege@firma.test', ['role' => 'member', 'permissions' => $granted, 'vehicle_scope' => 'own'])
            ->assertSessionHasNoErrors();
        $this->assertInvitation('kollege@firma.test', $granted, 'own', 'member');

        // The full own set, members.manage included, is theirs to hand on.
        $this->invite($this->manager, 'vertretung@firma.test', ['role' => 'member', 'permissions' => self::MANAGER_PERMISSIONS, 'vehicle_scope' => 'all'])
            ->assertSessionHasNoErrors();
    }

    public function test_an_own_scope_manager_can_grant_own_scope(): void
    {
        $this->updateMember($this->ownScopeManager, $this->member, ['role' => 'member', 'permissions' => ['vehicles.view', 'orders.create'], 'vehicle_scope' => 'own'])
            ->assertSessionHasNoErrors();
        $this->assertStored($this->member, ['vehicles.view', 'orders.create'], 'own', 'member');

        $this->invite($this->ownScopeManager, 'eigene@firma.test', ['role' => 'member', 'permissions' => ['vehicles.view'], 'vehicle_scope' => 'own'])
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------ above authority

    public function test_a_manager_cannot_grant_permissions_they_do_not_hold(): void
    {
        $this->updateMember($this->manager, $this->member, ['role' => 'member', 'permissions' => ['vehicles.view', 'offers.select'], 'vehicle_scope' => 'own'])
            ->assertSessionHasErrors('member');
        $this->assertStored($this->member, ['vehicles.view'], 'own', 'member');

        $this->invite($this->manager, 'freigabe@firma.test', ['role' => 'member', 'permissions' => ['vehicles.view', 'offers.select'], 'vehicle_scope' => 'own'])
            ->assertSessionHasErrors('invitation');
        $this->assertNoInvitation('freigabe@firma.test');
    }

    public function test_a_permission_cannot_be_smuggled_in_through_its_dependencies(): void
    {
        // company.manage requires company.view; the manager holds neither.
        $this->invite($this->manager, 'firma@firma.test', ['role' => 'member', 'permissions' => ['company.manage'], 'vehicle_scope' => 'own'])
            ->assertSessionHasErrors('invitation');
        $this->assertNoInvitation('firma@firma.test');
    }

    public function test_a_manager_cannot_grant_a_preset_above_their_authority(): void
    {
        // Standardnutzer carries company.view and vehicles.create, which this manager lacks.
        $this->updateMember($this->manager, $this->member, ['preset' => B2bRolePreset::StandardUser->value, 'vehicle_scope' => 'own'])
            ->assertSessionHasErrors('member');
        $this->assertStored($this->member, ['vehicles.view'], 'own', 'member');

        $this->invite($this->manager, 'standard@firma.test', ['preset' => B2bRolePreset::StandardUser->value, 'vehicle_scope' => 'own'])
            ->assertSessionHasErrors('invitation');
        $this->assertNoInvitation('standard@firma.test');
    }

    public function test_a_manager_cannot_create_an_owner_or_company_administrator(): void
    {
        foreach ([
            ['preset' => B2bRolePreset::CompanyAdministrator->value, 'vehicle_scope' => 'all'],
            ['role' => 'owner', 'permissions' => [], 'vehicle_scope' => 'all'],
        ] as $payload) {
            $this->updateMember($this->manager, $this->member, $payload)->assertSessionHasErrors('member');
            $this->assertSame('member', $this->storedRole($this->member));

            $this->invite($this->manager, 'chef@firma.test', $payload)->assertSessionHasErrors('invitation');
            $this->assertNoInvitation('chef@firma.test');
        }
    }

    public function test_a_manager_cannot_grant_a_broader_vehicle_scope(): void
    {
        $this->updateMember($this->ownScopeManager, $this->member, ['role' => 'member', 'permissions' => ['vehicles.view'], 'vehicle_scope' => 'all'])
            ->assertSessionHasErrors('member');
        $this->assertStored($this->member, ['vehicles.view'], 'own', 'member');

        $this->invite($this->ownScopeManager, 'flotte@firma.test', ['role' => 'member', 'permissions' => ['vehicles.view'], 'vehicle_scope' => 'all'])
            ->assertSessionHasErrors('invitation');
        $this->assertNoInvitation('flotte@firma.test');
    }

    public function test_a_manager_cannot_elevate_or_otherwise_change_themselves(): void
    {
        $this->updateMember($this->ownScopeManager, $this->ownScopeManager, ['role' => 'member', 'permissions' => B2bPermission::values(), 'vehicle_scope' => 'all'])
            ->assertSessionHasErrors('member');
        $this->updateMember($this->ownScopeManager, $this->ownScopeManager, ['role' => 'member', 'permissions' => self::MANAGER_PERMISSIONS, 'vehicle_scope' => 'all'])
            ->assertSessionHasErrors('member');
        $this->updateMember($this->ownScopeManager, $this->ownScopeManager, ['preset' => B2bRolePreset::CompanyAdministrator->value, 'vehicle_scope' => 'all'])
            ->assertSessionHasErrors('member');

        $this->assertStored($this->ownScopeManager, self::MANAGER_PERMISSIONS, 'own', 'member');
    }

    public function test_a_manager_cannot_change_or_remove_a_member_who_outranks_them(): void
    {
        // Even taking rights away is acting above their rank.
        $this->updateMember($this->manager, $this->seniorMember, ['role' => 'member', 'permissions' => ['vehicles.view'], 'vehicle_scope' => 'own'])
            ->assertSessionHasErrors('member');
        $this->assertStored($this->seniorMember, ['vehicles.view', 'offers.select', 'analytics.view'], 'all', 'member');

        $this->actingAs($this->manager)->from(route('b2b.members.index'))
            ->delete(route('b2b.members.destroy', $this->seniorMember->id))
            ->assertSessionHasErrors('member');
        $this->assertDatabaseHas('user_b2b', ['user_id' => $this->seniorMember->id, 'b2b_id' => $this->company->b2b_id]);

        // An own-scope manager is outranked by a company-wide member of equal rights.
        $this->updateMember($this->ownScopeManager, $this->manager, ['role' => 'member', 'permissions' => ['vehicles.view'], 'vehicle_scope' => 'own'])
            ->assertSessionHasErrors('member');
        $this->assertStored($this->manager, self::MANAGER_PERMISSIONS, 'all', 'member');

        // A member within their authority can still be removed.
        $this->actingAs($this->manager)->from(route('b2b.members.index'))
            ->delete(route('b2b.members.destroy', $this->member->id))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('user_b2b', ['user_id' => $this->member->id]);
    }

    // ------------------------------------------------------ no manage right

    public function test_a_read_only_member_cannot_manage_members(): void
    {
        $this->updateMember($this->readOnly, $this->member, ['role' => 'member', 'permissions' => ['vehicles.view'], 'vehicle_scope' => 'all'])
            ->assertForbidden();
        $this->invite($this->readOnly, 'nope@firma.test', ['role' => 'member', 'permissions' => ['vehicles.view'], 'vehicle_scope' => 'own'])
            ->assertForbidden();
        $this->actingAs($this->readOnly)->delete(route('b2b.members.destroy', $this->member->id))->assertForbidden();

        $this->assertStored($this->member, ['vehicles.view'], 'own', 'member');
        $this->assertNoInvitation('nope@firma.test');
    }

    // ----------------------------------------------------------- isolation

    public function test_membership_management_stays_within_the_active_company(): void
    {
        $other = $this->makeCompany('Andere AG');
        $otherMember = $this->makeMember($other, ['vehicles.view'], 'own');

        // A manager in company A cannot reach a member of company B.
        $this->updateMember($this->manager, $otherMember, ['role' => 'member', 'permissions' => ['vehicles.view', 'orders.create'], 'vehicle_scope' => 'all'])
            ->assertSessionHasErrors('member');
        $this->assertSame(['vehicles.view'], $this->storedPermissions($otherMember, $other));

        // Being owner of company B confers nothing while acting as company A.
        $dual = $this->makeMember($this->company, self::MANAGER_PERMISSIONS, 'all');
        DB::table('user_b2b')->insert([
            'user_id' => $dual->id, 'b2b_id' => $other->b2b_id, 'role' => 'owner', 'permissions' => null,
            'vehicle_scope' => 'all', 'status' => 'active', 'created_at' => now()->addMinute(), 'updated_at' => now(),
        ]);
        $dual->forceFill(['active_b2b_id' => $this->company->b2b_id])->save();

        $this->invite($dual, 'owner-in-a@firma.test', ['preset' => B2bRolePreset::CompanyAdministrator->value, 'vehicle_scope' => 'all'])
            ->assertSessionHasErrors('invitation');
        $this->updateMember($dual, $this->member, ['role' => 'member', 'permissions' => ['vehicles.view', 'offers.select'], 'vehicle_scope' => 'own'])
            ->assertSessionHasErrors('member');

        $this->assertNoInvitation('owner-in-a@firma.test');
        $this->assertStored($this->member, ['vehicles.view'], 'own', 'member');
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateMember(User $actor, User $target, array $payload): TestResponse
    {
        $this->resetRequestState();

        return $this->actingAs($actor)->from(route('b2b.members.index'))
            ->patch(route('b2b.members.update', $target->id), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function invite(User $actor, string $email, array $payload): TestResponse
    {
        $this->resetRequestState();

        return $this->actingAs($actor)->from(route('b2b.members.index'))
            ->post(route('b2b.invitations.store'), [...$payload, 'email' => $email]);
    }

    /**
     * Each call is an independent request: no guard, company context or
     * controller carried over from the previous one.
     */
    private function resetRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function assertStored(User $user, array $permissions, string $scope, string $role): void
    {
        $row = DB::table('user_b2b')->where('user_id', $user->id)->where('b2b_id', $this->company->b2b_id)->sole();

        $this->assertSame($role, $row->role);
        $this->assertSame($scope, $row->vehicle_scope);
        $this->assertEqualsCanonicalizing($permissions, json_decode((string) $row->permissions, true) ?? []);
    }

    private function storedRole(User $user): string
    {
        return (string) DB::table('user_b2b')->where('user_id', $user->id)->where('b2b_id', $this->company->b2b_id)->value('role');
    }

    /**
     * @return list<string>
     */
    private function storedPermissions(User $user, B2B $company): array
    {
        return json_decode((string) DB::table('user_b2b')->where('user_id', $user->id)->where('b2b_id', $company->b2b_id)->value('permissions'), true) ?? [];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function assertInvitation(string $email, array $permissions, string $scope, string $role): void
    {
        $row = DB::table('b2b_invitations')->where('email', $email)->whereNull('revoked_at')->sole();

        $this->assertSame($role, $row->role);
        $this->assertSame($scope, $row->vehicle_scope);
        $this->assertEqualsCanonicalizing($permissions, json_decode((string) $row->permissions, true));
    }

    private function assertNoInvitation(string $email): void
    {
        $this->assertDatabaseMissing('b2b_invitations', ['email' => $email]);
    }
}
