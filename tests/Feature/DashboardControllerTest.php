<?php

namespace Tests\Feature;

use App\Enums\B2bPermission;
use App\Enums\B2bRolePreset;
use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The landing page: the service catalogue and the vehicle picker each service
 * is booked through. The fleet it books against is `vehicles.index`.
 */
class DashboardControllerTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    public function test_unauthenticated_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_company_gets_the_service_catalogue(): void
    {
        $this->actingAs($this->makeOwner($this->makeCompany()))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('b2b/Dashboard')
                ->has('bookableVehicles')
                ->has('stations')
            );
    }

    /**
     * The point of the split: a Privatkunde's dashboard is their vehicle list
     * and stays exactly that. No catalogue, no picker, same props as before.
     */
    public function test_a_privatkunde_still_gets_their_fleet(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'license_plate' => 'K LB 1']);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('vehicles', 1)
                ->where('vehicles.0.license_plate', 'K LB 1')
                ->has('pagination')
                ->has('filters')
                ->has('memberOptions')
                ->missing('bookableVehicles')
            );
    }

    /** The company pages are not offered to a Privatkunde, who is sent home. */
    public function test_a_privatkunde_is_redirected_off_the_company_pages(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($owner)->get(route('vehicles.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($owner)->get(route('orders.index'))->assertRedirect(route('dashboard'));
    }

    /**
     * The picker offers a vehicle a new order can actually be placed for.
     * Anything else would open a booking form the server refuses on submit.
     */
    public function test_the_picker_only_offers_vehicles_without_a_running_order(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);

        $this->makeB2bVehicle($company, ['license_plate' => 'K LB 1']);

        $busy = $this->makeB2bVehicle($company, ['license_plate' => 'K LB 2']);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $busy->vehicle_id,
            'order_status' => OrderStatus::Confirmed->value,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('bookableVehicles', 1)
                ->where('bookableVehicles.0.license_plate', 'K LB 1')
            );
    }

    /**
     * A cancelled order leaves the vehicle exactly as it found it, so it stays
     * bookable — the same rule OrderStatus::reorderableValues() states and
     * VehicleService::blocksNewOrder() enforces on submit.
     */
    public function test_a_cancelled_order_leaves_its_vehicle_bookable(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->makeB2bVehicle($company, ['license_plate' => 'K LB 3']);

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Cancelled->value,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('bookableVehicles', 1)
                ->where('bookableVehicles.0.license_plate', 'K LB 3')
            );
    }

    /** A completed return is the one closed state that keeps its vehicle. */
    public function test_a_completed_order_keeps_its_vehicle_out_of_the_picker(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->makeB2bVehicle($company);

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Completed->value,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('bookableVehicles', 0));
    }

    public function test_the_picker_never_reaches_across_companies(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $this->makeB2bVehicle($alpha, ['license_plate' => 'A-AA 1111']);
        $this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']);

        $this->actingAs($this->makeOwner($alpha))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('bookableVehicles', 1)
                ->where('bookableVehicles.0.license_plate', 'A-AA 1111')
            );
    }

    /** A member restricted to their own vehicles books against those only. */
    public function test_an_own_scope_member_only_sees_their_own_vehicles_in_the_picker(): void
    {
        $company = $this->makeCompany();
        $scoped = $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::CreateOrders->value], 'own');

        $this->makeB2bVehicle($company, ['license_plate' => 'A-MINE 1', 'created_by_user_id' => $scoped->id]);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-THEIRS 2']);

        $this->actingAs($scoped)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('bookableVehicles', 1)
                ->where('bookableVehicles.0.license_plate', 'A-MINE 1')
            );
    }

    // ── The company overview ─────────────────────────────────────────

    public function test_the_dashboard_carries_the_companys_key_figures(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-AA 1111']);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('analytics.totals')
                ->where('analytics.totals.vehicles', 1)
                ->has('analytics.states')
            );
    }

    public function test_the_overview_carries_every_kpi_the_dashboard_renders(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $this->makeB2bOrder($this->makeB2bVehicle($company), OrderStatus::Confirmed->value);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Vehicles count.
                ->where('analytics.totals.vehicles', 1)
                // Active / completed orders.
                ->where('statistics.orders.active', 1)
                ->has('statistics.orders.completed')
                ->has('statistics.orders.total')
                // Average processing time.
                ->has('statistics.processing_time.average_days')
                ->has('statistics.processing_time.measured_orders')
                // Savings.
                ->has('statistics.savings.saving_percentage')
                ->has('statistics.savings.saving_total_net')
                ->has('statistics.savings.orders_counted')
                // Whose figures these are.
                ->where('statistics.scope.company_wide', true)
            );
    }

    /**
     * The company overview is for whoever may see the company's numbers.
     * `analytics.view` is that permission, an owner holds it implicitly, and
     * the Standard User preset does not grant it — nor does a hand-picked
     * view-only set. Both get the service catalogue alone. (The Read-only
     * preset *does* grant it since b2b.txt §3 lets that role see statistics.)
     */
    public function test_members_without_analytics_access_get_no_company_overview(): void
    {
        $company = $this->makeCompany();
        $this->makeB2bOrder($this->makeB2bVehicle($company));

        $viewOnly = [B2bPermission::ViewVehicles->value, B2bPermission::ViewCompany->value];

        foreach ([B2bRolePreset::StandardUser->permissions()->toArray(), $viewOnly] as $permissions) {
            $member = $this->makeMember($company, $permissions);

            $this->actingAs($member)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('statistics', null)
                    ->where('analytics', null)
                    ->has('recentOrders', 0)
                    // The catalogue is shared by every role, so its data stays.
                    ->has('bookableVehicles'),
                );
        }
    }

    // ── The member's operating dashboard ─────────────────────────────

    public function test_a_member_gets_their_own_operating_overview(): void
    {
        $company = $this->makeCompany();
        $operator = $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray());

        // One vehicle already in a process, one still free.
        $busy = $this->makeB2bVehicle($company, ['license_plate' => 'A-BUSY 1']);
        $this->makeB2bOrder($busy, OrderStatus::Confirmed->value);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-FREI 2']);

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('myOverview.vehicles', 2)
                ->where('myOverview.active_orders', 1)
                ->where('myOverview.bookable_vehicles', 1)
                ->has('myVehicles', 2)
                // Still no company-level figures.
                ->where('statistics', null)
                ->where('analytics', null)
            );
    }

    /** The preview is a preview; the fleet page is the list. */
    public function test_the_member_vehicle_preview_is_capped(): void
    {
        $company = $this->makeCompany();
        $operator = $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray());

        foreach (range(1, 8) as $index) {
            $this->makeB2bVehicle($company, ['license_plate' => "A-XX {$index}"]);
        }

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('myVehicles', 5)
                // The count is the real total, not the preview length.
                ->where('myOverview.vehicles', 8)
            );
    }

    /** An own-scope member counts their own vehicles and nobody else's. */
    public function test_the_member_overview_respects_the_vehicle_scope(): void
    {
        $company = $this->makeCompany();
        $scoped = $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray(), 'own');

        $this->makeB2bVehicle($company, ['license_plate' => 'A-MINE 1', 'created_by_user_id' => $scoped->id]);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-THEIRS 2']);

        $this->actingAs($scoped)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('myOverview.vehicles', 1)
                ->has('myVehicles', 1)
                ->where('myVehicles.0.license_plate', 'A-MINE 1')
            );
    }

    public function test_the_member_overview_never_reaches_across_companies(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $operator = $this->makeMember($alpha, B2bRolePreset::StandardUser->permissions()->toArray());
        $this->makeB2bVehicle($alpha, ['license_plate' => 'A-AA 1111']);
        $this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']);

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('myOverview.vehicles', 1)
                ->where('myVehicles.0.license_plate', 'A-AA 1111')
            );
    }

    /** A view-only member gets the same view-only information, and no way to act. */
    public function test_a_read_only_member_gets_the_operating_overview_without_actions(): void
    {
        $company = $this->makeCompany();
        $viewer = $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::ViewCompany->value]);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-AA 1111']);

        $props = $this->actingAs($viewer)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $props['myOverview']['vehicles']);
        $this->assertCount(1, $props['myVehicles']);
        $this->assertNotContains(
            B2bPermission::CreateOrders->value,
            $props['auth']['b2b']['active']['permissions'],
            'Read-only must not be offered the booking action.',
        );
    }

    /** The administrator's page is the company overview and nothing else. */
    public function test_a_company_administrator_gets_no_member_overview(): void
    {
        $company = $this->makeCompany();
        $this->makeB2bVehicle($company);

        $this->actingAs($this->makeOwner($company))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('myOverview', null)
                ->has('myVehicles', 0)
                ->has('statistics.orders')
            );
    }

    public function test_a_company_administrator_gets_the_whole_dashboard(): void
    {
        $company = $this->makeCompany();
        $this->makeB2bOrder($this->makeB2bVehicle($company));

        $this->actingAs($this->makeOwner($company))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('statistics.orders')
                ->has('analytics.totals')
                ->has('recentOrders', 1)
                ->has('bookableVehicles'),
            );
    }

    /**
     * Role-shaped in practice, permission-shaped in code: a company that
     * deliberately grants analytics to one member gets exactly what it asked
     * for, without a second rule to keep in step.
     */
    public function test_a_member_granted_analytics_access_sees_the_overview(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, [
            B2bPermission::ViewVehicles->value,
            B2bPermission::ViewAnalytics->value,
        ]);
        $this->makeB2bOrder($this->makeB2bVehicle($company));

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('statistics.orders')
                ->has('analytics.totals')
                ->has('recentOrders', 1),
            );
    }

    /** Nothing of the overview reaches a member who may not see it. */
    public function test_the_overview_payload_is_withheld_not_merely_hidden(): void
    {
        $company = $this->makeCompany();
        $this->makeB2bVehicle($company);

        $viewer = $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::ViewCompany->value]);
        $props = $this->actingAs($viewer)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

        $this->assertNull($props['analytics']);
        $this->assertNull($props['statistics']);
        $this->assertSame([], $props['recentOrders']);
    }

    /** An own-scope member with analytics is told the figures are theirs. */
    public function test_the_overview_reports_a_narrowed_scope(): void
    {
        $company = $this->makeCompany();
        $scoped = $this->makeMember($company, [
            B2bPermission::ViewVehicles->value,
            B2bPermission::ViewAnalytics->value,
        ], 'own');

        $this->actingAs($scoped)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('statistics.scope.company_wide', false));
    }

    public function test_the_dashboard_lists_the_newest_processes(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);

        $older = $this->makeB2bVehicle($company, ['license_plate' => 'A-ALT 1']);
        $newer = $this->makeB2bVehicle($company, ['license_plate' => 'A-NEU 2']);

        LeasybackOrder::factory()->create(['vehicle_id' => $older->vehicle_id, 'created_at' => now()->subDay()]);
        LeasybackOrder::factory()->create(['vehicle_id' => $newer->vehicle_id, 'created_at' => now()]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentOrders', 2)
                ->where('recentOrders.0.license_plate', 'A-NEU 2')
                ->where('recentOrders.1.license_plate', 'A-ALT 1')
            );
    }

    /** The card is an overview, not a second orders page. */
    public function test_the_process_overview_is_capped(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);

        foreach (range(1, 8) as $index) {
            LeasybackOrder::factory()->create([
                'vehicle_id' => $this->makeB2bVehicle($company, ['license_plate' => "A-XX {$index}"])->vehicle_id,
            ]);
        }

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('recentOrders', 5));
    }

    public function test_the_process_overview_never_reaches_across_companies(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $this->makeB2bOrder($this->makeB2bVehicle($alpha, ['license_plate' => 'A-AA 1111']));
        $this->makeB2bOrder($this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']));

        $this->actingAs($this->makeOwner($alpha))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentOrders', 1)
                ->where('recentOrders.0.license_plate', 'A-AA 1111')
            );
    }

    /** An own-scope member's overview is narrowed like everything else. */
    public function test_the_process_overview_respects_the_member_vehicle_scope(): void
    {
        $company = $this->makeCompany();
        $scoped = $this->makeMember($company, [
            B2bPermission::ViewVehicles->value,
            B2bPermission::ViewAnalytics->value,
        ], 'own');

        $this->makeB2bOrder($this->makeB2bVehicle($company, ['license_plate' => 'A-MINE 1', 'created_by_user_id' => $scoped->id]));
        $this->makeB2bOrder($this->makeB2bVehicle($company, ['license_plate' => 'A-THEIRS 2']));

        $this->actingAs($scoped)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentOrders', 1)
                ->where('recentOrders.0.license_plate', 'A-MINE 1')
            );
    }

    // ── What each role is offered ────────────────────────────────────

    /**
     * Read-only keeps the catalogue and loses every action: the one bookable
     * service is gated on `orders.create`, which the preset withholds.
     */
    public function test_a_read_only_member_gets_the_catalogue_but_no_way_to_act(): void
    {
        $company = $this->makeCompany();
        $viewer = $this->makeMember($company, B2bRolePreset::ReadOnly->permissions()->toArray());
        $this->makeB2bVehicle($company, ['license_plate' => 'A-FREI 2']);

        $props = $this->actingAs($viewer)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

        $this->assertNotContains(
            B2bPermission::CreateOrders->value,
            $props['auth']['b2b']['active']['permissions'],
            'Read-only must not be offered the booking action.',
        );
    }

    public function test_a_standard_user_is_offered_the_booking_action(): void
    {
        $company = $this->makeCompany();
        $operator = $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray());
        $this->makeB2bVehicle($company, ['license_plate' => 'A-FREI 2']);

        $props = $this->actingAs($operator)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

        $this->assertContains(B2bPermission::CreateOrders->value, $props['auth']['b2b']['active']['permissions']);
        $this->assertCount(1, $props['bookableVehicles']);
    }

    public function test_a_member_without_vehicle_access_is_sent_to_the_team_page(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, [B2bPermission::ViewMembers->value]);

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertRedirect(route('b2b.members.index'));
    }

    /**
     * Neither the fleet nor the team page: sending them to the team page
     * answered with a 403, so the member had no page they could open at all.
     */
    public function test_a_member_without_vehicle_or_member_access_lands_on_a_page_they_can_open(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, [B2bPermission::ViewCompany->value]);

        $this->actingAs($member)->get(route('dashboard'))->assertRedirect(route('profile.edit'));
        $this->actingAs($member)->get(route('vehicles.index'))->assertRedirect(route('profile.edit'));
        $this->actingAs($member)->get(route('profile.edit'))->assertOk();
    }

    public function test_a_member_with_only_statistics_access_lands_on_the_statistics_page(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, [B2bPermission::ViewAnalytics->value]);

        $this->actingAs($member)->get(route('dashboard'))->assertRedirect(route('b2b.statistics.index'));
        $this->actingAs($member)->get(route('b2b.statistics.index'))->assertOk();
    }

    /** A member with no company permission at all still reaches their account page. */
    public function test_a_member_with_no_permissions_is_not_sent_into_a_refusal(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, []);

        $this->actingAs($member)->get(route('dashboard'))->assertRedirect(route('profile.edit'));
        $this->actingAs($member)->get(route('profile.edit'))->assertOk();
    }

    public function test_an_admin_is_sent_to_their_own_area(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_firmenkunde_without_a_company_is_sent_to_registration(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Firmenkunde]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.b2b.show'));
    }
}
