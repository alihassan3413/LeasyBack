<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Actions\CancelOrderByCustomer;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Support\RepairPaymentPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * The €200 fee a customer owes for cancelling their own order.
 *
 * The invariant underneath every test here: **the cancellation is the
 * customer's, and Stripe does not get a vote on it.** A declined card, an
 * expired one, an outage, or no card at all — the order still ends up
 * cancelled, and only the fee's fate differs.
 *
 * The second invariant: it is a *separate* obligation. It never merges with,
 * replaces or disturbs a repair charge, which may already have been paid on the
 * very same order.
 */
class CancellationFeeTest extends TestCase
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

    // ---- fixtures ----------------------------------------------------------

    /**
     * An open B2C order, optionally with a card the fee can be taken from.
     *
     * @return array{0: LeasybackOrder, 1: User}
     */
    private function openOrder(bool $withMandate = true, string $status = OrderStatus::Workshop->value): array
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $customer->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);

        if ($withMandate) {
            OrderPaymentMethod::factory()->saved()->create([
                'order_id' => $order->id,
                'stripe_customer_id' => 'cus_cancel',
                'payment_method_id' => 'pm_saved',
            ]);
        }

        return [$order, $customer];
    }

    private function cancel(User $customer, LeasybackOrder $order): TestResponse
    {
        return $this->actingAs($customer)->postJson(route('orders.cancel', $order->id));
    }

    private function feeFor(LeasybackOrder $order): ?OrderPayment
    {
        return OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::CancellationFee->value)
            ->first();
    }

    private function feesFor(LeasybackOrder $order): Collection
    {
        return OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::CancellationFee->value)
            ->get();
    }

    private function intentResult(string $id, string $status, ?string $failureCode = null, int $amount = 20000): StripePaymentIntentResult
    {
        return new StripePaymentIntentResult(
            id: $id,
            status: $status,
            amount: $amount,
            currency: 'eur',
            clientSecret: $id.'_secret',
            customerId: 'cus_cancel',
            paymentMethodId: 'pm_saved',
            failureCode: $failureCode,
        );
    }

    /** Make the next off-session createPaymentIntent land in a given state. */
    private function nextChargeLands(string $status, ?string $failureCode = null): void
    {
        $this->stripe->givenPaymentIntent($this->intentResult('pi_fee', $status, $failureCode));
        $this->stripe->createResults[] = $this->stripe->paymentIntents['pi_fee'];
    }

    // ---- the happy path -----------------------------------------------------

    public function test_cancelling_with_a_saved_card_cancels_the_order_and_charges_two_hundred_euro(): void
    {
        [$order, $customer] = $this->openOrder();

        $this->cancel($customer, $order)
            ->assertOk()
            ->assertJsonPath('order_status', OrderStatus::Cancelled->value)
            ->assertJsonPath('fee.amount', '200.00');

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);

        $fee = $this->feeFor($order);

        $this->assertNotNull($fee);
        $this->assertSame(20000, $fee->amount_cents);
        $this->assertSame('eur', $fee->currency);
        $this->assertSame(PaymentStatus::Paid, $fee->status);

        $call = $this->stripe->lastCallTo('createPaymentIntent')['arguments'];

        $this->assertSame(20000, $call['amountCents']);
        $this->assertTrue($call['offSession']);
        $this->assertTrue($call['confirm']);
        $this->assertSame('pm_saved', $call['paymentMethodId']);
        $this->assertSame('cancellation_fee', $call['metadata']['purpose']);
        $this->assertSame(sprintf('%s:cancellation_fee:1:1', $fee->id), $call['idempotencyKey']);
    }

    public function test_the_fee_amount_comes_from_config_not_a_literal(): void
    {
        config(['payments.cancellation_fee_cents' => 25000]);

        [$order, $customer] = $this->openOrder();

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(25000, $this->feeFor($order)->amount_cents);
    }

    // ---- Stripe never gets a vote on the cancellation ------------------------

    public function test_an_authentication_required_charge_leaves_the_order_cancelled(): void
    {
        [$order, $customer] = $this->openOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_ACTION);

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::RequiresAction, $this->feeFor($order)->status);
    }

    public function test_a_declined_card_leaves_the_order_cancelled(): void
    {
        [$order, $customer] = $this->openOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD, 'card_declined');

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $this->feeFor($order)->status);
    }

    public function test_a_processing_charge_leaves_the_order_cancelled(): void
    {
        [$order, $customer] = $this->openOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_PROCESSING);

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Processing, $this->feeFor($order)->status);
    }

    public function test_a_stripe_outage_still_cancels_the_order(): void
    {
        [$order, $customer] = $this->openOrder();

        $this->stripe->nextFailure = StripeGatewayException::apiError('Stripe is down.');

        $this->cancel($customer, $order)->assertOk();

        // The point of the ordering: the cancellation committed before anything
        // was sent to Stripe, so an exception cannot reach back past it.
        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $this->feeFor($order)->status);
    }

    // ---- no usable mandate ---------------------------------------------------

    public function test_without_a_saved_card_the_fee_is_outstanding_and_no_intent_is_fabricated(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: false);

        $this->cancel($customer, $order)->assertOk();

        $fee = $this->feeFor($order);

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::RequiresManualCollection, $fee->status);
        $this->assertSame(20000, $fee->amount_cents);

        // No Stripe object at all — the obligation exists without one.
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(0, OrderPaymentIntent::where('payment_id', $fee->id)->count());
    }

    public function test_an_unauthorized_mandate_is_not_charged_off_session(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: false);

        // Verified card, but no recorded off-session authorization — the one
        // thing that makes an unattended charge legitimate.
        OrderPaymentMethod::factory()->saved()->create([
            'order_id' => $order->id,
            'stripe_customer_id' => 'cus_cancel',
            'payment_method_id' => 'pm_saved',
            'offsession_authorized_at' => null,
        ]);

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(PaymentStatus::RequiresManualCollection, $this->feeFor($order)->status);
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    // ---- idempotency ---------------------------------------------------------

    public function test_cancelling_twice_creates_one_fee_and_charges_once(): void
    {
        [$order, $customer] = $this->openOrder();

        $this->cancel($customer, $order)->assertOk();

        // The order is closed now, so the second request is refused outright —
        // and even if it were not, the fee is found rather than created.
        $this->cancel($customer, $order->fresh())->assertNotFound();

        $this->assertCount(1, $this->feesFor($order));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_the_action_itself_is_idempotent_even_when_called_directly(): void
    {
        [$order, $customer] = $this->openOrder();

        $action = app(CancelOrderByCustomer::class);

        $first = $action($order, $customer);
        $second = $action($order->fresh(), $customer);

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->feesFor($order));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'), 'A repeat cancellation must not re-charge.');
    }

    public function test_a_replayed_webhook_does_not_double_charge_or_double_settle(): void
    {
        [$order, $customer] = $this->openOrder();

        $this->cancel($customer, $order)->assertOk();

        $fee = $this->feeFor($order);
        $paidAt = $fee->paid_at;
        $intentId = OrderPaymentIntent::where('payment_id', $fee->id)->value('payment_intent_id');

        foreach (range(1, 3) as $ignored) {
            $this->sendWebhook([
                'type' => 'payment_intent.succeeded',
                'data' => ['object' => [
                    'id' => $intentId,
                    'status' => OrderPaymentIntent::STRIPE_SUCCEEDED,
                    'amount' => 20000,
                    'currency' => 'eur',
                    'customer' => 'cus_cancel',
                    'metadata' => ['order_id' => $order->id, 'purpose' => 'cancellation_fee'],
                ]],
            ])->assertOk();
        }

        $this->assertSame(PaymentStatus::Paid, $fee->fresh()->status);
        $this->assertEquals($paidAt, $fee->fresh()->paid_at);
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(1, OrderPaymentIntent::where('payment_id', $fee->id)->count());
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function sendWebhook(array $event): TestResponse
    {
        $this->stripe->webhookEvent = $event;

        return $this->postJson('/api/webhooks/stripe', $event, ['Stripe-Signature' => 't=1,v1=fake']);
    }

    // ---- the repair payment is a different obligation -------------------------

    /**
     * Cancel through the action rather than the route.
     *
     * A repair charge is only opened on reaching `delivered`, and a customer
     * can no longer cancel from there — so an order owing both is unreachable
     * through the HTTP endpoint by design. The separation invariant still has
     * to hold in the action itself: it is what any future caller (an Admin
     * tool, a support script) would go through, and the guarantee that the fee
     * never disturbs a repair charge should not depend on which door was used.
     */
    private function cancelViaAction(LeasybackOrder $order, User $customer): OrderPayment
    {
        return app(CancelOrderByCustomer::class)($order, $customer);
    }

    public function test_a_paid_repair_charge_is_left_completely_alone(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: true, status: OrderStatus::Delivered->value);

        $repair = OrderPayment::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => PaymentPurpose::Repair,
            'amount_cents' => 119000,
            'currency' => 'eur',
        ]);
        $repair->forceFill([
            'status' => PaymentStatus::Paid,
            'notified_status' => PaymentStatus::Paid->value,
            'paid_at' => now()->subDay(),
            'intent_count' => 1,
        ])->save();

        $before = $repair->fresh()->toArray();

        $this->cancelViaAction($order, $customer);

        $this->assertSame($before, $repair->fresh()->toArray(), 'The repair charge must not be touched by a cancellation.');
        $this->assertSame(PaymentStatus::Paid, $repair->fresh()->status);

        // And the fee exists beside it, at its own amount.
        $fee = $this->feeFor($order);

        $this->assertNotNull($fee);
        $this->assertNotSame($repair->id, $fee->id);
        $this->assertSame(20000, $fee->amount_cents);
        $this->assertSame(2, OrderPayment::where('order_id', $order->id)->count());
    }

    public function test_an_outstanding_repair_charge_is_not_absorbed_into_the_fee(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: true, status: OrderStatus::Delivered->value);

        $repair = OrderPayment::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => PaymentPurpose::Repair,
            'amount_cents' => 119000,
            'currency' => 'eur',
        ]);
        $repair->forceFill(['status' => PaymentStatus::Failed])->save();

        $this->cancelViaAction($order, $customer);

        $this->assertSame(PaymentStatus::Failed, $repair->fresh()->status);
        $this->assertSame(119000, $repair->fresh()->amount_cents);
        $this->assertSame(20000, $this->feeFor($order)->amount_cents);
    }

    // ---- who may cancel -------------------------------------------------------

    public function test_admin_cancelling_an_order_levies_no_fee(): void
    {
        [$order] = $this->openOrder();

        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        // Admin's own route, which is the point: a different entry point that
        // reaches the same status without ever touching the fee.
        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order->id), ['status' => OrderStatus::Cancelled->value])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertNull($this->feeFor($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_admin_cannot_use_the_customer_cancellation_endpoint(): void
    {
        [$order] = $this->openOrder();

        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->postJson(route('orders.cancel', $order->id))->assertNotFound();

        $this->assertSame(OrderStatus::Workshop->value, $order->fresh()->order_status);
        $this->assertNull($this->feeFor($order));
    }

    public function test_a_stranger_cannot_cancel_someone_elses_order(): void
    {
        [$order] = $this->openOrder();

        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($stranger)->postJson(route('orders.cancel', $order->id))->assertNotFound();

        $this->assertSame(OrderStatus::Workshop->value, $order->fresh()->order_status);
        $this->assertNull($this->feeFor($order));
    }

    public function test_an_already_completed_order_cannot_be_cancelled_for_a_fee(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: true, status: OrderStatus::Completed->value);

        $this->cancel($customer, $order)->assertNotFound();

        $this->assertNull($this->feeFor($order));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function uncancellableStatuses(): array
    {
        return [
            // Repairs finished, workshop instructed and paid, repair charge
            // already opened. There is nothing left to call off, and a €200
            // fee for undoing nothing is not a fee.
            'delivered' => [OrderStatus::Delivered->value],
            'completed' => [OrderStatus::Completed->value],
            'cancelled' => [OrderStatus::Cancelled->value],
            'discarded' => [OrderStatus::Discarded->value],
        ];
    }

    #[DataProvider('uncancellableStatuses')]
    public function test_an_order_past_the_point_of_no_return_offers_no_cancellation(string $status): void
    {
        [$order, $customer] = $this->openOrder(withMandate: true, status: $status);

        $this->cancel($customer, $order)->assertNotFound();

        $this->assertSame($status, $order->fresh()->order_status);
        $this->assertNull($this->feeFor($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_a_delivered_order_is_refused_even_though_it_is_not_closed(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: true, status: OrderStatus::Delivered->value);

        // The distinction the rule turns on: `delivered` is an *open* status,
        // so the ordinary open/closed check would have let this through.
        $this->assertNotContains(OrderStatus::Delivered->value, OrderStatus::closedValues());
        $this->assertFalse(OrderStatus::isCustomerCancellable(OrderStatus::Delivered->value));

        $this->cancel($customer, $order)->assertNotFound();

        $this->assertNull($this->feeFor($order));
    }

    public function test_the_settlement_scope_still_reaches_a_cancelled_orders_fee(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: false);

        $this->cancel($customer, $order)->assertOk();

        // Cancelling is now refused, but paying what it produced must not be —
        // the two rules govern different things.
        $this->cancel($customer, $order->fresh())->assertNotFound();
        $this->actingAs($customer)
            ->getJson(route('payments.cancellation-fee.show', $order->id))
            ->assertOk()
            ->assertJsonPath('payable', true);
    }

    // ---- B2B ------------------------------------------------------------------

    public function test_a_b2b_order_has_no_customer_cancellation_and_no_fee(): void
    {
        $company = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => $company->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Workshop->value,
        ]);

        $this->actingAs($company)->postJson(route('orders.cancel', $order->id))->assertNotFound();

        $this->assertSame(OrderStatus::Workshop->value, $order->fresh()->order_status);
        $this->assertNull($this->feeFor($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    // ---- settling it afterwards ------------------------------------------------

    public function test_an_outstanding_fee_can_be_paid_from_the_portal(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: false);

        $this->cancel($customer, $order)->assertOk();

        // Owed on a cancelled — therefore closed — order, which is exactly the
        // case the settlement scope exists for.
        $this->actingAs($customer)
            ->getJson(route('payments.cancellation-fee.show', $order->id))
            ->assertOk()
            ->assertJsonPath('purpose', PaymentPurpose::CancellationFee->value)
            ->assertJsonPath('amount', '200.00')
            ->assertJsonPath('payable', true)
            ->assertJsonPath('settled', false);

        $this->actingAs($customer)
            ->postJson(route('payments.cancellation-fee.intent', $order->id))
            ->assertOk()
            ->assertJsonPath('mode', 'collect');

        $call = $this->stripe->lastCallTo('createPaymentIntent')['arguments'];

        $this->assertSame(20000, $call['amountCents']);
        $this->assertFalse($call['offSession']);
        $this->assertSame('cancellation_fee', $call['metadata']['purpose']);
    }

    public function test_the_fee_checkout_cannot_be_pointed_at_the_repair_charge(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: false, status: OrderStatus::Delivered->value);

        $repair = OrderPayment::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => PaymentPurpose::Repair,
            'amount_cents' => 119000,
            'currency' => 'eur',
        ]);
        $repair->forceFill(['status' => PaymentStatus::Failed])->save();

        $this->cancelViaAction($order, $customer);

        // Each endpoint reports its own obligation and only its own.
        $this->actingAs($customer)
            ->getJson(route('payments.cancellation-fee.show', $order->id))
            ->assertOk()
            ->assertJsonPath('amount_cents', 20000);

        $this->actingAs($customer)
            ->getJson(route('payments.repair.show', $order->id))
            ->assertOk()
            ->assertJsonPath('amount_cents', 119000);
    }

    // ---- the customer's own view -------------------------------------------------

    public function test_the_dashboard_shows_the_cancelled_order_and_its_fee(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: false);

        $this->cancel($customer, $order)->assertOk();

        $payload = [];

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertInertia(function (AssertableInertia $page) use (&$payload) {
                $payload = $page->toArray()['props']['vehicles'][0]['orders'][0];
            });

        $this->assertSame(OrderStatus::Cancelled->value, $payload['order_status']);
        $this->assertSame(20000, $payload['payment']['cancellation_fee']['amount_cents']);
        $this->assertSame(PaymentStatus::RequiresManualCollection->value, $payload['payment']['cancellation_fee']['status']);
        $this->assertTrue($payload['payment']['cancellation_fee']['payable']);
    }

    public function test_admin_sees_the_fee_separately_from_the_repair_charge(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: true, status: OrderStatus::Delivered->value);

        $offer = LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'selected',
            'repair_cost_net' => '1190.00',
            'repair_cost_gross' => '1190.00',
            'depreciation_value_net' => '0.00',
            'depreciation_value_gross' => '0.00',
            'workshop_repair_quote_net' => '0.00',
            'workshop_repair_quote_gross' => '0.00',
            'missing_parts_cost_net' => '0.00',
            'missing_parts_cost_gross' => '0.00',
            'selected_at' => now(),
        ]);

        $repair = OrderPayment::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => PaymentPurpose::Repair,
            'amount_cents' => 119000,
            'currency' => 'eur',
        ]);
        $repair->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now()])->save();

        $this->cancelViaAction($order, $customer);

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertSame(119000, $detail['repair_payment']['amount_cents']);
        $this->assertSame('repair', $detail['repair_payment']['purpose']);
        $this->assertSame(20000, $detail['cancellation_fee']['amount_cents']);
        $this->assertSame('cancellation_fee', $detail['cancellation_fee']['purpose']);

        // The fee holds nothing back: the order it belongs to is already
        // terminal, so there is no vehicle left to withhold.
        $this->assertFalse($detail['cancellation_fee']['blocks_pickup']);
        $this->assertNotNull($offer->fresh());
    }

    public function test_an_unpaid_fee_does_not_block_anything(): void
    {
        [$order, $customer] = $this->openOrder(withMandate: false);

        $this->cancel($customer, $order)->assertOk();

        $fee = $this->feeFor($order);

        $this->assertFalse($fee->blocksRelease());
        $this->assertFalse(PaymentPurpose::CancellationFee->blocksVehicleRelease());
    }

    public function test_a_cancelled_order_reports_no_repair_payment_presentation(): void
    {
        [$order, $customer] = $this->openOrder();

        $this->cancel($customer, $order)->assertOk();

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        // A cancelled order presents as cancelled; an outstanding amount on it
        // is an accounting matter, not a step anyone is being asked to take.
        $this->assertSame(RepairPaymentPresentation::NONE, $detail['repair_payment_stage']);
    }

    public function test_the_status_transition_is_recorded_as_the_customers(): void
    {
        [$order, $customer] = $this->openOrder();

        $this->cancel($customer, $order)->assertOk();

        $this->assertDatabaseHas('leasyback_order_status_updates', [
            'auftragsnummer' => $order->auftragsnummer,
            'new_status' => OrderStatus::Cancelled->value,
            'auth_source' => 'user',
            'updated_by_user_id' => $customer->id,
        ]);
    }

    public function test_cancelling_from_any_open_status_is_allowed(): void
    {
        foreach ([OrderStatus::OrderPlaced, OrderStatus::Confirmed, OrderStatus::Inspected, OrderStatus::Workshop] as $status) {
            [$order, $customer] = $this->openOrder(withMandate: false, status: $status->value);

            $this->cancel($customer, $order)->assertOk();

            $this->assertSame(
                OrderStatus::Cancelled->value,
                $order->fresh()->order_status,
                "Cancelling from {$status->value} must be allowed.",
            );
            $this->assertNotNull($this->feeFor($order));
        }
    }

    public function test_the_transition_action_alone_never_creates_a_fee(): void
    {
        [$order] = $this->openOrder();

        app(TransitionOrderStatus::class)($order, OrderStatus::Cancelled->value, 'test', 'PHPUnit');

        // The fee belongs to the customer's gesture, not to the status — so the
        // single writer of order_status stays entirely unaware of it.
        $this->assertNull($this->feeFor($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }
}
