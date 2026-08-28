<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Mail\Orders\FinalInspectionCompletedMail;
use App\Mail\Orders\OrderCompletedMail;
use App\Mail\Orders\VehicleReadyForPickupMail;
use App\Models\User;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use App\Support\PartnerLifecyclePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The back half of the B2C journey: repair, follow-up inspection, collection
 * and closure.
 *
 * Two things used to make this unfinishable. `reworkshop` offered only
 * `cancelled`, so a car that failed its follow-up inspection could never be
 * completed — the single way out of a second repair was to cancel a case the
 * customer had already committed to. And `delivered`, which means "ready for
 * collection" and sends exactly that email, was treated as a terminal *and*
 * closed status, so a car still standing at the workshop released its vehicle
 * for a fresh booking and the case simply stopped there.
 */
class B2cRepairToClosureTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ------------------------------------------------------- the whole path

    public function test_a_b2c_case_runs_from_repair_to_closure(): void
    {
        $order = $this->b2cOrder('workshop');

        foreach (['reinspection', 'delivered', 'completed'] as $next) {
            $order = $this->advance($order, $next);
        }

        $this->assertSame('completed', $order->order_status);
    }

    public function test_a_failed_follow_up_inspection_sends_the_car_back_and_still_reaches_closure(): void
    {
        $order = $this->b2cOrder('workshop');

        $order = $this->advance($order, 'reinspection');   // follow-up performed
        $order = $this->advance($order, 'reworkshop');     // …it failed
        $order = $this->advance($order, 'reinspection');   // repeat repair inspected
        $order = $this->advance($order, 'delivered');      // …it passed
        $order = $this->advance($order, 'completed');      // collected

        $this->assertSame('completed', $order->order_status);
    }

    /**
     * The loop is not capped: how often a repair has to be redone is a fact
     * about the car, not a number the transition table should decide.
     */
    public function test_the_repair_loop_repeats_as_often_as_the_car_needs(): void
    {
        $order = $this->b2cOrder('workshop');
        $order = $this->advance($order, 'reinspection');

        for ($cycle = 0; $cycle < 4; $cycle++) {
            $order = $this->advance($order, 'reworkshop');
            $order = $this->advance($order, 'reinspection');
        }

        $order = $this->advance($order, 'delivered');
        $this->advance($order, 'completed');

        // Every cycle is legible afterwards: four returns to the workshop and
        // the five follow-up inspections around them.
        $this->assertSame(4, $this->historyCount($order, 'reworkshop'));
        $this->assertSame(5, $this->historyCount($order, 'reinspection'));
    }

    // ------------------------------------------------------- no way backwards

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function refusedShortcuts(): array
    {
        return [
            'repeat repair cannot skip its inspection' => ['reworkshop', 'delivered'],
            'a released car cannot re-enter the workshop' => ['delivered', 'workshop'],
            'a released car cannot be re-inspected' => ['delivered', 'inspected'],
            'a closed case cannot reopen' => ['completed', 'delivered'],
            'a closed case cannot be cancelled' => ['completed', 'cancelled'],
            'a closed case cannot return to repair' => ['completed', 'workshop'],
        ];
    }

    #[DataProvider('refusedShortcuts')]
    public function test_a_finished_case_cannot_fall_back_into_repair(string $from, string $to): void
    {
        $order = $this->b2cOrder($from);

        $this->expectException(ValidationException::class);

        try {
            app(TransitionOrderStatus::class)($order, $to, 'admin', 'tester');
        } finally {
            $this->assertSame($from, $order->fresh()->order_status);
        }
    }

    public function test_a_case_awaiting_collection_can_still_be_cancelled(): void
    {
        $order = $this->advance($this->b2cOrder('delivered'), 'cancelled');

        $this->assertSame('cancelled', $order->order_status);
    }

    // -------------------------------------------------- active-order invariant

    /**
     * A car nobody has collected is not a finished case, and collecting it
     * does not hand the vehicle back either: completion is the end of this
     * vehicle's journey, not a reset to the start of another.
     */
    public function test_a_vehicle_stays_claimed_through_collection_and_after_it(): void
    {
        $order = $this->b2cOrder('reinspection');
        $vehicleId = $order->vehicle_id;
        $vehicles = app(VehicleService::class);

        $this->advance($order, 'delivered');
        $this->assertTrue($vehicles->blocksNewOrder($vehicleId), 'a car awaiting collection must still hold its vehicle');

        $this->advance($order->fresh(), 'completed');
        $this->assertTrue($vehicles->blocksNewOrder($vehicleId), 'a completed case must not free the vehicle for another order');
    }

    /**
     * The one way back. A cancelled order produced nothing, so it must leave
     * the vehicle as it found it — otherwise calling an order off would retire
     * the car permanently.
     */
    public function test_only_a_cancelled_order_frees_the_vehicle_again(): void
    {
        $order = $this->b2cOrder('confirmed');
        $vehicles = app(VehicleService::class);

        $this->advance($order, 'cancelled');

        $this->assertFalse($vehicles->blocksNewOrder($order->vehicle_id));
    }

    public function test_delivered_is_not_in_the_closed_set_and_completed_is(): void
    {
        $this->assertNotContains(OrderStatus::Delivered->value, OrderStatus::closedValues());
        $this->assertContains(OrderStatus::Completed->value, OrderStatus::closedValues());
        $this->assertSame([OrderStatus::Completed->value], OrderStatus::completedValues());
    }

    // ------------------------------------------------------------ ownership

    /**
     * Fix 03's allow-list already answers this, but the statuses it now has to
     * refuse are new, so the refusal is pinned here too.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function providerForbiddenStatuses(): array
    {
        return [
            ['reinspection', 'reworkshop'],
            ['reinspection', 'delivered'],
            ['delivered', 'completed'],
        ];
    }

    #[DataProvider('providerForbiddenStatuses')]
    public function test_the_inspection_provider_owns_none_of_the_new_transitions(string $from, string $target): void
    {
        config(['services.tuvsud.api_key' => 'k']);
        $order = $this->b2cOrder($from);

        $this->withHeader('X-API-Key', 'k')
            ->getJson("/order/tuvsud/status?auftragsnummer={$order->auftragsnummer}&status={$target}")
            ->assertStatus(403);

        $this->assertSame($from, $order->fresh()->order_status);
    }

    public function test_the_provider_still_owns_the_follow_up_inspection_itself(): void
    {
        config(['services.tuvsud.api_key' => 'k']);
        $order = $this->b2cOrder('reworkshop');

        $this->withHeader('X-API-Key', 'k')
            ->getJson("/order/tuvsud/status?auftragsnummer={$order->auftragsnummer}&status=reinspection_completed")
            ->assertOk();

        $this->assertSame('reinspection', $order->fresh()->order_status);
        $this->assertTrue(PartnerLifecyclePermissions::owns(PartnerLifecyclePermissions::TUV_SUD, 'reinspection'));
    }

    public function test_a_replayed_provider_callback_changes_nothing_twice(): void
    {
        config(['services.tuvsud.api_key' => 'k']);
        $order = $this->b2cOrder('reworkshop');

        for ($i = 0; $i < 3; $i++) {
            $this->withHeader('X-API-Key', 'k')
                ->getJson("/order/tuvsud/status?auftragsnummer={$order->auftragsnummer}&status=reinspection_completed")
                ->assertOk();
        }

        $this->assertSame(1, $this->historyCount($order, 'reinspection'));
        Mail::assertQueued(FinalInspectionCompletedMail::class, 1);
    }

    // -------------------------------------------------------- side effects

    public function test_the_ready_for_collection_mail_is_sent_once_per_real_transition(): void
    {
        $order = $this->b2cOrder('reinspection');

        $this->advance($order, 'delivered');
        // A repeat of the status the order already holds is a no-op, so no
        // second "your car is ready" lands in the customer's inbox.
        $this->advance($order->fresh(), 'delivered');

        Mail::assertQueued(VehicleReadyForPickupMail::class, 1);
        Mail::assertNotQueued(OrderCompletedMail::class);
    }

    public function test_closure_sends_the_completion_mail_once(): void
    {
        $order = $this->advance($this->b2cOrder('delivered'), 'completed');

        $this->advance($order->fresh(), 'completed');

        Mail::assertQueued(OrderCompletedMail::class, 1);
    }

    // -------------------------------------------------------------- channel

    public function test_the_b2b_return_lifecycle_is_unchanged(): void
    {
        $order = $this->b2bOrder('order_requested');

        foreach ([
            'order_placed', 'confirmed', 'vehicle_collected', 'inspected',
            'workshop_commissioned', 'workshop', 'repair_completed', 'reinspection',
            'vehicle_returned', 'invoice_processed',
        ] as $next) {
            $order = $this->advance($order, $next);
        }

        $this->assertSame('invoice_processed', $order->order_status);
    }

    public function test_a_b2b_order_still_cannot_hold_a_b2c_only_status(): void
    {
        $order = $this->b2bOrder('reinspection');

        $this->expectException(ValidationException::class);

        app(TransitionOrderStatus::class)($order, 'delivered', 'admin', 'tester');
    }

    public function test_a_b2c_order_still_cannot_hold_a_b2b_only_status(): void
    {
        $order = $this->b2cOrder('workshop');

        $this->expectException(ValidationException::class);

        app(TransitionOrderStatus::class)($order, 'repair_completed', 'admin', 'tester');
    }

    // ---------------------------------------------------------------- admin

    public function test_admin_is_offered_exactly_the_valid_next_actions(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        foreach ([
            'reinspection' => ['reworkshop', 'delivered', 'cancelled'],
            'reworkshop' => ['reinspection', 'cancelled'],
            'delivered' => ['completed', 'cancelled'],
            'completed' => [],
        ] as $from => $expected) {
            $order = $this->b2cOrder($from);

            $transitions = $this->actingAs($admin)
                ->get(route('admin.orders.show', $order->id))
                ->viewData('page')['props']['order']['available_transitions'];

            $this->assertEqualsCanonicalizing($expected, $transitions, "wrong actions offered at [{$from}]");
        }
    }

    public function test_an_admin_can_walk_a_case_to_closure_through_the_ui_route(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $order = $this->b2cOrder('delivered');

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.status', $order->id), ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->order_status);
    }

    // ------------------------------------------------- what the customer reloads

    /**
     * QA's report: Admin marks the order completed, Admin's own timeline shows
     * it, and the customer's page still draws the last step as unfinished after
     * a refresh.
     *
     * The client derives that timeline entirely from these two fields —
     * getCustomerOrderFlowSteps() reads `order_status` to place the order and
     * `status_updates` to date the closing stage — so what is pinned here is
     * that a reload genuinely carries both. Both B2C surfaces are asserted,
     * because they build the same steps from separately assembled payloads and
     * the reported symptom appeared on only one of them.
     */
    public function test_a_completed_case_reloads_as_completed_on_both_customer_surfaces(): void
    {
        [$owner, $order] = $this->ownedB2cOrder('workshop');

        foreach (['reinspection', 'delivered', 'completed'] as $next) {
            $order = $this->advance($order, $next);
        }

        $closure = fn (AssertableInertia $page, string $at) => $page
            ->where("{$at}.orders.0.order_status", 'completed')
            ->where("{$at}.orders.0.status_updates.0.new_status", 'completed')
            ->where("{$at}.orders.0.status_updates.0.old_status", 'delivered');

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $closure($page, 'vehicles.0'));

        $this->actingAs($owner)
            ->get(route('vehicles.show', $order->vehicle_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $closure($page, 'vehicle'));
    }

    /**
     * The repair-payment stage has to reach both customer surfaces, and be the
     * same one on each.
     *
     * The vehicle page derives its payment rung from this field. It was present
     * in the payload all along and simply not read, which resolved the stage to
     * `none`, made the settled charge invisible to stageHappened() and drew the
     * rung as a skipped step captioned "kein Kundenangebot erstellt" over a
     * repair the customer had paid for. Asserting parity rather than a literal:
     * what must hold is that the two pages cannot disagree about the payment.
     */
    public function test_both_customer_surfaces_carry_the_same_repair_payment_stage(): void
    {
        [$owner, $order] = $this->ownedB2cOrder('reinspection');

        $this->advance($order, 'delivered');

        $stageOn = fn (string $route, mixed $args, string $at) => data_get(
            $this->actingAs($owner)->get(route($route, $args))->assertOk()->viewData('page'),
            "props.{$at}.orders.0.payment.repair_stage",
        );

        $dashboard = $stageOn('dashboard', [], 'vehicles.0');
        $detail = $stageOn('vehicles.show', $order->vehicle_id, 'vehicle');

        $this->assertNotNull($detail, 'the vehicle page cannot derive a payment rung without this');
        $this->assertSame($dashboard, $detail);
    }

    /**
     * The closing transition has to leave a history row of its own. It is the
     * only record of when the case closed — the timeline dates its final stage
     * from it, and nothing else in the payload carries that moment.
     */
    public function test_closure_is_recorded_as_its_own_history_entry(): void
    {
        [, $order] = $this->ownedB2cOrder('delivered');

        $this->advance($order, 'completed');

        $entry = OrderStatusUpdate::where('auftragsnummer', $order->auftragsnummer)
            ->where('new_status', 'completed')
            ->sole();

        $this->assertSame('delivered', $entry->old_status);
        $this->assertNotNull($entry->created_at);
    }

    // -------------------------------------------------------------- helpers

    /**
     * A B2C order whose vehicle has a real owner, so the customer-facing
     * routes resolve it. b2cOrder() deliberately leaves the vehicle unowned.
     *
     * @return array{User, LeasybackOrder}
     */
    private function ownedB2cOrder(string $status): array
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => $owner->id,
        ]);

        return [$owner, LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ])];
    }

    private function advance(LeasybackOrder $order, string $to): LeasybackOrder
    {
        return app(TransitionOrderStatus::class)($order, $to, 'admin', 'tester');
    }

    private function historyCount(LeasybackOrder $order, string $status): int
    {
        return OrderStatusUpdate::where('auftragsnummer', $order->auftragsnummer)
            ->where('new_status', $status)
            ->count();
    }

    private function b2cOrder(string $status): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2b_id' => null]);

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);
    }

    private function b2bOrder(string $status): LeasybackOrder
    {
        return LeasybackOrder::factory()->create([
            'vehicle_id' => $this->makeB2bVehicle($this->makeCompany())->vehicle_id,
            'order_status' => $status,
        ]);
    }
}
