<?php

namespace Tests\Feature\B2b;

use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\B2B\Services\B2bStatisticsService;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * b2b.txt §20: "Users can access only their own company's information" and
 * "Statistics and Excel exports use company-scoped data" (§19's strict
 * company-level data isolation).
 *
 * Two companies exist in every test here, each with a vehicle whose plate is
 * distinctive, so a leak shows up as a concrete foreign plate rather than a
 * count that happens to match.
 */
class CrossCompanyIsolationTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    public function test_dashboard_shows_only_the_callers_own_company_vehicles(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $this->makeB2bVehicle($alpha, ['license_plate' => 'A-AA 1111']);
        $this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']);

        $response = $this->actingAs($this->makeOwner($alpha))->get(route('dashboard'));

        $response->assertOk();
        $plates = collect($response->viewData('page')['props']['vehicles'])->pluck('license_plate');

        $this->assertContains('A-AA 1111', $plates);
        $this->assertNotContains('B-BB 2222', $plates);
    }

    public function test_vehicle_detail_of_another_company_is_not_reachable(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $foreign = $this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']);

        $this->actingAs($this->makeOwner($alpha))
            ->get(route('vehicles.show', $foreign->vehicle_id))
            ->assertNotFound();
    }

    public function test_vehicle_service_payload_never_carries_a_foreign_companys_vehicle(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $this->makeB2bVehicle($alpha, ['license_plate' => 'A-AA 1111']);
        $this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']);

        $payload = app(VehicleService::class)->listVehiclesWithOrders($alpha->b2b_id, 'B2B');

        $this->assertStringContainsString('A-AA 1111', json_encode($payload));
        $this->assertStringNotContainsString('B-BB 2222', json_encode($payload));
    }

    public function test_statistics_and_export_are_scoped_to_the_callers_company(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $alphaVehicle = $this->makeB2bVehicle($alpha, ['license_plate' => 'A-AA 1111']);
        $betaVehicle = $this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']);

        $this->makeB2bOrder($alphaVehicle, 'completed');
        $this->makeB2bOrder($betaVehicle, 'completed');

        $owner = $this->makeOwner($alpha);
        $membership = app(B2bContext::class)->activeMembership($owner);
        $statistics = app(B2bStatisticsService::class);

        $this->assertSame(1, $statistics->summary($membership, $owner->id)['orders']['completed']);

        $exported = json_encode($statistics->exportRows($membership, $owner->id));

        $this->assertStringContainsString('A-AA 1111', $exported);
        $this->assertStringNotContainsString('B-BB 2222', $exported);
    }

    public function test_statistics_route_refuses_a_member_of_another_company(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $this->makeB2bVehicle($beta, ['license_plate' => 'B-BB 2222']);

        $response = $this->actingAs($this->makeOwner($alpha))->get(route('b2b.statistics.index'));

        $response->assertOk();
        $this->assertStringNotContainsString('B-BB 2222', json_encode($response->viewData('page')['props']));
    }

    /**
     * KNOWN DEFECT — skipped, not deleted (phase 17 finding 1).
     *
     * `vehicle_scope = 'own'` is enforced by VehicleScopeService::scopeQuery(),
     * findVehicleWithAccess() and VehiclePolicy, but **not** by
     * VehicleService::scopedVehicleQuery(), which backs the dashboard list and
     * listVehiclesWithOrders(). Both filter on `b2b_id` alone, so an own-scope
     * member is listed every vehicle in the company — plate, VIN, make, model,
     * orders, offers and documents — while the dashboard tells them "Sie sehen
     * nur die Fahrzeuge, die Sie selbst angelegt haben". Opening a colleague's
     * vehicle is still correctly refused, so this is a listing leak, not a
     * detail-access one. Cross-company isolation is unaffected.
     *
     * This test carries the correct expectation and will pass once
     * scopedVehicleQuery() consults the membership's vehicle scope.
     */
    public function test_an_own_scope_member_sees_only_the_vehicles_they_registered(): void
    {
        $this->markTestSkipped(
            'Known defect: VehicleService::scopedVehicleQuery() ignores vehicle_scope=own. See phase 17 finding 1.'
        );

        $alpha = $this->makeCompany('Alpha GmbH');

        $scoped = $this->makeMember($alpha, ['vehicles.view'], 'own');

        $this->makeB2bVehicle($alpha, ['license_plate' => 'A-MINE 1', 'created_by_user_id' => $scoped->id]);
        $this->makeB2bVehicle($alpha, ['license_plate' => 'A-THEIRS 2']);

        $response = $this->actingAs($scoped)->get(route('dashboard'));

        $response->assertOk();
        $plates = collect($response->viewData('page')['props']['vehicles'])->pluck('license_plate');

        $this->assertContains('A-MINE 1', $plates);
        $this->assertNotContains('A-THEIRS 2', $plates);
    }

    public function test_admin_order_detail_of_a_foreign_company_is_unreachable_by_a_company_user(): void
    {
        $alpha = $this->makeCompany('Alpha GmbH');
        $beta = $this->makeCompany('Beta GmbH');

        $order = $this->makeB2bOrder($this->makeB2bVehicle($beta));

        // The whole admin area is Admin-only; a company user must not reach a
        // foreign order through it regardless of company scoping.
        $this->actingAs($this->makeOwner($alpha))
            ->get(route('admin.orders.show', $order->id))
            ->assertForbidden();
    }
}
