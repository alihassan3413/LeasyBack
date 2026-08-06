<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bPermission;
use App\Models\User;
use App\Modules\UserProfile\B2B\Services\B2bAnalyticsService;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Unresolved item 32: the dashboard's FleetOverview tiles must describe the
 * same set of vehicles the list beneath them shows.
 *
 * Before this fix `B2bAnalyticsService::summary()` took only a company id, so
 * an own-scope member saw company-wide totals above their own narrowed list —
 * "12 Fahrzeuge" over a table of three.
 *
 * Each test asserts the totals *and* the state buckets, because the two are
 * computed by separate queries and only one of them being narrowed would still
 * leave the panel self-contradictory.
 */
class B2bAnalyticsScopeTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    /**
     * @return array{totals: array<string, int>, states: list<array<string, mixed>>, members: list<array<string, mixed>>}
     */
    private function summaryFor(User $viewer, string $b2bId): array
    {
        return app(B2bAnalyticsService::class)->summary($b2bId, $viewer);
    }

    /**
     * @param  list<array<string, mixed>>  $states
     */
    private function stateTotal(array $states): int
    {
        return array_sum(array_column($states, 'count'));
    }

    public function test_an_own_scope_member_gets_totals_for_only_their_own_vehicles(): void
    {
        $company = $this->makeCompany();
        $scoped = $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::ViewAnalytics->value], 'own');

        $mine = $this->makeB2bVehicle($company, ['created_by_user_id' => $scoped->id]);
        $this->makeB2bOrder($mine, 'inspected');

        $theirs = $this->makeB2bVehicle($company);
        $this->makeB2bOrder($theirs, 'inspected');
        $this->makeB2bVehicle($company);

        $summary = $this->summaryFor($scoped, $company->b2b_id);

        $this->assertSame(1, $summary['totals']['vehicles']);
        $this->assertSame(1, $summary['totals']['open_orders']);
        $this->assertSame(1, $this->stateTotal($summary['states']));
    }

    public function test_a_company_wide_member_gets_company_wide_totals(): void
    {
        $company = $this->makeCompany();
        $wide = $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::ViewAnalytics->value], 'all');

        $mine = $this->makeB2bVehicle($company, ['created_by_user_id' => $wide->id]);
        $this->makeB2bOrder($mine, 'inspected');

        $theirs = $this->makeB2bVehicle($company);
        $this->makeB2bOrder($theirs, 'inspected');
        $this->makeB2bVehicle($company);

        $summary = $this->summaryFor($wide, $company->b2b_id);

        $this->assertSame(3, $summary['totals']['vehicles']);
        $this->assertSame(2, $summary['totals']['open_orders']);
        $this->assertSame(3, $this->stateTotal($summary['states']));
    }

    public function test_an_owner_gets_company_wide_totals(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);

        $this->makeB2bOrder($this->makeB2bVehicle($company), 'inspected');
        $this->makeB2bVehicle($company);

        $summary = $this->summaryFor($owner, $company->b2b_id);

        $this->assertSame(2, $summary['totals']['vehicles']);
        $this->assertSame(1, $summary['totals']['open_orders']);
        $this->assertSame(2, $this->stateTotal($summary['states']));
    }

    public function test_another_companys_vehicles_never_reach_the_totals(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $this->makeB2bVehicle($alpha);
        $this->makeB2bVehicle($beta);
        $this->makeB2bVehicle($beta);

        $summary = $this->summaryFor($this->makeOwner($alpha), $alpha->b2b_id);

        $this->assertSame(1, $summary['totals']['vehicles']);
        $this->assertSame(1, $this->stateTotal($summary['states']));
    }

    /**
     * The per-member breakdown groups *by* member on purpose. Narrowing it to
     * the viewer would collapse the panel to one row.
     */
    public function test_the_per_member_breakdown_is_not_narrowed(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $scoped = $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::ViewAnalytics->value], 'own');

        $this->makeB2bVehicle($company, ['created_by_user_id' => $scoped->id]);
        $this->makeB2bVehicle($company, ['created_by_user_id' => $owner->id]);

        $summary = $this->summaryFor($scoped, $company->b2b_id);

        $this->assertCount(2, $summary['members']);
        $this->assertSame(
            [1, 1],
            array_column($summary['members'], 'vehicles'),
            'Both members must still be reported with their own vehicle counts',
        );
    }

    public function test_the_dashboard_tiles_agree_with_the_list_for_an_own_scope_member(): void
    {
        $company = $this->makeCompany();
        $scoped = $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::ViewAnalytics->value], 'own');

        $this->makeB2bVehicle($company, ['license_plate' => 'A-MINE 1', 'created_by_user_id' => $scoped->id]);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-THEIRS 2']);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-THEIRS 3']);

        $response = $this->actingAs($scoped)->get(route('dashboard'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['vehicles']);
        $this->assertSame(1, $props['analytics']['totals']['vehicles']);
        $this->assertSame(
            count($props['vehicles']),
            $this->stateTotal($props['analytics']['states']),
            'The FleetOverview buckets must sum to the number of vehicles listed',
        );
    }

    public function test_the_dashboard_tiles_stay_company_wide_for_an_owner(): void
    {
        $company = $this->makeCompany();

        $this->makeB2bVehicle($company, ['license_plate' => 'A-ONE 1']);
        $this->makeB2bVehicle($company, ['license_plate' => 'A-TWO 2']);

        $response = $this->actingAs($this->makeOwner($company))->get(route('dashboard'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertCount(2, $props['vehicles']);
        $this->assertSame(2, $props['analytics']['totals']['vehicles']);
    }

    /**
     * A Privatkunde has no membership, so no analytics are built at all — the
     * fix must not reach the B2C dashboard.
     */
    public function test_a_privatkunde_dashboard_is_unchanged(): void
    {
        $order = LeasybackOrder::factory()->create(['order_status' => 'confirmed']);
        $owner = User::find($order->vehicle->b2c_user_id);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertNull($props['analytics']);
        $this->assertCount(1, $props['vehicles']);
    }
}
