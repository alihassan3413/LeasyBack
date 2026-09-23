<?php

namespace Tests\Feature;

use App\Enums\B2bPermission;
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
 * The company's orders page: every order it has placed, across its fleet.
 * Access to an order follows access to its vehicle, so the listing is scoped
 * through the same query the fleet page uses.
 *
 * Firmenkunde only — a Privatkunde reaches an order through the vehicle that
 * owns it, on the dashboard they have always had.
 */
class OrderIndexTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    public function test_unauthenticated_is_redirected_to_login(): void
    {
        $this->get(route('orders.index'))->assertRedirect(route('login'));
    }

    public function test_it_lists_the_companys_own_orders_only(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);

        $this->makeB2bOrder($this->makeB2bVehicle($company, ['license_plate' => 'K LB 1']));

        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $theirs = Vehicle::factory()->create(['b2c_user_id' => $stranger->id, 'license_plate' => 'K LB 2']);
        LeasybackOrder::factory()->create(['vehicle_id' => $theirs->vehicle_id]);

        $this->actingAs($owner)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('orders/Index')
                ->has('orders', 1)
                ->where('orders.0.license_plate', 'K LB 1')
            );
    }

    /** A vehicle that has been through the process twice has two rows, not one. */
    public function test_every_order_of_a_vehicle_gets_its_own_row(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->makeB2bVehicle($company);

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Cancelled->value,
            'created_at' => now()->subDay(),
        ]);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Confirmed->value,
            'created_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders', 2)
                // Newest first.
                ->where('orders.0.order_status', 'confirmed')
                ->where('orders.1.order_status', 'cancelled')
            );
    }

    public function test_the_open_filter_hides_closed_orders(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->makeB2bVehicle($company);

        LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'order_status' => OrderStatus::Confirmed->value]);
        LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'order_status' => OrderStatus::Completed->value]);

        $this->actingAs($owner)
            ->get(route('orders.index', ['status' => 'open']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders', 1)
                ->where('orders.0.order_status', 'confirmed')
            );
    }

    public function test_the_closed_filter_shows_only_finished_orders(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->makeB2bVehicle($company);

        LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'order_status' => OrderStatus::Confirmed->value]);
        LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'order_status' => OrderStatus::Completed->value]);

        $this->actingAs($owner)
            ->get(route('orders.index', ['status' => 'closed']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders', 1)
                ->where('orders.0.order_status', 'completed')
            );
    }

    public function test_search_matches_the_vehicle_behind_an_order(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);

        $this->makeB2bOrder($this->makeB2bVehicle($company, ['license_plate' => 'K LB 1', 'make' => 'BMW']));
        $this->makeB2bOrder($this->makeB2bVehicle($company, ['license_plate' => 'K LB 2', 'make' => 'Audi']));

        $this->actingAs($owner)
            ->get(route('orders.index', ['search' => 'BMW']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders', 1)
                ->where('orders.0.license_plate', 'K LB 1')
            );
    }

    public function test_orders_never_reach_across_companies(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $this->makeB2bOrder($this->makeB2bVehicle($alpha, ['license_plate' => 'A-AA 1111']));
        $this->makeB2bOrder($this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']));

        $this->actingAs($this->makeOwner($alpha))
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders', 1)
                ->where('orders.0.license_plate', 'A-AA 1111')
            );
    }

    /** Access to an order follows access to its vehicle, member scope included. */
    public function test_an_own_scope_member_only_sees_orders_on_their_own_vehicles(): void
    {
        $company = $this->makeCompany();
        $scoped = $this->makeMember($company, [B2bPermission::ViewVehicles->value], 'own');

        $this->makeB2bOrder($this->makeB2bVehicle($company, ['license_plate' => 'A-MINE 1', 'created_by_user_id' => $scoped->id]));
        $this->makeB2bOrder($this->makeB2bVehicle($company, ['license_plate' => 'A-THEIRS 2']));

        $this->actingAs($scoped)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders', 1)
                ->where('orders.0.license_plate', 'A-MINE 1')
            );
    }

    public function test_a_member_without_vehicle_access_is_refused(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, [B2bPermission::ViewMembers->value]);

        $this->actingAs($member)->get(route('orders.index'))->assertForbidden();
    }

    public function test_a_firmenkunde_without_a_company_is_sent_to_registration(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Firmenkunde]);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertRedirect(route('onboarding.b2b.show'));
    }

    /** A Privatkunde has no orders page; they belong on their dashboard. */
    public function test_a_privatkunde_is_sent_to_their_dashboard(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($owner)->get(route('orders.index'))->assertRedirect(route('dashboard'));
    }

    public function test_an_admin_is_sent_to_their_own_order_area(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get(route('orders.index'))
            ->assertRedirect(route('admin.orders.index'));
    }
}
