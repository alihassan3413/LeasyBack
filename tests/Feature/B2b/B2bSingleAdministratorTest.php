<?php

namespace Tests\Feature\B2b;

use App\Enums\UserType;
use App\Models\B2B;
use App\Models\User;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use App\Modules\UserProfile\B2B\Models\B2bInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Exactly one Company Administrator (B2bRole::Owner) per company.
 *
 * The invite and edit paths already refuse a second one; these tests pin the
 * two paths that used to write the role without asking — accepting a stale
 * owner invitation and reactivating an owner membership — and hold the status
 * endpoint to the same authority rules as every other member mutation.
 */
class B2bSingleAdministratorTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    /** What the manager in this test holds. */
    private const MANAGER_PERMISSIONS = ['vehicles.view', 'orders.create', 'members.view', 'members.manage'];

    public function test_accepting_a_stale_owner_invitation_is_refused_when_the_company_already_has_an_administrator(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $invitee = User::factory()->create(['user_type' => UserType::Firmenkunde, 'email' => 'kandidat@firma.test']);

        // An owner invitation that predates the single-administrator guard —
        // still pending, still accepted by the endpoint as-is.
        $token = Str::random(64);
        B2bInvitation::create([
            'b2b_id' => $company->b2b_id,
            'email' => 'kandidat@firma.test',
            'role' => 'owner',
            'permissions' => B2bPermissionSet::all()->toArray(),
            'vehicle_scope' => 'all',
            'token_hash' => hash('sha256', $token),
            'invited_by_user_id' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($invitee)
            ->post(route('b2b.invitations.accept', $token))
            ->assertSessionHasErrors('invitation');

        $this->assertDatabaseMissing('user_b2b', ['user_id' => $invitee->id]);
        $this->assertSame(1, $this->activeOwnerCount($company));
        $this->assertNull(
            B2bInvitation::where('token_hash', hash('sha256', $token))->firstOrFail()->accepted_at
        );
    }

    public function test_an_owner_membership_cannot_be_reactivated_while_another_administrator_is_active(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $inactiveOwner = $this->makeOwner($company);
        $this->setStatus($company, $inactiveOwner, 'inactive');

        $this->actingAs($owner)->from(route('b2b.members.index'))
            ->patch(route('b2b.members.status', $inactiveOwner->id), ['status' => 'active'])
            ->assertSessionHasErrors('member');

        $this->assertSame('inactive', $this->storedStatus($company, $inactiveOwner));
        $this->assertSame(1, $this->activeOwnerCount($company));
    }

    public function test_a_manager_cannot_change_an_owners_status(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $manager = $this->makeMember($company, self::MANAGER_PERMISSIONS, 'all');

        $this->actingAs($manager)->from(route('b2b.members.index'))
            ->patch(route('b2b.members.status', $owner->id), ['status' => 'inactive'])
            ->assertSessionHasErrors('member');

        $this->assertSame('active', $this->storedStatus($company, $owner));
    }

    public function test_a_manager_can_still_activate_a_member_within_their_authority(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $this->makeOwner($company);
        $manager = $this->makeMember($company, self::MANAGER_PERMISSIONS, 'all');
        $member = $this->makeMember($company, ['vehicles.view'], 'own');

        $this->setStatus($company, $member, 'inactive');

        $this->actingAs($manager)->from(route('b2b.members.index'))
            ->patch(route('b2b.members.status', $member->id), ['status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertSame('active', $this->storedStatus($company, $member));
    }

    private function setStatus(B2B $company, User $member, string $status): void
    {
        DB::table('user_b2b')
            ->where('b2b_id', $company->b2b_id)
            ->where('user_id', $member->id)
            ->update(['status' => $status]);
    }

    private function storedStatus(B2B $company, User $member): ?string
    {
        return DB::table('user_b2b')
            ->where('b2b_id', $company->b2b_id)
            ->where('user_id', $member->id)
            ->value('status');
    }

    private function activeOwnerCount(B2B $company): int
    {
        return (int) DB::table('user_b2b')
            ->where('b2b_id', $company->b2b_id)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->count();
    }
}
