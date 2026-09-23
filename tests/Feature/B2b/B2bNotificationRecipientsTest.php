<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bPermission;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Who is notified about a company vehicle. Every recipient is sent a link to
 * the vehicle, so the list must be exactly the members who can open it —
 * anyone else got a notification that 404s and learned that a vehicle they
 * may not see exists.
 */
class B2bNotificationRecipientsTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    public function test_only_members_who_can_see_the_vehicle_are_notified(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $fleetViewer = $this->makeMember($company, [B2bPermission::ViewVehicles->value]);
        $creator = $this->makeMember($company, [B2bPermission::ViewVehicles->value, B2bPermission::CreateVehicles->value], 'own');
        $ownScopeStranger = $this->makeMember($company, [B2bPermission::ViewVehicles->value], 'own');
        $noVehicleAccess = $this->makeMember($company, [B2bPermission::ViewMembers->value]);
        $inactive = $this->makeMember($company, [B2bPermission::ViewVehicles->value]);
        DB::table('user_b2b')->where('user_id', $inactive->id)->update(['status' => 'inactive']);

        $otherCompany = $this->makeCompany('Fremd GmbH');
        $outsider = $this->makeOwner($otherCompany);

        $vehicle = $this->makeB2bVehicle($company, ['created_by_user_id' => $creator->id]);

        $recipients = app(VehicleScopeService::class)->resolveOwnerUsers($vehicle)->pluck('id')->sort()->values()->all();

        $expected = collect([$owner->id, $fleetViewer->id, $creator->id])->sort()->values()->all();

        $this->assertSame($expected, $recipients);
        $this->assertNotContains($ownScopeStranger->id, $recipients);
        $this->assertNotContains($noVehicleAccess->id, $recipients);
        $this->assertNotContains($inactive->id, $recipients);
        $this->assertNotContains($outsider->id, $recipients);
    }

    public function test_every_recipient_can_actually_open_the_vehicle(): void
    {
        $company = $this->makeCompany();
        $this->makeOwner($company);
        $this->makeMember($company, [B2bPermission::ViewVehicles->value]);
        $this->makeMember($company, [B2bPermission::ViewVehicles->value], 'own');
        $this->makeMember($company, [B2bPermission::ViewAnalytics->value]);

        $vehicle = $this->makeB2bVehicle($company);

        $recipients = app(VehicleScopeService::class)->resolveOwnerUsers($vehicle);

        $this->assertCount(2, $recipients);

        foreach ($recipients as $recipient) {
            $this->actingAs($recipient)->get(route('vehicles.show', $vehicle->vehicle_id))->assertOk();
        }
    }
}
