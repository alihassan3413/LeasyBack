<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bPermission;
use App\Enums\B2bRole;
use App\Enums\B2bRolePreset;
use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\B2B\Data\B2bPermissionSet;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The three named company roles, exercised through the endpoints they are
 * meant to open and close.
 *
 * A preset is only a shorthand for a (role, permissions) pair the system
 * already enforced, so these tests deliberately assert against the *routes*
 * rather than the stored JSON: what matters is that a Standard User really
 * cannot approve a quotation, not that a particular string landed in a column.
 */
class B2bRolePresetTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    // ── The presets themselves ───────────────────────────────────────

    public function test_the_administrator_preset_is_an_owner_with_every_permission(): void
    {
        $preset = B2bRolePreset::CompanyAdministrator;

        $this->assertSame(B2bRole::Owner, $preset->role());
        $this->assertSame(B2bPermission::values(), $preset->permissions()->toArray());
    }

    public function test_the_standard_user_preset_can_order_but_not_approve_or_administer(): void
    {
        $permissions = B2bRolePreset::StandardUser->permissions();

        $this->assertSame(B2bRole::Member, B2bRolePreset::StandardUser->role());
        $this->assertTrue($permissions->has(B2bPermission::ViewCompany));
        $this->assertTrue($permissions->has(B2bPermission::ViewVehicles));
        $this->assertTrue($permissions->has(B2bPermission::CreateOrders));
        // b2b.txt §3 "Create orders": add individual vehicles, import vehicles.
        $this->assertTrue($permissions->has(B2bPermission::CreateVehicles));
        $this->assertFalse($permissions->has(B2bPermission::UpdateVehicles));
        $this->assertFalse($permissions->has(B2bPermission::DeleteVehicleDocuments));
        $this->assertFalse($permissions->has(B2bPermission::SelectOffers));
        $this->assertFalse($permissions->has(B2bPermission::ManageMembers));
        $this->assertFalse($permissions->has(B2bPermission::ViewMembers));
    }

    public function test_the_read_only_preset_grants_viewing_and_nothing_else(): void
    {
        $permissions = B2bRolePreset::ReadOnly->permissions();

        $this->assertSame(B2bRole::Member, B2bRolePreset::ReadOnly->role());
        // b2b.txt §3 "Read only": view vehicles/orders and statistics, export.
        $this->assertSame(
            [B2bPermission::ViewVehicles->value, B2bPermission::ViewCompany->value, B2bPermission::ViewAnalytics->value],
            $permissions->toArray(),
        );
        $this->assertFalse($permissions->has(B2bPermission::CreateOrders));
        $this->assertFalse($permissions->has(B2bPermission::CreateVehicles));
        $this->assertFalse($permissions->has(B2bPermission::SelectOffers));
    }

    public function test_standard_user_can_add_and_import_vehicles(): void
    {
        $company = $this->makeCompany();
        $operator = $this->makePresetMember($company, B2bRolePreset::StandardUser);

        // Past the permission gate: validation (302 with errors) rather than 403.
        $this->actingAs($operator)
            ->post(route('vehicles.store'), ['license_plate' => 'A-NEU 1'])
            ->assertSessionHasErrors();

        $this->actingAs($operator)->get(route('vehicles.import.template'))->assertOk();
    }

    public function test_read_only_user_can_view_statistics_and_export(): void
    {
        $company = $this->makeCompany();
        $viewer = $this->makePresetMember($company, B2bRolePreset::ReadOnly);

        $this->actingAs($viewer)->get(route('b2b.statistics.index'))->assertOk();
        $this->actingAs($viewer)->get(route('b2b.statistics.export'))->assertOk();
    }

    /** Every preset must survive B2bPermissionSet's dependency closure unchanged. */
    public function test_preset_permission_sets_are_already_normalised(): void
    {
        foreach (B2bRolePreset::cases() as $preset) {
            $this->assertSame(
                $preset->permissions()->toArray(),
                B2bPermissionSet::fromRaw($preset->permissions()->toArray())->toArray(),
                "{$preset->value} stores a set that normalisation would change",
            );
        }
    }

    public function test_a_stored_membership_is_matched_back_to_its_preset(): void
    {
        foreach (B2bRolePreset::cases() as $preset) {
            $this->assertSame($preset, B2bRolePreset::match($preset->role(), $preset->permissions()));
        }
    }

    public function test_a_hand_picked_permission_set_matches_no_preset(): void
    {
        $custom = B2bPermissionSet::fromRaw([
            B2bPermission::ViewVehicles->value,
            B2bPermission::DeleteVehicleDocuments->value,
        ]);

        $this->assertNull(B2bRolePreset::match(B2bRole::Member, $custom));
    }

    // ── Company Administrator ────────────────────────────────────────

    public function test_company_administrator_can_create_orders_approve_quotations_and_manage_members(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeOwner($company);

        $this->assertCanCreateOrder($admin, $company);
        $this->assertCanApproveQuotation($admin, $company);

        $this->actingAs($admin)->get(route('b2b.members.index'))->assertOk();

        Mail::fake();
        $this->actingAs($admin)
            ->post(route('b2b.invitations.store'), [
                'email' => 'neu@firma.de',
                'preset' => B2bRolePreset::StandardUser->value,
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('b2b_invitations', ['email' => 'neu@firma.de', 'role' => 'member']);
    }

    // ── Standard User / Operator ─────────────────────────────────────

    public function test_standard_user_can_create_orders(): void
    {
        $company = $this->makeCompany();
        $operator = $this->makePresetMember($company, B2bRolePreset::StandardUser);

        $this->assertCanCreateOrder($operator, $company);
    }

    public function test_standard_user_cannot_approve_a_quotation(): void
    {
        $company = $this->makeCompany();
        $operator = $this->makePresetMember($company, B2bRolePreset::StandardUser);
        $offer = $this->publishedOffer($company);

        $this->actingAs($operator)->post(route('offers.select', $offer->offer_id))->assertForbidden();
        $this->actingAs($operator)->post(route('offers.reject', $offer->offer_id))->assertForbidden();
    }

    public function test_standard_user_cannot_invite_or_manage_members(): void
    {
        $company = $this->makeCompany();
        $operator = $this->makePresetMember($company, B2bRolePreset::StandardUser);
        $colleague = $this->makePresetMember($company, B2bRolePreset::ReadOnly);

        $this->actingAs($operator)->get(route('b2b.members.index'))->assertForbidden();
        $this->actingAs($operator)
            ->post(route('b2b.invitations.store'), [
                'email' => 'x@firma.de',
                'preset' => B2bRolePreset::ReadOnly->value,
                'vehicle_scope' => 'all',
            ])
            ->assertForbidden();
        $this->actingAs($operator)
            ->patch(route('b2b.members.update', $colleague->id), [
                'preset' => B2bRolePreset::CompanyAdministrator->value,
                'vehicle_scope' => 'all',
            ])
            ->assertForbidden();
        $this->actingAs($operator)->delete(route('b2b.members.destroy', $colleague->id))->assertForbidden();
    }

    public function test_standard_user_can_view_the_fleet(): void
    {
        $company = $this->makeCompany();
        $operator = $this->makePresetMember($company, B2bRolePreset::StandardUser);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-AA 1111']);

        $this->actingAs($operator)->get(route('vehicles.index'))->assertOk();
    }

    // ── Read-only ────────────────────────────────────────────────────

    public function test_read_only_user_can_view_vehicles_and_orders(): void
    {
        $company = $this->makeCompany();
        $viewer = $this->makePresetMember($company, B2bRolePreset::ReadOnly);
        $this->makeB2bOrder($this->makeB2bVehicle($company, ['license_plate' => 'A-AA 1111']));

        $this->actingAs($viewer)->get(route('dashboard'))->assertOk();
        $this->actingAs($viewer)->get(route('vehicles.index'))->assertOk();
        $this->actingAs($viewer)->get(route('orders.index'))->assertOk();
    }

    public function test_read_only_user_cannot_create_orders_or_vehicles(): void
    {
        $company = $this->makeCompany();
        $viewer = $this->makePresetMember($company, B2bRolePreset::ReadOnly);
        $vehicle = $this->makeB2bVehicle($company);

        $this->actingAs($viewer)
            ->post(route('orders.store', $vehicle->vehicle_id), [])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('vehicles.store'), ['license_plate' => 'A-NEU 1'])
            ->assertForbidden();
    }

    public function test_read_only_user_cannot_approve_a_quotation(): void
    {
        $company = $this->makeCompany();
        $viewer = $this->makePresetMember($company, B2bRolePreset::ReadOnly);
        $offer = $this->publishedOffer($company);

        $this->actingAs($viewer)->post(route('offers.select', $offer->offer_id))->assertForbidden();
    }

    public function test_read_only_user_cannot_invite_users(): void
    {
        $company = $this->makeCompany();
        $viewer = $this->makePresetMember($company, B2bRolePreset::ReadOnly);

        $this->actingAs($viewer)
            ->post(route('b2b.invitations.store'), [
                'email' => 'x@firma.de',
                'preset' => B2bRolePreset::StandardUser->value,
                'vehicle_scope' => 'all',
            ])
            ->assertForbidden();
    }

    // ── The preset travelling through the endpoints ──────────────────

    public function test_updating_a_member_by_preset_stores_that_presets_rights(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $member = $this->makePresetMember($company, B2bRolePreset::ReadOnly);

        $this->actingAs($owner)
            ->patch(route('b2b.members.update', $member->id), [
                'preset' => B2bRolePreset::StandardUser->value,
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            B2bRolePreset::StandardUser->permissions()->toArray(),
            $this->storedPermissions($company->b2b_id, $member->id),
        );
    }

    /** The advanced editor still posts role + permissions and still works. */
    public function test_a_custom_permission_set_is_still_accepted_without_a_preset(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $member = $this->makePresetMember($company, B2bRolePreset::ReadOnly);

        $this->actingAs($owner)
            ->patch(route('b2b.members.update', $member->id), [
                'role' => 'member',
                'permissions' => [B2bPermission::ViewVehicles->value, B2bPermission::UploadVehicleDocuments->value],
                'vehicle_scope' => 'own',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [B2bPermission::ViewVehicles->value, B2bPermission::UploadVehicleDocuments->value],
            $this->storedPermissions($company->b2b_id, $member->id),
        );
    }

    public function test_an_unknown_preset_is_rejected(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $member = $this->makePresetMember($company, B2bRolePreset::ReadOnly);

        $this->actingAs($owner)
            ->patch(route('b2b.members.update', $member->id), [
                'preset' => 'super_admin',
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasErrors('preset');
    }

    /** Only an owner may hand out ownership — the administrator preset included. */
    public function test_a_non_owner_manager_cannot_assign_the_administrator_preset(): void
    {
        $company = $this->makeCompany();
        $manager = $this->makeMember($company, [
            B2bPermission::ViewMembers->value,
            B2bPermission::ManageMembers->value,
        ]);
        $member = $this->makePresetMember($company, B2bRolePreset::ReadOnly);

        $this->actingAs($manager)
            ->patch(route('b2b.members.update', $member->id), [
                'preset' => B2bRolePreset::CompanyAdministrator->value,
                'vehicle_scope' => 'all',
            ])
            ->assertSessionHasErrors('member');

        $this->assertSame(
            B2bRolePreset::ReadOnly->permissions()->toArray(),
            $this->storedPermissions($company->b2b_id, $member->id),
        );
    }

    public function test_the_members_page_carries_the_preset_catalogue_and_each_members_role_name(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $this->makePresetMember($company, B2bRolePreset::StandardUser);

        $response = $this->actingAs($owner)->get(route('b2b.members.index'))->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(count(B2bRolePreset::cases()), $props['rolePresets']);
        $this->assertSame(B2bRolePreset::default()->value, $props['defaultPreset']);

        $presets = array_column($props['members'], 'preset');
        $this->assertContains(B2bRolePreset::CompanyAdministrator->value, $presets);
        $this->assertContains(B2bRolePreset::StandardUser->value, $presets);
    }

    public function test_a_hand_picked_member_is_labelled_individuell(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $this->makeMember($company, [
            B2bPermission::ViewVehicles->value,
            B2bPermission::DeleteVehicleDocuments->value,
        ]);

        $props = $this->actingAs($owner)->get(route('b2b.members.index'))->viewData('page')['props'];
        $custom = collect($props['members'])->firstWhere('preset', null);

        $this->assertNotNull($custom);
        $this->assertSame(B2bRolePreset::customLabel(), $custom['preset_label']);
    }

    /**
     * A Privatkunde who joined a company still has `user_type = Privatkunde`
     * while acting as that company — the membership is what changes, not the
     * column. Both the fleet and the orders have to be reachable for them, and
     * the shared props have to say "acting as a company" so the navigation is
     * built for one. Keying the nav off the raw column instead gave them the
     * two-entry private menu, with the company's own pages unreachable.
     */
    public function test_a_private_account_acting_as_a_company_reaches_the_company_pages(): void
    {
        $company = $this->makeCompany();
        $viewer = $this->makeMember($company, B2bRolePreset::ReadOnly->permissions()->toArray());
        $viewer->forceFill(['user_type' => UserType::Privatkunde])->save();

        // A dual-context account defaults to its private side, so put them in
        // the company — which is the state the navigation got wrong.
        app(B2bContext::class)->switchTo($viewer->fresh(), $company->b2b_id);

        $this->makeB2bOrder($this->makeB2bVehicle($company, ['license_plate' => 'A-AA 1111']));

        $props = $this->actingAs($viewer->fresh())->get(route('vehicles.index'))->assertOk()->viewData('page')['props'];

        // The raw column still says private...
        $this->assertSame(UserType::Privatkunde, $props['auth']['user']['user_type']);
        // ...while the membership says the navigation must be the company one.
        $this->assertNotNull($props['auth']['b2b']['active']);
        $this->assertCount(1, $props['vehicles']);

        $this->actingAs($viewer->fresh())
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders', 1));
    }

    // ── The props the UI gates on ────────────────────────────────────

    /**
     * Every frontend gate is `useB2bPermissions().can(...)`, which reads
     * `auth.b2b.active.permissions` off the shared Inertia props. There is no
     * JS test runner in this project, so the contract is pinned here: if this
     * array is right, every `v-if` built on it is right.
     *
     * @return array<string, array{0: B2bRolePreset, 1: array<string>, 2: array<string>}>
     */
    public static function presetGateProvider(): array
    {
        return [
            'administrator sees every action' => [
                B2bRolePreset::CompanyAdministrator,
                [
                    B2bPermission::CreateOrders->value,
                    B2bPermission::SelectOffers->value,
                    B2bPermission::UpdateVehicles->value,
                    B2bPermission::UploadVehicleDocuments->value,
                    B2bPermission::DeleteVehicleDocuments->value,
                    B2bPermission::ManageMembers->value,
                    B2bPermission::ViewMembers->value,
                ],
                [],
            ],
            'standard user orders but does not approve or administer' => [
                B2bRolePreset::StandardUser,
                [B2bPermission::CreateOrders->value],
                [
                    B2bPermission::SelectOffers->value,
                    B2bPermission::ManageMembers->value,
                    B2bPermission::ViewMembers->value,
                    B2bPermission::UpdateVehicles->value,
                    B2bPermission::DeleteVehicleDocuments->value,
                ],
            ],
            'read-only sees view actions only' => [
                B2bRolePreset::ReadOnly,
                [B2bPermission::ViewVehicles->value],
                [
                    B2bPermission::CreateOrders->value,
                    B2bPermission::SelectOffers->value,
                    B2bPermission::CreateVehicles->value,
                    B2bPermission::UpdateVehicles->value,
                    B2bPermission::UploadVehicleDocuments->value,
                    B2bPermission::DeleteVehicleDocuments->value,
                    B2bPermission::ManageMembers->value,
                ],
            ],
        ];
    }

    /**
     * @param  array<string>  $granted
     * @param  array<string>  $denied
     */
    #[DataProvider('presetGateProvider')]
    public function test_the_shared_props_carry_exactly_what_the_ui_should_offer(
        B2bRolePreset $preset,
        array $granted,
        array $denied,
    ): void {
        $company = $this->makeCompany();
        $user = $this->makePresetMember($company, $preset);

        $props = $this->actingAs($user)->get(route('dashboard'))->viewData('page')['props'];
        $permissions = $props['auth']['b2b']['active']['permissions'];

        foreach ($granted as $permission) {
            $this->assertContains($permission, $permissions, "{$preset->value} should be offered {$permission}");
        }

        foreach ($denied as $permission) {
            $this->assertNotContains($permission, $permissions, "{$preset->value} must not be offered {$permission}");
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makePresetMember(object $company, B2bRolePreset $preset): User
    {
        return $preset->role() === B2bRole::Owner
            ? $this->makeOwner($company)
            : $this->makeMember($company, $preset->permissions()->toArray());
    }

    /**
     * @return array<string>
     */
    private function storedPermissions(string $b2bId, int $userId): array
    {
        $row = DB::table('user_b2b')->where('b2b_id', $b2bId)->where('user_id', $userId)->first();

        return B2bPermissionSet::fromRaw(json_decode((string) $row->permissions, true) ?: [])->toArray();
    }

    /**
     * A published offer on a company order — the thing "approve quotation"
     * acts on. Typed as the canonical model, not the App\Models shim: the
     * factory returns the parent class (see BuildsB2bCompanies::shimVehicle).
     */
    private function publishedOffer(object $company): LeasybackOffer
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), OrderStatus::Inspected->value);

        return LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'published',
        ]);
    }

    private function assertCanCreateOrder(User $user, object $company): void
    {
        $vehicle = $this->makeB2bVehicle($company);

        // The permission gate is the middleware; the payload is validated
        // after it, so a 422 still proves the caller was let through and a
        // 403 proves they were not.
        $response = $this->actingAs($user)->post(route('orders.store', $vehicle->vehicle_id), []);

        $this->assertNotSame(403, $response->getStatusCode(), 'orders.create should have been granted');
    }

    private function assertCanApproveQuotation(User $user, object $company): void
    {
        $offer = $this->publishedOffer($company);

        $response = $this->actingAs($user)->post(route('offers.select', $offer->offer_id));

        $this->assertNotSame(403, $response->getStatusCode(), 'offers.select should have been granted');
    }
}
