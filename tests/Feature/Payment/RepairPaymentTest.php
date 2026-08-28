<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Mail\Orders\FinalInspectionCompletedMail;
use App\Mail\Orders\VehicleInRepairMail;
use App\Mail\Orders\VehicleReadyForPickupMail;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\OrderTaskResolver;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * The repair charge: when it fires, what it charges, and what it gates.
 */
class RepairPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
        Mail::fake();
    }

    /**
     * An order sitting at `reinspection` with an accepted offer and a usable
     * card — everything in place for the charge to fire on the next move.
     *
     * @return array{0: LeasybackOrder, 1: User}
     */
    private function orderAwaitingDelivery(string $gross = '1190.00', bool $withMandate = true): array
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $customer->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Reinspection->value,
        ]);

        if ($gross !== '0.00') {
            // final_total_gross is auto-summed from all four components in the
            // model's saving hook, so the others are zeroed to make the
            // expected charge exactly $gross.
            LeasybackOffer::factory()->create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'offer_status' => 'selected',
                'repair_cost_net' => $gross,
                'repair_cost_gross' => $gross,
                'depreciation_value_net' => '0.00',
                'depreciation_value_gross' => '0.00',
                'workshop_repair_quote_net' => '0.00',
                'workshop_repair_quote_gross' => '0.00',
                'missing_parts_cost_net' => '0.00',
                'missing_parts_cost_gross' => '0.00',
                'selected_at' => now(),
            ]);
        }

        if ($withMandate) {
            OrderPaymentMethod::factory()->saved()->create([
                'order_id' => $order->id,
                'stripe_customer_id' => 'cus_repair_test',
            ]);
        }

        return [$order, $customer];
    }

    private function moveTo(LeasybackOrder $order, string $status): LeasybackOrder
    {
        return app(TransitionOrderStatus::class)($order, $status, 'test', 'PHPUnit');
    }

    private function repairPayment(LeasybackOrder $order): ?OrderPayment
    {
        return OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::Repair->value)
            ->first();
    }

    // ---- the trigger -------------------------------------------------------

    public function test_reaching_delivered_charges_the_selected_offers_gross(): void
    {
        [$order] = $this->orderAwaitingDelivery('1190.00');

        $this->moveTo($order, OrderStatus::Delivered->value);

        $payment = $this->repairPayment($order);

        $this->assertNotNull($payment);
        $this->assertSame(119000, $payment->amount_cents);
        $this->assertSame('eur', $payment->currency);
        $this->assertSame(PaymentStatus::Paid, $payment->status);

        $call = $this->stripe->lastCallTo('createPaymentIntent')['arguments'];

        $this->assertSame(119000, $call['amountCents']);
        $this->assertTrue($call['confirm']);
        $this->assertTrue($call['offSession']);
        $this->assertSame('cus_repair_test', $call['customerId']);
        $this->assertSame($order->id, $call['metadata']['order_id']);
        $this->assertSame('repair', $call['metadata']['purpose']);
        $this->assertArrayHasKey('offer_id', $call['metadata']);
    }

    /**
     * `reinspection` means the follow-up inspection happened, not that it
     * passed. Charging there would bill for a repair that then loops through
     * `reworkshop` and gets redone.
     */
    public function test_reinspection_to_reworkshop_creates_no_payment(): void
    {
        [$order] = $this->orderAwaitingDelivery();

        $this->moveTo($order, OrderStatus::Reworkshop->value);

        $this->assertNull($this->repairPayment($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_a_reworkshop_loop_still_produces_exactly_one_payment(): void
    {
        [$order] = $this->orderAwaitingDelivery();

        $order = $this->moveTo($order, OrderStatus::Reworkshop->value);
        $order = $this->moveTo($order, OrderStatus::Reinspection->value);
        $this->moveTo($order, OrderStatus::Delivered->value);

        $this->assertSame(1, OrderPayment::where('order_id', $order->id)->count());
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_accepting_an_offer_alone_creates_no_payment(): void
    {
        [$order] = $this->orderAwaitingDelivery();

        $this->assertNull($this->repairPayment($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    // ---- zero cost ---------------------------------------------------------

    public function test_a_zero_cost_repair_is_not_required_and_touches_no_stripe_object(): void
    {
        [$order] = $this->orderAwaitingDelivery('0.00');

        $this->moveTo($order, OrderStatus::Delivered->value);

        $payment = $this->repairPayment($order);

        $this->assertSame(PaymentStatus::NotRequired, $payment->status);
        $this->assertSame(0, $payment->amount_cents);
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(0, OrderPaymentIntent::count());
    }

    public function test_a_zero_cost_repair_can_proceed_to_pickup(): void
    {
        [$order] = $this->orderAwaitingDelivery('0.00');

        $order = $this->moveTo($order, OrderStatus::Delivered->value);
        $order = $this->moveTo($order, OrderStatus::Completed->value);

        $this->assertSame(OrderStatus::Completed->value, $order->order_status);
    }

    // ---- mail ownership ----------------------------------------------------

    /**
     * Exactly one sender: the `delivered` status mail is suppressed and the
     * payment emits the pickup mail when it settles.
     */
    public function test_the_pickup_mail_is_sent_once_when_the_charge_succeeds(): void
    {
        [$order] = $this->orderAwaitingDelivery('1190.00');

        $this->moveTo($order, OrderStatus::Delivered->value);

        Mail::assertQueued(VehicleReadyForPickupMail::class, 1);
    }

    public function test_no_pickup_mail_while_the_charge_is_outstanding(): void
    {
        [$order] = $this->orderAwaitingDelivery('1190.00', withMandate: false);

        $this->moveTo($order, OrderStatus::Delivered->value);

        Mail::assertNotQueued(VehicleReadyForPickupMail::class);
        $this->assertSame(PaymentStatus::RequiresManualCollection, $this->repairPayment($order)->status);
    }

    /**
     * Only `delivered` is suppressed — every other transition keeps its own
     * customer mail.
     */
    public function test_other_transitions_still_send_their_status_mail(): void
    {
        [$order] = $this->orderAwaitingDelivery();

        $this->moveTo($order, OrderStatus::Reworkshop->value);

        Mail::assertQueued(VehicleInRepairMail::class);
    }

    // ---- the pickup gate ---------------------------------------------------

    public function test_completion_is_refused_while_the_repair_is_unpaid(): void
    {
        [$order] = $this->orderAwaitingDelivery('1190.00', withMandate: false);
        $order = $this->moveTo($order, OrderStatus::Delivered->value);

        $this->expectException(ValidationException::class);

        $this->moveTo($order, OrderStatus::Completed->value);
    }

    public function test_completion_is_allowed_once_paid(): void
    {
        [$order] = $this->orderAwaitingDelivery('1190.00');
        $order = $this->moveTo($order, OrderStatus::Delivered->value);

        $this->assertSame(PaymentStatus::Paid, $this->repairPayment($order)->status);

        $order = $this->moveTo($order, OrderStatus::Completed->value);

        $this->assertSame(OrderStatus::Completed->value, $order->order_status);
    }

    /**
     * A hand-inserted order with no payment row must not complete an unpaid
     * repair just because the row is absent.
     */
    public function test_completion_is_refused_when_an_amount_is_owed_but_no_payment_row_exists(): void
    {
        [$order] = $this->orderAwaitingDelivery('1190.00');
        $order = $this->moveTo($order, OrderStatus::Delivered->value);

        OrderPayment::where('order_id', $order->id)->delete();

        $this->expectException(ValidationException::class);

        $this->moveTo($order->fresh(), OrderStatus::Completed->value);
    }

    // ---- B2B untouched -----------------------------------------------------

    public function test_a_b2b_order_creates_no_repair_payment(): void
    {
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => null]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Workshop->value,
        ]);

        $order = $this->moveTo($order, OrderStatus::RepairCompleted->value);
        $this->moveTo($order, OrderStatus::Reinspection->value);

        $this->assertSame(0, OrderPayment::count());
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    // ---- amount conversion -------------------------------------------------

    public function test_gross_is_converted_to_minor_units_without_float_drift(): void
    {
        [$order] = $this->orderAwaitingDelivery('100.01');

        $this->moveTo($order, OrderStatus::Delivered->value);

        $this->assertSame(10001, $this->repairPayment($order)->amount_cents);
    }

    public function test_the_charge_is_dispatched_only_once_per_order(): void
    {
        [$order] = $this->orderAwaitingDelivery('1190.00');

        $order = $this->moveTo($order, OrderStatus::Delivered->value);
        // A redelivered transition to the same status is a documented no-op.
        $this->moveTo($order, OrderStatus::Delivered->value);

        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(1, OrderPaymentIntent::count());
    }

    /**
     * The admin task tree must never offer an action the completion gate would
     * refuse, so an unpaid repair replaces `confirm_pickup` with a wait.
     */
    public function test_the_admin_task_tree_waits_on_payment_instead_of_offering_pickup(): void
    {
        [$order] = $this->orderAwaitingDelivery('1190.00', withMandate: false);
        $this->moveTo($order, OrderStatus::Delivered->value);

        $tasks = app(OrderTaskResolver::class)->forOrderDetail([
            'id' => $order->id,
            'vehicle_belongs' => 'B2C',
            'order_status' => OrderStatus::Delivered->value,
            'status_updates' => [],
            'repair_payment' => ['status' => 'requires_manual_collection', 'blocks_pickup' => true],
        ]);

        $this->assertSame('await_repair_payment', $tasks['next']['key']);
        $this->assertNull($tasks['next']['action']);
    }

    public function test_the_admin_task_tree_offers_pickup_once_paid(): void
    {
        [$order] = $this->orderAwaitingDelivery('1190.00');
        $this->moveTo($order, OrderStatus::Delivered->value);

        $tasks = app(OrderTaskResolver::class)->forOrderDetail([
            'id' => $order->id,
            'vehicle_belongs' => 'B2C',
            'order_status' => OrderStatus::Delivered->value,
            'status_updates' => [],
            'repair_payment' => ['status' => 'paid', 'blocks_pickup' => false],
            // A settled charge owes the customer an invoice before the case
            // closes, so that step is what the tree asks for first.
            'report_documents' => [
                ['document_type' => 'rechnung', 'published' => true, 'created_at' => '2026-08-01T10:00:00+00:00'],
            ],
        ]);

        $this->assertSame('confirm_pickup', $tasks['next']['key']);
        $this->assertNotNull($tasks['next']['action']);
    }

    public function test_the_final_inspection_mail_is_unaffected(): void
    {
        [$order] = $this->orderAwaitingDelivery();
        $order->update(['order_status' => OrderStatus::Workshop->value]);

        $this->moveTo($order, OrderStatus::Reinspection->value);

        Mail::assertQueued(FinalInspectionCompletedMail::class);
    }
}
