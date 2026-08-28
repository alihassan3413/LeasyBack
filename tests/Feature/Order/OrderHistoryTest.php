<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\InspectionStation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * A vehicle's orders are a history, not a single "the order".
 *
 * The portal used to render `vehicle.orders[0]` — a position in whatever order
 * the query returned — which made two things impossible: saying with certainty
 * which order was the live one, and reaching any of the others at all. A
 * customer who reordered after a cancellation lost the cancelled case entirely:
 * its timeline, its documents and the reason it stopped were still in the
 * database and nowhere on screen.
 *
 * Pinned here: the server names `current_order` and `order_history` itself, the
 * rule it uses to pick between them, and that every order — closed ones
 * included — has an address of its own that serves it in full.
 */
class OrderHistoryTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    // ------------------------------------------------- the split itself

    public function test_the_payload_names_the_current_order_and_the_history_behind_it(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();

        $cancelled = $this->order($vehicle, OrderStatus::Cancelled, '-3 days');
        $running = $this->order($vehicle, OrderStatus::Confirmed, '-1 day');

        $this->actingAs($customer)
            ->get(route('vehicles.show', $vehicle->vehicle_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.current_order.id', $running->id)
                ->has('vehicle.order_history', 1)
                ->where('vehicle.order_history.0.id', $cancelled->id)
                ->where('vehicle.order_history.0.outcome', 'cancelled')
                ->where('vehicle.order_history.0.is_closed', true)
            );
    }

    /**
     * "Current" is a rule about the order's state, not about its place in the
     * list — a cancelled order created after a running one must not displace it.
     */
    public function test_the_running_order_is_current_even_when_a_closed_one_is_newer(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();

        $running = $this->order($vehicle, OrderStatus::Workshop, '-5 days');
        $discarded = $this->order($vehicle, OrderStatus::Discarded, '-1 hour');

        $this->actingAs($customer)
            ->get(route('vehicles.show', $vehicle->vehicle_id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.current_order.id', $running->id)
                ->where('vehicle.order_history.0.id', $discarded->id)
                ->where('vehicle.order_history.0.outcome', 'discarded')
            );
    }

    /**
     * A vehicle whose case is over still shows it. Leaving `current_order` null
     * once everything had closed would blank the page for exactly the customers
     * who most want to look back at what happened.
     */
    public function test_a_fully_closed_vehicle_keeps_its_newest_order_as_current(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();

        $first = $this->order($vehicle, OrderStatus::Cancelled, '-9 days');
        $completed = $this->order($vehicle, OrderStatus::Completed, '-2 days');

        $this->actingAs($customer)
            ->get(route('vehicles.show', $vehicle->vehicle_id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.current_order.id', $completed->id)
                ->where('vehicle.current_order.order_status', OrderStatus::Completed->value)
                ->has('vehicle.order_history', 1)
                ->where('vehicle.order_history.0.id', $first->id)
            );
    }

    public function test_a_vehicle_with_no_orders_has_neither_a_current_order_nor_a_history(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();

        $this->actingAs($customer)
            ->get(route('vehicles.show', $vehicle->vehicle_id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.current_order', null)
                ->has('vehicle.order_history', 0)
            );
    }

    /**
     * A history row is dated by the transition that ended it, not by when it
     * was opened — "Beendet am" has to be the closing date or it is a lie.
     */
    public function test_a_history_row_carries_the_date_its_order_closed(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();

        $cancelled = $this->order($vehicle, OrderStatus::Cancelled, '-6 days');
        OrderStatusUpdate::create([
            'auftragsnummer' => $cancelled->auftragsnummer,
            'old_status' => OrderStatus::Confirmed->value,
            'new_status' => OrderStatus::Cancelled->value,
            'updated_by' => 'admin@leasyback.test',
            'auth_source' => 'admin',
        ]);
        $this->order($vehicle, OrderStatus::Confirmed, '-1 day');

        $this->actingAs($customer)
            ->get(route('vehicles.show', $vehicle->vehicle_id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.order_history.0.id', $cancelled->id)
                ->whereNot('vehicle.order_history.0.closed_at', null)
            );
    }

    /** A running order has not closed, so it must not claim a closing date. */
    public function test_a_running_order_has_no_closing_date(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();

        $this->order($vehicle, OrderStatus::Cancelled, '-2 days');
        $running = $this->order($vehicle, OrderStatus::Confirmed, '-1 day');

        $this->actingAs($customer)
            ->get(route('orders.show', $running->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.current_order.id', $running->id)
                ->where('vehicle.current_order.closed_at', null)
                ->where('vehicle.current_order.outcome', 'open')
            );
    }

    // ------------------------------------------- every reorder is its own

    public function test_a_reorder_is_a_separate_record_and_the_cancelled_one_stays_in_the_history(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        [$customer, $vehicle] = $this->customerWithVehicle();
        $station = InspectionStation::factory()->create(['provider' => 'dekra', 'is_active' => true]);

        $cancelled = $this->order($vehicle, OrderStatus::Cancelled, '-4 days');

        // Through the real creation path, so what is pinned is that reordering
        // opens a *record*, never that it reopens the cancelled one.
        $this->actingAs($customer)
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'station_id' => $station->station_id,
                'termin' => now()->addWeek()->toDateTimeString(),
            ])
            ->assertSessionHasNoErrors();

        $reorder = LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)
            ->whereKeyNot($cancelled->id)
            ->sole();

        $this->assertNotSame($cancelled->auftragsnummer, $reorder->auftragsnummer);
        $this->assertSame(2, LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->count());
        $this->assertSame(OrderStatus::Cancelled->value, $cancelled->refresh()->order_status);

        $this->actingAs($customer)
            ->get(route('vehicles.show', $vehicle->vehicle_id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('vehicle.current_order.auftragsnummer', $reorder->auftragsnummer)
                ->where('vehicle.order_history.0.auftragsnummer', $cancelled->auftragsnummer)
            );
    }

    // ----------------------------------------------- the detail page itself

    public function test_a_closed_order_opens_by_its_own_id_with_its_own_timeline_and_documents(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();

        $cancelled = $this->order($vehicle, OrderStatus::Cancelled, '-4 days');
        OrderStatusUpdate::create([
            'auftragsnummer' => $cancelled->auftragsnummer,
            'old_status' => OrderStatus::Confirmed->value,
            'new_status' => OrderStatus::Cancelled->value,
            'updated_by' => 'admin@leasyback.test',
            'auth_source' => 'admin',
        ]);
        VehicleReportDocument::factory()->create([
            'auftragsnummer' => $cancelled->auftragsnummer,
            'vehicle_id' => $vehicle->vehicle_id,
            'document_type' => 'gutachten',
            'published' => true,
        ]);

        $running = $this->order($vehicle, OrderStatus::Confirmed, '-1 day');

        $this->actingAs($customer)
            ->get(route('orders.show', $cancelled->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('orders/Show')
                // The order that was asked for, not the vehicle's current one.
                ->where('order.id', $cancelled->id)
                ->where('order.order_status', OrderStatus::Cancelled->value)
                ->has('order.status_updates', 1)
                ->has('order.report_documents', 1)
                ->has('order.offers')
                // The running order is still reachable from here, as a summary.
                ->where('vehicle.current_order.id', $running->id)
                ->where('vehicle.license_plate', $vehicle->license_plate)
            );
    }

    /**
     * The detail payload carries one order, not the vehicle's whole list —
     * sending both would ship the same record twice.
     */
    public function test_the_detail_payload_does_not_repeat_the_vehicles_orders(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();
        $order = $this->order($vehicle, OrderStatus::Confirmed, '-1 day');

        $this->actingAs($customer)
            ->get(route('orders.show', $order->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('vehicle.orders')
                // Reduced to a summary: the full record is under `order`.
                ->missing('vehicle.current_order.status_updates')
                ->where('vehicle.current_order.id', $order->id)
            );
    }

    public function test_the_payment_state_travels_with_the_order_it_belongs_to(): void
    {
        [$customer, $vehicle] = $this->customerWithVehicle();
        $order = $this->order($vehicle, OrderStatus::Confirmed, '-1 day');

        $this->actingAs($customer)
            ->get(route('orders.show', $order->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('order.payment')
                ->where('order.payment.requires_setup', true)
            );
    }

    // -------------------------------------------------------- who may look

    public function test_another_customers_order_is_not_reachable(): void
    {
        [, $vehicle] = $this->customerWithVehicle();
        $order = $this->order($vehicle, OrderStatus::Confirmed, '-1 day');

        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($stranger)
            ->get(route('orders.show', $order->id))
            ->assertNotFound();
    }

    public function test_an_unknown_order_id_is_a_404_rather_than_an_error(): void
    {
        [$customer] = $this->customerWithVehicle();

        $this->actingAs($customer)
            ->get(route('orders.show', '00000000-0000-4000-8000-000000000000'))
            ->assertNotFound();
    }

    /**
     * A company member restricted to their own vehicles is restricted to their
     * own orders too — the order page must not become the way around
     * VehicleScopeService.
     */
    public function test_an_own_scope_member_cannot_open_a_colleagues_order(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->makeB2bVehicle($company, ['created_by_user_id' => $owner->id]);
        $order = $this->makeB2bOrder($vehicle);

        $member = $this->makeMember($company, ['vehicles.view'], 'own');

        $this->actingAs($member)
            ->get(route('orders.show', $order->id))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('orders.show', $order->id))
            ->assertOk();
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: User, 1: Vehicle} */
    private function customerWithVehicle(): array
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);

        return [$customer, Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $customer->id,
            'b2b_id' => null,
        ])];
    }

    /**
     * Orders are created directly rather than through OrderService: the
     * invariant that a vehicle has at most one *active* order is enforced
     * there, and building a history means putting several on one vehicle.
     */
    private function order(Vehicle $vehicle, OrderStatus $status, string $createdAt): LeasybackOrder
    {
        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status->value,
            'created_at' => now()->parse($createdAt),
        ]);
    }
}
