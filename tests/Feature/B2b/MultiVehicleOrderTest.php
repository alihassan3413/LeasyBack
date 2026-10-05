<?php

namespace Tests\Feature\B2b;

use App\Models\B2B;
use App\Models\User;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderVehicle;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Step 1 of the Vehicle Condition Appraisal: one order that covers several
 * vehicles. Every vehicle of such an order must be held by it, show it on its
 * own page, and be released again when the order is called off.
 */
class MultiVehicleOrderTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private B2B $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->company = $this->makeCompany('Flotte GmbH');
        $this->owner = $this->makeOwner($this->company);
    }

    public function test_every_vehicle_of_the_order_is_held_by_it(): void
    {
        [$first, $second, $third] = [$this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company)];
        $free = $this->makeB2bVehicle($this->company);

        $this->multiVehicleOrder([$first, $second, $third]);

        $service = app(VehicleService::class);

        foreach ([$first, $second, $third] as $vehicle) {
            $this->assertTrue($service->blocksNewOrder($vehicle->vehicle_id), "{$vehicle->license_plate} must be held");
        }

        $this->assertFalse($service->blocksNewOrder($free->vehicle_id));

        $bookable = collect($service->listBookableVehicles($this->company->b2b_id, 'B2B'))->pluck('vehicle_id')->all();
        $this->assertSame([$free->vehicle_id], $bookable, 'only the free vehicle can still be booked');
    }

    public function test_each_vehicle_shows_the_order_as_its_current_one(): void
    {
        [$first, $second] = [$this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company)];
        $order = $this->multiVehicleOrder([$first, $second]);

        $service = app(VehicleService::class);

        foreach ([$first, $second] as $vehicle) {
            $data = $service->findVehicleWithOrders($vehicle->vehicle_id, $this->company->b2b_id, 'B2B');
            $this->assertSame($order->id, $data['current_order']['id'], "{$vehicle->license_plate} shows the order");
        }
    }

    public function test_the_fleet_list_shows_the_status_on_every_vehicle(): void
    {
        [$first, $second] = [$this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company)];
        $this->multiVehicleOrder([$first, $second]);

        $rows = app(VehicleService::class)->paginateVehiclesWithOrders($this->company->b2b_id, 'B2B', ['status' => 'order_requested'])['data'];

        $this->assertEqualsCanonicalizing([$first->vehicle_id, $second->vehicle_id], array_column($rows, 'vehicle_id'));
    }

    public function test_cancelling_the_order_frees_all_its_vehicles(): void
    {
        [$first, $second] = [$this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company)];
        $order = $this->multiVehicleOrder([$first, $second]);

        app(TransitionOrderStatus::class)($order, 'cancelled', 'admin', 'tester');

        $this->assertSame(0, OrderVehicle::where('order_id', $order->id)->whereNotNull('active_vehicle_id')->count());

        $service = app(VehicleService::class);
        $this->assertFalse($service->blocksNewOrder($first->vehicle_id));
        $this->assertFalse($service->blocksNewOrder($second->vehicle_id));
    }

    public function test_a_completed_order_keeps_its_vehicles_held(): void
    {
        [$first, $second] = [$this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company)];
        $order = $this->multiVehicleOrder([$first, $second]);

        // Completion closes the slot but — like every other service — leaves
        // the vehicle out of a second order of the same history.
        $order->update(['order_status' => 'completed']);

        $this->assertSame(0, OrderVehicle::where('order_id', $order->id)->whereNotNull('active_vehicle_id')->count());
        $this->assertTrue(app(VehicleService::class)->blocksNewOrder($second->vehicle_id));
    }

    public function test_the_database_refuses_one_vehicle_in_two_open_multi_vehicle_orders(): void
    {
        [$first, $second, $third] = [$this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company)];
        $this->multiVehicleOrder([$first, $second]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->multiVehicleOrder([$third, $second]);
    }

    public function test_orders_with_a_single_vehicle_are_unchanged(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);
        $order = $this->makeB2bOrder($vehicle, 'confirmed');

        $this->assertSame(0, OrderVehicle::count());
        $this->assertTrue(app(VehicleService::class)->blocksNewOrder($vehicle->vehicle_id));
        $this->assertSame(
            $order->id,
            app(VehicleService::class)->findVehicleWithOrders($vehicle->vehicle_id, $this->company->b2b_id, 'B2B')['current_order']['id'],
        );
    }

    /**
     * An order the way the appraisal booking will store it: the first vehicle
     * on the order row, all of them in leasyback_order_vehicles.
     *
     * @param  list<Vehicle>  $vehicles
     */
    private function multiVehicleOrder(array $vehicles): LeasybackOrder
    {
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicles[0]->vehicle_id,
            'order_status' => 'order_requested',
            'leasyback_partner' => 'leasyback',
            'service_type' => 'gutachten',
            'request_payload' => ['order_type' => 'vehicle_appraisal'],
        ]);

        foreach ($vehicles as $position => $vehicle) {
            OrderVehicle::create(['order_id' => $order->id, 'vehicle_id' => $vehicle->vehicle_id, 'position' => $position]);
        }

        return $order;
    }
}