<?php

namespace Tests\Feature\B2b;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * A Firmenkunde who skipped company registration ("Jetzt überspringen",
 * "Später fertigstellen"): the dashboard is open to them as a read-only
 * pending page, and everything that needs a company stays refused by the
 * server exactly as before.
 */
class B2bCompanyPendingTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private function skipped(): User
    {
        return User::factory()->create(['user_type' => UserType::Firmenkunde]);
    }

    public function test_the_skipped_user_sees_the_pending_dashboard_with_no_company_data(): void
    {
        // Another company's data exists; none of it may reach this page.
        $this->makeB2bOrder($this->makeB2bVehicle($this->makeCompany('Fremd GmbH'), ['license_plate' => 'K FR 1']));

        $response = $this->actingAs($this->skipped())->get(route('dashboard'))->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('b2b/Dashboard')
            ->where('companyPending', true)
            ->where('bookableVehicles', [])
            ->where('recentOrders', [])
            ->where('myVehicles', [])
            ->where('analytics', null)
            ->where('statistics', null)
            ->where('relocationOptions.address_profiles', [])
            ->where('relocationOptions.billing_addresses', [])
            ->where('auth.b2b.company_pending', true)
            ->where('auth.b2b.active', null)
            ->where('auth.b2b.permissions', [])
        );
        $this->assertStringNotContainsString('K FR 1', $response->getContent());
    }

    /** Every company page and every company action is still refused — sent to registration. */
    public function test_everything_that_needs_a_company_stays_blocked(): void
    {
        $user = $this->skipped();
        $foreignVehicle = $this->makeB2bVehicle($this->makeCompany('Fremd GmbH'));
        $this->actingAs($user);

        foreach (['vehicles.index', 'orders.index', 'b2b.members.index', 'b2b.statistics.index'] as $page) {
            $this->get(route($page))->assertRedirect(route('onboarding.b2b.show'));
        }

        $this->post(route('vehicles.store'), [
            'license_plate' => 'B AB 123', 'vin' => 'WVWZZZ1KZE2E09999', 'make' => 'Volkswagen', 'leasinggeber' => 'VW Leasing',
        ])->assertRedirect(route('onboarding.b2b.show'));

        $this->post(route('orders.store', $foreignVehicle->vehicle_id), [
            'requested_collection_date' => now()->addWeek()->toDateString(),
            'requested_collection_time_slot' => '10:00-12:00',
            'collection_address' => ['street' => 'Invalidenstraße', 'zip_code' => '10115', 'city' => 'Berlin'],
        ])->assertRedirect(route('onboarding.b2b.show'));

        $this->post(route('b2b.invitations.store'), ['email' => 'x@example.com', 'role' => 'member', 'vehicle_scope' => 'all'])
            ->assertRedirect(route('onboarding.b2b.show'));

        $this->assertSame(1, Vehicle::count(), 'only the foreign vehicle exists');
        $this->assertSame(0, LeasybackOrder::count());
        $this->assertDatabaseCount('b2b_invitations', 0);
    }

    /** The banner's target: the registration form, still open to them. */
    public function test_the_call_to_action_leads_back_to_registration(): void
    {
        $this->actingAs($this->skipped())
            ->get(route('onboarding.b2b.show'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('onboarding/B2bRegistration'));
    }

    /** Someone who had a company and was deactivated is not "pending": still refused. */
    public function test_a_deactivated_member_is_not_given_the_pending_dashboard(): void
    {
        $company = $this->makeCompany();
        $member = $this->makeMember($company, []);
        DB::table('user_b2b')->where('user_id', $member->id)->update(['status' => 'inactive']);

        $this->actingAs($member)->get(route('dashboard'))->assertForbidden();
    }

    /** A company with its data registered gets the normal dashboard, unchanged. */
    public function test_a_registered_company_gets_its_normal_dashboard(): void
    {
        $company = $this->makeCompany();
        $this->makeB2bVehicle($company, ['license_plate' => 'B OK 1']);

        $this->actingAs($this->makeOwner($company))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('b2b/Dashboard')
                ->where('companyPending', false)
                ->where('auth.b2b.company_pending', false)
                ->has('bookableVehicles', 1)
                ->whereNot('statistics', null)
            );
    }
}
