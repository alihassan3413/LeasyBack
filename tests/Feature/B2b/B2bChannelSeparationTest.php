<?php

namespace Tests\Feature\B2b;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * b2b.txt §21: "Do not merge the B2B and B2C workflows" / "Do not change
 * existing B2C statuses", and §20's "Existing B2C behavior remains completely
 * unchanged".
 *
 * The channel is always resolved from the persisted vehicle, never from
 * request input, so every case here sets up a real vehicle of the relevant
 * type rather than passing a flag.
 */
class B2bChannelSeparationTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    public function test_a_b2c_order_rejects_every_b2b_only_status(): void
    {
        foreach (OrderStatus::b2bOnlyValues() as $status) {
            $order = LeasybackOrder::factory()->create(['order_status' => 'confirmed']);

            try {
                app(TransitionOrderStatus::class)($order, $status, 'admin', 'tester');
                $this->fail("Expected a B2C order to reject the B2B-only status {$status}");
            } catch (ValidationException) {
                $this->assertSame('confirmed', $order->fresh()->order_status);
            }
        }
    }

    public function test_a_b2b_order_rejects_every_b2c_only_status(): void
    {
        $company = $this->makeCompany();

        foreach (OrderStatus::b2cOnlyValues() as $status) {
            $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'reinspection');

            try {
                app(TransitionOrderStatus::class)($order, $status, 'admin', 'tester');
                $this->fail("Expected a B2B order to reject the B2C-only status {$status}");
            } catch (ValidationException) {
                $this->assertSame('reinspection', $order->fresh()->order_status);
            }
        }
    }

    public function test_the_b2b_only_statuses_and_b2c_only_statuses_do_not_overlap(): void
    {
        $this->assertSame(
            [],
            array_intersect(OrderStatus::b2bOnlyValues(), OrderStatus::b2cOnlyValues()),
        );
    }

    /**
     * Vehicle::B2B_ONLY_ATTRIBUTES are stripped at serialization, so a B2C
     * payload cannot carry a fleet field even if the row somehow holds values.
     */
    public function test_b2b_only_vehicle_attributes_never_appear_on_a_b2c_payload(): void
    {
        $b2cOrder = LeasybackOrder::factory()->create(['order_status' => 'confirmed']);
        $vehicle = $b2cOrder->vehicle;

        // Plant values directly, bypassing the request rules that normally
        // prohibit them, so the serialization guard is what is under test.
        DB::table('vehicles')->where('vehicle_id', $vehicle->vehicle_id)->update([
            'mileage' => 12345,
            'contract_number' => 'LEAK-CONTRACT',
            'cost_centre' => 'LEAK-COST',
            'driver_name' => 'LEAK-DRIVER',
            'driver_contact' => 'LEAK-CONTACT',
        ]);

        $payload = json_encode(app(VehicleService::class)->listVehiclesWithOrders(
            (string) $vehicle->b2c_user_id,
            'B2C',
        ));

        foreach (['LEAK-CONTRACT', 'LEAK-COST', 'LEAK-DRIVER', 'LEAK-CONTACT'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $payload);
        }
    }

    public function test_a_b2c_order_payload_carries_no_b2b_only_keys(): void
    {
        $b2cOrder = LeasybackOrder::factory()->create(['order_status' => 'confirmed']);

        $payload = app(VehicleService::class)->listVehiclesWithOrders(
            (string) $b2cOrder->vehicle->b2c_user_id,
            'B2C',
        );

        $order = $payload[0]['orders'][0];

        foreach (['collection', 'notes'] as $b2bOnlyKey) {
            $this->assertArrayNotHasKey($b2bOnlyKey, $order);
        }
    }

    public function test_the_documented_b2c_transition_path_still_works_end_to_end(): void
    {
        $order = LeasybackOrder::factory()->create(['order_status' => 'order_requested']);
        $action = app(TransitionOrderStatus::class);

        foreach ([
            'order_placed', 'confirmed', 'inspected', 'workshop', 'reinspection', 'delivered',
        ] as $next) {
            $order = $action($order, $next, 'admin', 'tester');
        }

        $this->assertSame('delivered', $order->order_status);
    }

    public function test_the_b2b_transition_path_runs_end_to_end_up_to_the_billing_gate(): void
    {
        $company = $this->makeCompany();
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'order_requested');
        $action = app(TransitionOrderStatus::class);

        foreach ([
            'order_placed', 'confirmed', 'vehicle_collected', 'inspected',
            'workshop_commissioned', 'workshop', 'repair_completed', 'reinspection',
            'vehicle_returned', 'invoice_processed',
        ] as $next) {
            $order = $action($order, $next, 'admin', 'tester');
        }

        $this->assertSame('invoice_processed', $order->order_status);
    }
}
