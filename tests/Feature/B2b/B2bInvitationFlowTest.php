<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Models\B2bInvitation;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Notifications\B2bInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The invitee's half of the B2B team flow, with the case that used to be a
 * dead end at its centre: the invited address already belongs to a private
 * (B2C) account.
 *
 * That account must be reused rather than duplicated, must keep everything it
 * already owns, and must end up able to act as *both* — which is why most of
 * these tests assert on what survives the join, not only on the membership row
 * that gets written.
 */
class B2bInvitationFlowTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    /**
     * Issue a real invitation the way the owner's UI does, and return the
     * plaintext token from the notification — the only place it ever exists.
     */
    private function inviteAndCaptureToken(User $owner, string $email, array $payload = []): string
    {
        Notification::fake();

        $this->actingAs($owner)
            ->post(route('b2b.invitations.store'), [
                'email' => $email,
                'role' => 'member',
                'permissions' => [B2bPermission::ViewVehicles->value],
                'vehicle_scope' => 'all',
                ...$payload,
            ])
            ->assertSessionHasNoErrors();

        $token = null;

        Notification::assertSentOnDemand(
            B2bInvitationNotification::class,
            function (B2bInvitationNotification $notification) use (&$token) {
                $url = (new \ReflectionProperty($notification, 'acceptUrl'))->getValue($notification);
                $token = Str::afterLast($url, '/');

                return true;
            }
        );

        $this->assertNotNull($token, 'No invitation token was emailed.');

        return $token;
    }

    private function makePrivateCustomer(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'user_type' => UserType::Privatkunde,
        ]);
    }

    // ---------------------------------------------------------------- invite

    public function test_inviting_an_unknown_email_creates_a_pending_invitation(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);

        $this->inviteAndCaptureToken($owner, 'neu@example.com');

        $invitation = B2bInvitation::where('email', 'neu@example.com')->firstOrFail();

        $this->assertSame($company->b2b_id, $invitation->b2b_id);
        $this->assertSame('pending', $invitation->status());
        $this->assertSame($owner->id, $invitation->invited_by_user_id);
        // The plaintext token is never persisted.
        $this->assertNotEmpty($invitation->token_hash);
    }

    public function test_inviting_an_existing_b2c_user_does_not_create_a_second_account(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $existing = $this->makePrivateCustomer('privat@example.com');

        $this->inviteAndCaptureToken($owner, 'privat@example.com');

        $this->assertSame(1, User::where('email', 'privat@example.com')->count());
        $this->assertSame($existing->id, User::where('email', 'privat@example.com')->value('id'));
    }

    public function test_reinviting_the_same_address_replaces_the_open_invitation(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);

        $firstToken = $this->inviteAndCaptureToken($owner, 'doppelt@example.com');
        $this->inviteAndCaptureToken($owner, 'doppelt@example.com');

        // Exactly one usable invitation, and the superseded link is dead.
        $this->assertSame(1, B2bInvitation::where('email', 'doppelt@example.com')
            ->whereNull('accepted_at')->whereNull('revoked_at')->count());

        $this->get(route('b2b.invitations.show', $firstToken))
            ->assertInertia(fn ($page) => $page->where('status', 'revoked')->where('invitation', null));
    }

    public function test_inviting_someone_who_is_already_a_member_is_refused(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $member = $this->makeMember($company, [B2bPermission::ViewVehicles->value]);

        $this->actingAs($owner)
            ->post(route('b2b.invitations.store'), [
                'email' => $member->email,
                'role' => 'member',
                'permissions' => [B2bPermission::ViewVehicles->value],
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasErrors('invitation');

        $this->assertSame(0, B2bInvitation::where('email', $member->email)->count());
    }

    // ---------------------------------------------------------------- accept

    public function test_existing_b2c_user_joins_the_company_without_a_duplicate_account(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $existing = $this->makePrivateCustomer('privat@example.com');

        $token = $this->inviteAndCaptureToken($owner, 'privat@example.com');

        $this->actingAs($existing)
            ->post(route('b2b.invitations.accept', $token))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::where('email', 'privat@example.com')->count());
        $this->assertDatabaseHas('user_b2b', [
            'user_id' => $existing->id,
            'b2b_id' => $company->b2b_id,
            'status' => 'active',
        ]);
        // user_type is deliberately untouched — the account keeps its private side.
        $this->assertSame(UserType::Privatkunde, $existing->fresh()->user_type);
        $this->assertSame('accepted', B2bInvitation::where('email', 'privat@example.com')->firstOrFail()->status());
    }

    public function test_existing_b2c_user_keeps_their_own_vehicles_after_joining(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $existing = $this->makePrivateCustomer('privat@example.com');

        $ownVehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $existing->id,
            'b2b_id' => null,
            'license_plate' => 'P-RI 1000',
        ]);

        $token = $this->inviteAndCaptureToken($owner, 'privat@example.com');
        $this->actingAs($existing)->post(route('b2b.invitations.accept', $token));

        // The vehicle row is untouched...
        $this->assertDatabaseHas('vehicles', [
            'vehicle_id' => $ownVehicle->vehicle_id,
            'b2c_user_id' => $existing->id,
            'vehicle_belongs' => 'B2C',
        ]);

        // ...and is still reachable once they switch back to their private area.
        $this->actingAs($existing->fresh())
            ->post(route('b2b.switch'), ['b2b_id' => null])
            ->assertRedirect(route('dashboard'));

        $plates = collect($this->actingAs($existing->fresh())->get(route('dashboard'))
            ->viewData('page')['props']['vehicles'])->pluck('license_plate');

        $this->assertContains('P-RI 1000', $plates);
    }

    public function test_dual_context_user_sees_the_company_fleet_while_acting_as_the_company(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $existing = $this->makePrivateCustomer('privat@example.com');

        Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $existing->id,
            'b2b_id' => null,
            'license_plate' => 'P-RI 1000',
        ]);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-AA 1111']);

        $token = $this->inviteAndCaptureToken($owner, 'privat@example.com');
        $this->actingAs($existing)->post(route('b2b.invitations.accept', $token));

        // Accepting lands them in the company they just joined.
        $plates = collect($this->actingAs($existing->fresh())->get(route('dashboard'))
            ->viewData('page')['props']['vehicles'])->pluck('license_plate');

        $this->assertContains('A-AA 1111', $plates);
        $this->assertNotContains('P-RI 1000', $plates, 'Private vehicles must not bleed into company context.');
    }

    public function test_logged_in_with_a_different_email_is_blocked(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $other = $this->makePrivateCustomer('jemand.anderes@example.com');

        $token = $this->inviteAndCaptureToken($owner, 'eingeladen@example.com');

        $this->actingAs($other)
            ->post(route('b2b.invitations.accept', $token))
            ->assertSessionHasErrors('invitation');

        $this->assertDatabaseMissing('user_b2b', [
            'user_id' => $other->id,
            'b2b_id' => $company->b2b_id,
        ]);
        $this->assertSame('pending', B2bInvitation::where('email', 'eingeladen@example.com')->firstOrFail()->status());
    }

    public function test_show_page_explains_the_mismatch_before_the_click(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $other = $this->makePrivateCustomer('jemand.anderes@example.com');

        $token = $this->inviteAndCaptureToken($owner, 'eingeladen@example.com');

        $this->actingAs($other)
            ->get(route('b2b.invitations.show', $token))
            ->assertInertia(fn ($page) => $page
                ->where('viewer.email_matches', false)
                ->where('viewer.email', 'jemand.anderes@example.com')
            );
    }

    public function test_accepting_twice_does_not_create_a_second_membership(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $existing = $this->makePrivateCustomer('privat@example.com');

        $token = $this->inviteAndCaptureToken($owner, 'privat@example.com');

        $this->actingAs($existing)->post(route('b2b.invitations.accept', $token));
        $this->actingAs($existing->fresh())
            ->post(route('b2b.invitations.accept', $token))
            ->assertSessionHasErrors('invitation');

        $this->assertSame(1, DB::table('user_b2b')
            ->where('user_id', $existing->id)
            ->where('b2b_id', $company->b2b_id)
            ->count());
    }

    public function test_expired_invitation_is_reported_as_expired(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $existing = $this->makePrivateCustomer('privat@example.com');

        $token = $this->inviteAndCaptureToken($owner, 'privat@example.com');
        B2bInvitation::where('email', 'privat@example.com')->update(['expires_at' => now()->subDay()]);

        $this->get(route('b2b.invitations.show', $token))
            ->assertInertia(fn ($page) => $page->where('status', 'expired')->where('invitation', null));

        $this->actingAs($existing)
            ->post(route('b2b.invitations.accept', $token))
            ->assertSessionHasErrors('invitation');

        $this->assertDatabaseMissing('user_b2b', [
            'user_id' => $existing->id,
            'b2b_id' => $company->b2b_id,
        ]);
    }

    public function test_revoked_invitation_cannot_be_accepted(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $existing = $this->makePrivateCustomer('privat@example.com');

        $token = $this->inviteAndCaptureToken($owner, 'privat@example.com');
        $invitation = B2bInvitation::where('email', 'privat@example.com')->firstOrFail();

        $this->actingAs($owner)
            ->delete(route('b2b.invitations.revoke', $invitation->invitation_id))
            ->assertSessionHasNoErrors();

        $this->get(route('b2b.invitations.show', $token))
            ->assertInertia(fn ($page) => $page->where('status', 'revoked')->where('invitation', null));

        $this->actingAs($existing)
            ->post(route('b2b.invitations.accept', $token))
            ->assertSessionHasErrors('invitation');

        $this->assertDatabaseMissing('user_b2b', [
            'user_id' => $existing->id,
            'b2b_id' => $company->b2b_id,
        ]);
    }

    public function test_unknown_token_is_reported_as_invalid(): void
    {
        $this->get(route('b2b.invitations.show', Str::random(64)))
            ->assertInertia(fn ($page) => $page->where('status', 'invalid')->where('invitation', null));
    }

    // ------------------------------------------------------------- isolation

    public function test_joining_one_company_grants_no_access_to_another(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');
        $existing = $this->makePrivateCustomer('privat@example.com');

        $foreign = $this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']);
        $this->makeB2bVehicle($alpha, ['license_plate' => 'A-AA 1111']);

        $token = $this->inviteAndCaptureToken($this->makeOwner($alpha), 'privat@example.com');
        $this->actingAs($existing)->post(route('b2b.invitations.accept', $token));

        $this->actingAs($existing->fresh())
            ->get(route('vehicles.show', $foreign->vehicle_id))
            ->assertNotFound();

        $plates = collect($this->actingAs($existing->fresh())->get(route('dashboard'))
            ->viewData('page')['props']['vehicles'])->pluck('license_plate');

        $this->assertNotContains('B-BB 2222', $plates);
    }

    public function test_switching_to_a_company_the_user_does_not_belong_to_is_refused(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');
        $existing = $this->makePrivateCustomer('privat@example.com');

        $token = $this->inviteAndCaptureToken($this->makeOwner($alpha), 'privat@example.com');
        $this->actingAs($existing)->post(route('b2b.invitations.accept', $token));

        $this->actingAs($existing->fresh())
            ->post(route('b2b.switch'), ['b2b_id' => $beta->b2b_id])
            ->assertSessionHasErrors('b2b_id');

        $this->assertSame($alpha->b2b_id, $existing->fresh()->active_b2b_id);
    }

    public function test_a_firmenkunde_cannot_switch_to_a_private_area(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);

        $this->actingAs($owner)
            ->post(route('b2b.switch'), ['b2b_id' => null])
            ->assertSessionHasErrors('b2b_id');

        $this->assertSame($company->b2b_id, $owner->fresh()->active_b2b_id);
    }

    public function test_dual_context_member_is_held_to_their_granted_permissions(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $existing = $this->makePrivateCustomer('privat@example.com');

        // Invited with view-only access: the members page must stay closed.
        $token = $this->inviteAndCaptureToken($owner, 'privat@example.com');
        $this->actingAs($existing)->post(route('b2b.invitations.accept', $token));

        $this->actingAs($existing->fresh())
            ->get(route('b2b.members.index'))
            ->assertForbidden();
    }

    public function test_context_resolution_reports_the_right_side_for_a_dual_context_user(): void
    {
        $company = $this->makeCompany('Alpha GmbH');
        $owner = $this->makeOwner($company);
        $existing = $this->makePrivateCustomer('privat@example.com');

        $token = $this->inviteAndCaptureToken($owner, 'privat@example.com');
        $this->actingAs($existing)->post(route('b2b.invitations.accept', $token));

        $context = app(B2bContext::class);
        $joined = $existing->fresh();

        $context->forget($joined);
        $this->assertTrue($context->actsAsCompany($joined));
        $this->assertSame(UserType::Firmenkunde, $context->effectiveUserType($joined));

        $context->switchToPersonal($joined);
        $this->assertFalse($context->actsAsCompany($joined));
        $this->assertSame(UserType::Privatkunde, $context->effectiveUserType($joined));
        $this->assertNull($joined->fresh()->active_b2b_id);
    }
}
