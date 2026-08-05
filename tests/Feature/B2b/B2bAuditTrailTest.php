<?php

namespace Tests\Feature\B2b;

use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * b2b.txt §20: "All critical actions are recorded in the audit history", and
 * §19's "Audit logs for status changes and approvals".
 *
 * §15 additionally requires that changes are stored as historical events and
 * do not overwrite the audit history, which is why these assert on the row
 * *count* growing rather than just the latest value being right.
 */
class B2bAuditTrailTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    public function test_every_status_change_appends_one_audit_row_with_actor_and_timestamp(): void
    {
        $company = $this->makeCompany();
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'order_requested');
        $action = app(TransitionOrderStatus::class);

        $path = ['order_placed', 'confirmed', 'vehicle_collected', 'inspected'];

        foreach ($path as $next) {
            $order = $action($order, $next, 'admin', 'Admin Anna');
        }

        $rows = DB::table('leasyback_order_status_updates')
            ->where('auftragsnummer', $order->auftragsnummer)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(count($path), $rows);

        foreach ($rows as $row) {
            $this->assertNotNull($row->created_at, 'Every audit row must carry a timestamp');
            $this->assertSame('admin', $row->auth_source);
            $this->assertSame('Admin Anna', $row->updated_by);
        }

        $this->assertSame(
            $path,
            $rows->pluck('new_status')->all(),
            'The audit history must preserve the order of transitions',
        );
    }

    public function test_a_repeated_status_write_does_not_append_a_duplicate_audit_row(): void
    {
        $company = $this->makeCompany();
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'order_placed');
        $action = app(TransitionOrderStatus::class);

        $order = $action($order, 'confirmed', 'admin', 'tester');
        $action($order, 'confirmed', 'admin', 'tester');

        $this->assertSame(
            1,
            DB::table('leasyback_order_status_updates')
                ->where('auftragsnummer', $order->auftragsnummer)
                ->where('new_status', 'confirmed')
                ->count(),
            'An idempotent same-status write must not create a second audit row',
        );
    }

    public function test_a_rejected_transition_leaves_no_audit_row(): void
    {
        $company = $this->makeCompany();
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'order_requested');

        try {
            // Not a legal edge from order_requested.
            app(TransitionOrderStatus::class)($order, 'completed', 'admin', 'tester');
        } catch (\Throwable) {
            // Expected.
        }

        $this->assertDatabaseMissing('leasyback_order_status_updates', [
            'auftragsnummer' => $order->auftragsnummer,
            'new_status' => 'completed',
        ]);
    }

    public function test_vehicle_creation_is_recorded_in_the_vehicle_audit_log(): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);

        // The factory writes the row directly, so drive the audited path.
        app(VehicleService::class)
            ->createVehicle($this->makeOwner($company), [
                'license_plate' => 'B AU 1234',
            ]);

        $created = Vehicle::where('license_plate', 'B AU 1234')
            ->firstOrFail();

        $this->assertDatabaseHas('vehicle_audit_log', [
            'vehicle_id' => $created->vehicle_id,
            'action' => 'INSERT',
        ]);

        $this->assertNotSame($vehicle->vehicle_id, $created->vehicle_id);
    }

    public function test_the_cancellation_path_is_audited(): void
    {
        $company = $this->makeCompany();
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'confirmed');

        app(TransitionOrderStatus::class)($order, 'cancelled', 'admin', 'tester');

        $this->assertDatabaseHas('leasyback_order_status_updates', [
            'auftragsnummer' => $order->auftragsnummer,
            'old_status' => 'confirmed',
            'new_status' => 'cancelled',
        ]);
    }
}
