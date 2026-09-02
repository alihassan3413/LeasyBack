<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\LeasybackOffer as OfferRecord;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\RepairOfferService;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Data\StripePaymentIntentResult;
use App\Modules\UserProfile\Payment\Enums\FeeReason;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Services\B2cFeeDeadlines;
use App\Modules\UserProfile\Payment\Services\B2cFeeService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * The B2C €200 fee: five business triggers, one obligation, one charge.
 */
class B2cFeeTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    private string $stripeCustomerId = 'cus_fee';

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
        Mail::fake();
    }

    /**
     * @return array{0: LeasybackOrder, 1: User}
     */
    private function b2cOrder(
        string $status = OrderStatus::Confirmed->value,
        bool $withMandate = true,
        ?string $termin = null,
    ): array {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $this->stripeCustomerId = 'cus_fee_'.$customer->id;
        $customer->forceFill(['stripe_customer_id' => $this->stripeCustomerId])->save();
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $customer->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
            'request_payload' => $termin === null ? [] : ['besichtigungsort' => ['termin' => $termin]],
        ]);

        if ($withMandate) {
            OrderPaymentMethod::factory()->saved()->create([
                'order_id' => $order->id,
                'stripe_customer_id' => $this->stripeCustomerId,
                'payment_method_id' => 'pm_saved',
            ]);
        }

        return [$order, $customer];
    }

    private function cancel(User $customer, LeasybackOrder $order): TestResponse
    {
        return $this->actingAs($customer)->postJson(route('orders.cancel', $order->id));
    }

    private function fee(LeasybackOrder $order): ?OrderPayment
    {
        return OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::CancellationFee->value)
            ->first();
    }

    private function feeCount(LeasybackOrder $order): int
    {
        return OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::CancellationFee->value)
            ->count();
    }

    private function trigger(LeasybackOrder $order, FeeReason $reason): ?OrderPayment
    {
        return app(B2cFeeService::class)->trigger($order, $reason);
    }

    private function nextChargeLands(string $status, ?string $failureCode = null): void
    {
        $this->stripe->createResults[] = new StripePaymentIntentResult(
            id: 'pi_fee',
            status: $status,
            amount: 20000,
            currency: 'eur',
            clientSecret: 'pi_fee_secret',
            customerId: $this->stripeCustomerId,
            paymentMethodId: 'pm_saved',
            failureCode: $failureCode,
        );
    }

    private function publishedOffer(LeasybackOrder $order, CarbonImmutable $publishedAt): LeasybackOffer
    {
        return LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'published',
            'published_at' => $publishedAt,
            'repair_cost_net' => '1000.00',
            'repair_cost_gross' => '1190.00',
            'depreciation_value_net' => '0.00',
            'depreciation_value_gross' => '0.00',
            'workshop_repair_quote_net' => '0.00',
            'workshop_repair_quote_gross' => '0.00',
            'missing_parts_cost_net' => '0.00',
            'missing_parts_cost_gross' => '0.00',
        ]);
    }

    // ---- 1. TÜV appointment cancellation, 48-hour rule -----------------------

    public function test_cancelling_49_hours_before_the_appointment_costs_nothing(): void
    {
        [$order, $customer] = $this->b2cOrder(termin: now()->addHours(49)->toIso8601String());

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertNull($this->fee($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_cancelling_exactly_48_hours_before_the_appointment_costs_nothing(): void
    {
        [$order, $customer] = $this->b2cOrder(termin: now()->addHours(48)->addSeconds(2)->toIso8601String());

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertNull($this->fee($order));
    }

    public function test_cancelling_47_hours_59_minutes_before_the_appointment_costs_two_hundred(): void
    {
        [$order, $customer] = $this->b2cOrder(termin: now()->addHours(47)->addMinutes(59)->toIso8601String());

        $this->cancel($customer, $order)->assertOk();

        $fee = $this->fee($order);

        $this->assertNotNull($fee);
        $this->assertSame(20000, $fee->amount_cents);
        $this->assertSame(FeeReason::TuvLateCancellation, $fee->trigger_reason);
        $this->assertSame(PaymentStatus::Paid, $fee->status);
    }

    public function test_cancelling_with_no_appointment_on_file_costs_nothing(): void
    {
        [$order, $customer] = $this->b2cOrder();

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertNull($this->fee($order));
    }

    // ---- 2. no-show -----------------------------------------------------------

    public function test_marking_a_no_show_charges_two_hundred(): void
    {
        [$order] = $this->b2cOrder(termin: now()->subHours(2)->toIso8601String());

        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->post(route('admin.orders.no-show', $order->id))->assertRedirect();

        $fee = $this->fee($order);

        $this->assertNotNull($fee);
        $this->assertSame(FeeReason::TuvNoShow, $fee->trigger_reason);
        $this->assertSame(PaymentStatus::Paid, $fee->status);
    }

    public function test_time_passing_alone_never_produces_a_no_show_fee(): void
    {
        [$order] = $this->b2cOrder(termin: now()->subDays(30)->toIso8601String());

        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));
    }

    // ---- 3. explicit offer rejection ------------------------------------------

    public function test_rejecting_the_repair_offer_charges_two_hundred_immediately(): void
    {
        [$order, $customer] = $this->b2cOrder(OrderStatus::Inspected->value);
        $offer = $this->publishedOffer($order, CarbonImmutable::now());

        app(RepairOfferService::class)->reject(OfferRecord::findOrFail($offer->offer_id), $customer, []);

        $fee = $this->fee($order);

        $this->assertNotNull($fee);
        $this->assertSame(FeeReason::RepairOfferRejected, $fee->trigger_reason);
        $this->assertSame(PaymentStatus::Paid, $fee->status);
    }

    // ---- 4. offer sent, no response -------------------------------------------

    public function test_no_response_at_13_days_23_hours_costs_nothing(): void
    {
        [$order] = $this->b2cOrder(OrderStatus::Inspected->value);
        $this->publishedOffer($order, CarbonImmutable::now()->subDays(13)->subHours(23));

        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));
    }

    public function test_no_response_at_14_days_charges_two_hundred(): void
    {
        [$order] = $this->b2cOrder(OrderStatus::Inspected->value);
        $this->publishedOffer($order, CarbonImmutable::now()->subDays(14)->subMinute());

        app(B2cFeeDeadlines::class)->process();

        $fee = $this->fee($order);

        $this->assertNotNull($fee);
        $this->assertSame(FeeReason::RepairOfferNoResponse, $fee->trigger_reason);
    }

    public function test_accepting_before_the_deadline_stops_the_timer(): void
    {
        [$order] = $this->b2cOrder(OrderStatus::Inspected->value);
        $offer = $this->publishedOffer($order, CarbonImmutable::now()->subDays(20));

        $offer->update(['offer_status' => 'selected', 'selected_at' => now()]);

        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));
    }

    public function test_rejecting_before_the_deadline_leaves_only_the_rejection_fee(): void
    {
        [$order, $customer] = $this->b2cOrder(OrderStatus::Inspected->value);
        $offer = $this->publishedOffer($order, CarbonImmutable::now()->subDays(20));

        app(RepairOfferService::class)->reject(OfferRecord::findOrFail($offer->offer_id), $customer, []);
        app(B2cFeeDeadlines::class)->process();

        $this->assertSame(1, $this->feeCount($order));
        $this->assertSame(FeeReason::RepairOfferRejected, $this->fee($order)->trigger_reason);
    }

    // ---- 5. accepted offer, repair never starts --------------------------------

    /**
     * @return array{0: LeasybackOrder, 1: User}
     */
    private function commissionedOrder(string $repairStart): array
    {
        [$order, $customer] = $this->b2cOrder(OrderStatus::WorkshopCommissioned->value);

        DB::table('leasyback_order_logistics')->insert([
            'id' => (string) Str::uuid(),
            'auftragsnummer' => $order->auftragsnummer,
            'confirmed_repair_start_date' => $repairStart,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$order, $customer];
    }

    public function test_a_missed_workshop_appointment_charges_nothing_immediately(): void
    {
        [$order] = $this->commissionedOrder(now()->subDay()->toDateString());

        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_inactivity_before_14_days_charges_nothing(): void
    {
        [$order] = $this->commissionedOrder(now()->subDays(13)->toDateString());

        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));
    }

    public function test_inactivity_at_14_days_charges_two_hundred(): void
    {
        [$order] = $this->commissionedOrder(now()->subDays(14)->toDateString());

        app(B2cFeeDeadlines::class)->process();

        $fee = $this->fee($order);

        $this->assertNotNull($fee);
        $this->assertSame(FeeReason::RepairInactivity, $fee->trigger_reason);
    }

    public function test_proceeding_with_the_repair_stops_the_inactivity_timer(): void
    {
        [$order] = $this->commissionedOrder(now()->subDays(30)->toDateString());

        app(TransitionOrderStatus::class)($order, OrderStatus::Workshop->value, 'test', 'PHPUnit');

        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));
    }

    // ---- explicit stop after accepting the offer ---------------------------------

    /**
     * @return array{0: LeasybackOrder, 1: User}
     */
    private function acceptedOrder(string $status = OrderStatus::WorkshopCommissioned->value): array
    {
        [$order, $customer] = $this->b2cOrder($status);

        LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'selected',
            'selected_at' => now(),
            'repair_cost_net' => '1000.00',
            'repair_cost_gross' => '1190.00',
            'depreciation_value_net' => '0.00',
            'depreciation_value_gross' => '0.00',
            'workshop_repair_quote_net' => '0.00',
            'workshop_repair_quote_gross' => '0.00',
            'missing_parts_cost_net' => '0.00',
            'missing_parts_cost_gross' => '0.00',
        ]);

        return [$order, $customer];
    }

    private function insertRepairAppointment(LeasybackOrder $order, string $date): void
    {
        DB::table('leasyback_order_logistics')->insert([
            'id' => (string) Str::uuid(),
            'auftragsnummer' => $order->auftragsnummer,
            'confirmed_repair_start_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMessage(LeasybackOrder $order, bool $fromAdmin, string $at): void
    {
        DB::table('order_messages')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'sender_id' => null,
            'sender_name' => $fromAdmin ? 'LeasyBack' : 'Kunde',
            'sender_is_admin' => $fromAdmin,
            'body' => 'Nachricht',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function test_cancelling_after_accepting_the_offer_charges_immediately(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->cancel($customer, $order)->assertOk();

        $fee = $this->fee($order);

        $this->assertNotNull($fee);
        $this->assertSame(FeeReason::RepairCancelledAfterAcceptance, $fee->trigger_reason);
        $this->assertSame(PaymentStatus::Paid, $fee->status);
        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->order_status);
    }

    public function test_cancelling_after_acceptance_charges_even_with_a_distant_appointment(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $order->update(['request_payload' => ['besichtigungsort' => ['termin' => now()->addMonth()->toIso8601String()]]]);

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(FeeReason::RepairCancelledAfterAcceptance, $this->fee($order)->trigger_reason);
    }

    public function test_an_explicit_stop_then_the_scheduler_still_produces_one_fee(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->insertRepairAppointment($order, now()->subDays(30)->toDateString());

        $this->cancel($customer, $order)->assertOk();

        app(B2cFeeDeadlines::class)->process();

        $this->assertSame(1, $this->feeCount($order));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    // ---- an unpaid fee keeps the case open and recoverable -------------------------

    public function test_an_unpaid_fee_leaves_the_case_open_for_recovery(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_ACTION);

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(PaymentStatus::RequiresAction, $this->fee($order)->status);
        $this->assertNotSame(OrderStatus::Completed->value, $order->fresh()->order_status);
        $this->assertNotContains($order->fresh()->order_status, OrderStatus::closedValues());

        $this->actingAs($customer)
            ->getJson(route('payments.cancellation-fee.show', $order->id))
            ->assertOk()
            ->assertJsonPath('payable', true);
    }

    public function test_a_failed_fee_leaves_the_case_open(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD, 'card_declined');

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(PaymentStatus::Failed, $this->fee($order)->status);
        $this->assertNotSame(OrderStatus::Completed->value, $order->fresh()->order_status);
    }

    public function test_a_manual_collection_fee_leaves_the_case_open(): void
    {
        [$order, $customer] = $this->b2cOrder(OrderStatus::WorkshopCommissioned->value, withMandate: false);

        LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'selected',
            'selected_at' => now(),
            'repair_cost_net' => '1000.00',
            'repair_cost_gross' => '1190.00',
            'depreciation_value_net' => '0.00',
            'depreciation_value_gross' => '0.00',
            'workshop_repair_quote_net' => '0.00',
            'workshop_repair_quote_gross' => '0.00',
            'missing_parts_cost_net' => '0.00',
            'missing_parts_cost_gross' => '0.00',
        ]);

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame(PaymentStatus::RequiresManualCollection, $this->fee($order)->status);
        $this->assertNotSame(OrderStatus::Completed->value, $order->fresh()->order_status);
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_a_stopped_case_shows_only_the_outstanding_fee_task(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD, 'card_declined');
        $this->cancel($customer, $order)->assertOk();

        $tasks = app(AdminQueryService::class)->orderDetail($order->id)['tasks'];

        $this->assertSame('await_cancellation_fee', $tasks['next']['key']);
        $this->assertFalse($tasks['is_closed']);
    }

    public function test_paying_an_outstanding_fee_later_closes_the_case(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD, 'card_declined');
        $this->cancel($customer, $order)->assertOk();

        $this->assertNotSame(OrderStatus::Completed->value, $order->fresh()->order_status);

        $intentId = OrderPaymentIntent::where('payment_id', $this->fee($order)->id)->value('payment_intent_id');

        $this->stripe->webhookEvent = [
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => $intentId,
                'status' => OrderPaymentIntent::STRIPE_SUCCEEDED,
                'amount' => 20000,
                'currency' => 'eur',
                'customer' => $this->stripeCustomerId,
                'metadata' => ['order_id' => $order->id],
            ]],
        ];

        $this->postJson('/api/webhooks/stripe', $this->stripe->webhookEvent, ['Stripe-Signature' => 't=1,v1=fake'])->assertOk();

        $this->assertSame(PaymentStatus::Paid, $this->fee($order)->status);
        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->order_status);
    }

    // ---- the 14-day window only runs without an explicit stop ----------------------

    public function test_a_missed_workshop_appointment_alone_charges_nothing(): void
    {
        [$order] = $this->acceptedOrder();

        $this->insertRepairAppointment($order, now()->subDay()->toDateString());

        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));
    }

    public function test_customer_contact_inside_the_window_pushes_the_deadline_back(): void
    {
        [$order] = $this->commissionedOrder(now()->subDays(20)->toDateString());

        $this->insertMessage($order, fromAdmin: false, at: now()->subDay()->toDateTimeString());

        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));
    }

    public function test_an_admin_chasing_a_silent_customer_does_not_push_the_deadline_back(): void
    {
        [$order] = $this->commissionedOrder(now()->subDays(20)->toDateString());

        $this->insertMessage($order, fromAdmin: true, at: now()->subDay()->toDateTimeString());

        app(B2cFeeDeadlines::class)->process();

        $this->assertSame(FeeReason::RepairInactivity, $this->fee($order)->trigger_reason);
    }

    // ---- QA: the cancellation preview drives the modal ---------------------------

    /**
     * @return array<string, mixed>
     */
    private function customerOrderPayload(User $customer): array
    {
        $payload = [];

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertInertia(function (AssertableInertia $page) use (&$payload) {
                $payload = $page->toArray()['props']['vehicles'][0]['current_order'];
            });

        return $payload;
    }

    public function test_an_accepted_offer_warns_about_the_fee_even_with_a_distant_appointment(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $order->update(['request_payload' => ['besichtigungsort' => ['termin' => now()->addMonth()->toIso8601String()]]]);

        $preview = $this->customerOrderPayload($customer)['payment']['cancellation'];

        $this->assertTrue($preview['fee_applies']);
        $this->assertSame(FeeReason::RepairCancelledAfterAcceptance->value, $preview['fee_reason']);
        $this->assertSame(20000, $preview['fee_amount_cents']);
        $this->assertStringNotContainsString('keine Gebühren', $preview['message']);
        $this->assertStringContainsString('200,00', $preview['message']);
    }

    public function test_an_early_cancellation_without_an_accepted_offer_promises_no_fee(): void
    {
        [, $customer] = $this->b2cOrder(termin: now()->addDays(5)->toIso8601String());

        $preview = $this->customerOrderPayload($customer)['payment']['cancellation'];

        $this->assertFalse($preview['fee_applies']);
        $this->assertSame(0, $preview['fee_amount_cents']);
        $this->assertNull($preview['fee_reason']);
        $this->assertStringContainsString('keine Gebühren', $preview['message']);
    }

    public function test_a_late_appointment_without_an_accepted_offer_warns_about_the_fee(): void
    {
        [, $customer] = $this->b2cOrder(termin: now()->addHours(5)->toIso8601String());

        $preview = $this->customerOrderPayload($customer)['payment']['cancellation'];

        $this->assertTrue($preview['fee_applies']);
        $this->assertSame(FeeReason::TuvLateCancellation->value, $preview['fee_reason']);
    }

    public function test_the_preview_and_the_charge_always_agree(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $order->update(['request_payload' => ['besichtigungsort' => ['termin' => now()->addMonth()->toIso8601String()]]]);

        $preview = $this->customerOrderPayload($customer)['payment']['cancellation'];

        $this->cancel($customer, $order)->assertOk();

        $this->assertSame($preview['fee_reason'], $this->fee($order)->trigger_reason->value);
    }

    // ---- QA: the Admin no-show action --------------------------------------------

    public function test_the_no_show_action_is_offered_only_at_the_appointment_stage(): void
    {
        [$confirmed] = $this->b2cOrder(OrderStatus::Confirmed->value, termin: now()->subHour()->toIso8601String());
        [$inspected] = $this->b2cOrder(OrderStatus::Inspected->value);

        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->assertSame(OrderStatus::Confirmed->value, app(AdminQueryService::class)->orderDetail($confirmed->id)['order_status']);
        $this->assertSame(OrderStatus::Inspected->value, app(AdminQueryService::class)->orderDetail($inspected->id)['order_status']);

        $this->actingAs($admin)->post(route('admin.orders.no-show', $confirmed->id))->assertRedirect();

        $this->assertNotNull($this->fee($confirmed));
    }

    public function test_the_no_show_endpoint_creates_exactly_one_fee(): void
    {
        [$order] = $this->b2cOrder(termin: now()->subHour()->toIso8601String());

        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($admin)->post(route('admin.orders.no-show', $order->id))->assertRedirect();
        }

        $this->assertSame(1, $this->feeCount($order));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(FeeReason::TuvNoShow, $this->fee($order)->trigger_reason);
    }

    public function test_a_b2b_order_is_refused_the_no_show_action(): void
    {
        $company = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => $company->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Confirmed->value,
        ]);

        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->post(route('admin.orders.no-show', $order->id))->assertRedirect();

        $this->assertNull($this->fee($order));
    }

    // ---- QA: the customer can reject a published offer -----------------------------

    public function test_a_published_offer_reaches_the_customer_payload_as_actionable(): void
    {
        [$order, $customer] = $this->b2cOrder(OrderStatus::Inspected->value);
        $this->publishedOffer($order, CarbonImmutable::now());

        $payload = $this->customerOrderPayload($customer);

        $this->assertNotEmpty($payload['offers']);
        $this->assertSame('published', $payload['offers'][0]['offer_status']);
    }

    public function test_rejecting_from_the_customer_endpoint_triggers_the_fee(): void
    {
        [$order, $customer] = $this->b2cOrder(OrderStatus::Inspected->value);
        $offer = $this->publishedOffer($order, CarbonImmutable::now());

        $this->actingAs($customer)
            ->from('/dashboard')
            ->post(route('offers.reject', $offer->offer_id), ['customer_comment' => 'Zu teuer'])
            ->assertSessionHasNoErrors();

        $fee = $this->fee($order);

        $this->assertNotNull($fee);
        $this->assertSame(FeeReason::RepairOfferRejected, $fee->trigger_reason);
        $this->assertSame(1, $this->feeCount($order));
    }

    // ---- QA: the timeline stops where the process stopped ---------------------------

    public function test_a_stopped_case_carries_the_fee_into_the_customer_payload(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD, 'card_declined');
        $this->cancel($customer, $order)->assertOk();

        $fee = $this->customerOrderPayload($customer)['payment']['cancellation_fee'];

        $this->assertSame(PaymentStatus::Failed->value, $fee['status']);
        $this->assertSame(20000, $fee['amount_cents']);
        $this->assertSame(FeeReason::RepairCancelledAfterAcceptance->label(), $fee['trigger_label']);
        $this->assertTrue($fee['payable']);
    }

    public function test_a_settled_fee_carries_a_paid_status_into_the_customer_payload(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->cancel($customer, $order)->assertOk();

        $payload = $this->customerOrderPayload($customer);

        $this->assertSame(PaymentStatus::Paid->value, $payload['payment']['cancellation_fee']['status']);
        $this->assertSame(OrderStatus::Completed->value, $payload['order_status']);
    }

    public function test_a_stopped_case_never_reaches_the_repair_statuses(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_ACTION);
        $this->cancel($customer, $order)->assertOk();

        // The timeline is derived from these two facts, and neither may claim a
        // repair stage the order never reached.
        $this->assertSame(OrderStatus::WorkshopCommissioned->value, $order->fresh()->order_status);
        $this->assertDatabaseMissing('leasyback_order_status_updates', [
            'auftragsnummer' => $order->auftragsnummer,
            'new_status' => OrderStatus::Workshop->value,
        ]);
        $this->assertDatabaseMissing('leasyback_order_status_updates', [
            'auftragsnummer' => $order->auftragsnummer,
            'new_status' => OrderStatus::Delivered->value,
        ]);
    }

    public function test_the_admin_payload_exposes_the_fee_and_its_reason(): void
    {
        [$order, $customer] = $this->acceptedOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_ACTION);
        $this->cancel($customer, $order)->assertOk();

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertSame(20000, $detail['cancellation_fee']['amount_cents']);
        $this->assertSame(FeeReason::RepairCancelledAfterAcceptance->value, $detail['cancellation_fee']['trigger_reason']);
        $this->assertSame(FeeReason::RepairCancelledAfterAcceptance->label(), $detail['cancellation_fee']['trigger_label']);
        $this->assertNotNull($detail['cancellation_fee']['triggered_at']);
    }

    public function test_a_b2b_order_carries_no_cancellation_preview(): void
    {
        $company = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => $company->id]);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Confirmed->value,
        ]);

        $detail = app(AdminQueryService::class)->orderDetail(
            LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->firstOrFail()->id,
        );

        $this->assertNull($detail['cancellation_fee']);
        $this->assertNull($detail['repair_payment']);
    }

    // ---- idempotency ------------------------------------------------------------

    public function test_several_different_triggers_produce_one_obligation(): void
    {
        [$order] = $this->b2cOrder();

        $this->trigger($order, FeeReason::TuvLateCancellation);
        $this->trigger($order, FeeReason::TuvNoShow);
        $this->trigger($order, FeeReason::RepairOfferRejected);

        $this->assertSame(1, $this->feeCount($order));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(FeeReason::TuvLateCancellation, $this->fee($order)->trigger_reason);
    }

    public function test_back_to_back_triggers_produce_one_obligation(): void
    {
        [$order] = $this->b2cOrder();

        $first = $this->trigger($order, FeeReason::TuvNoShow);
        $second = $this->trigger($order->fresh(), FeeReason::TuvNoShow);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->feeCount($order));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_repeated_scheduler_runs_never_duplicate_a_fee(): void
    {
        [$order] = $this->b2cOrder(OrderStatus::Inspected->value);
        $this->publishedOffer($order, CarbonImmutable::now()->subDays(20));

        foreach (range(1, 3) as $ignored) {
            app(B2cFeeDeadlines::class)->process();
        }

        $this->assertSame(1, $this->feeCount($order));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_a_replayed_webhook_never_charges_twice(): void
    {
        [$order] = $this->b2cOrder();

        $this->trigger($order, FeeReason::TuvNoShow);

        $fee = $this->fee($order);
        $paidAt = $fee->paid_at;
        $intentId = OrderPaymentIntent::where('payment_id', $fee->id)->value('payment_intent_id');

        foreach (range(1, 3) as $ignored) {
            $this->stripe->webhookEvent = [
                'type' => 'payment_intent.succeeded',
                'data' => ['object' => [
                    'id' => $intentId,
                    'status' => OrderPaymentIntent::STRIPE_SUCCEEDED,
                    'amount' => 20000,
                    'currency' => 'eur',
                    'customer' => $this->stripeCustomerId,
                    'metadata' => ['order_id' => $order->id],
                ]],
            ];

            $this->postJson('/api/webhooks/stripe', $this->stripe->webhookEvent, ['Stripe-Signature' => 't=1,v1=fake'])->assertOk();
        }

        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(1, OrderPaymentIntent::where('payment_id', $fee->id)->count());
        $this->assertEquals($paidAt, $fee->fresh()->paid_at);
    }

    public function test_a_paid_fee_makes_every_later_trigger_a_no_op(): void
    {
        [$order] = $this->b2cOrder();

        $this->trigger($order, FeeReason::TuvNoShow);
        $this->assertSame(PaymentStatus::Paid, $this->fee($order)->status);

        $this->trigger($order->fresh(), FeeReason::RepairOfferRejected);
        $this->trigger($order->fresh(), FeeReason::RepairInactivity);

        $this->assertSame(1, $this->feeCount($order));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    // ---- Stripe outcomes and case completion -------------------------------------

    public function test_a_successful_charge_closes_the_case(): void
    {
        [$order] = $this->b2cOrder();

        $this->trigger($order, FeeReason::TuvNoShow);

        $this->assertSame(PaymentStatus::Paid, $this->fee($order)->status);
        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->order_status);
    }

    public function test_authentication_required_leaves_the_case_open(): void
    {
        [$order] = $this->b2cOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_ACTION);
        $this->trigger($order, FeeReason::TuvNoShow);

        $this->assertSame(PaymentStatus::RequiresAction, $this->fee($order)->status);
        $this->assertSame(OrderStatus::Confirmed->value, $order->fresh()->order_status);
    }

    public function test_a_decline_leaves_the_case_open(): void
    {
        [$order] = $this->b2cOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD, 'card_declined');
        $this->trigger($order, FeeReason::TuvNoShow);

        $this->assertSame(PaymentStatus::Failed, $this->fee($order)->status);
        $this->assertSame(OrderStatus::Confirmed->value, $order->fresh()->order_status);
    }

    public function test_processing_leaves_the_case_open(): void
    {
        [$order] = $this->b2cOrder();

        $this->nextChargeLands(OrderPaymentIntent::STRIPE_PROCESSING);
        $this->trigger($order, FeeReason::TuvNoShow);

        $this->assertSame(PaymentStatus::Processing, $this->fee($order)->status);
        $this->assertSame(OrderStatus::Confirmed->value, $order->fresh()->order_status);
    }

    public function test_no_mandate_produces_a_manual_obligation_and_no_intent(): void
    {
        [$order] = $this->b2cOrder(withMandate: false);

        $this->trigger($order, FeeReason::TuvNoShow);

        $fee = $this->fee($order);

        $this->assertSame(PaymentStatus::RequiresManualCollection, $fee->status);
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(0, OrderPaymentIntent::where('payment_id', $fee->id)->count());
        $this->assertSame(OrderStatus::Confirmed->value, $order->fresh()->order_status);
    }

    public function test_a_later_recovery_closes_the_case(): void
    {
        [$order, $customer] = $this->b2cOrder(withMandate: false);

        $this->trigger($order, FeeReason::TuvNoShow);

        $this->actingAs($customer)
            ->postJson(route('payments.cancellation-fee.intent', $order->id))
            ->assertOk();

        $intentId = OrderPaymentIntent::where('payment_id', $this->fee($order)->id)->value('payment_intent_id');

        $this->stripe->givenPaymentIntent(new StripePaymentIntentResult(
            id: $intentId,
            status: OrderPaymentIntent::STRIPE_SUCCEEDED,
            amount: 20000,
            currency: 'eur',
            clientSecret: $intentId.'_secret',
            customerId: $this->stripeCustomerId,
        ));

        $this->actingAs($customer)
            ->postJson(route('payments.cancellation-fee.sync', $order->id))
            ->assertOk()
            ->assertJsonPath('settled', true);

        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->order_status);
    }

    public function test_a_completed_case_cannot_trigger_another_fee(): void
    {
        [$order] = $this->b2cOrder();

        $this->trigger($order, FeeReason::TuvNoShow);
        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->order_status);

        $this->trigger($order->fresh(), FeeReason::RepairInactivity);

        $this->assertSame(1, $this->feeCount($order));
    }

    public function test_a_closed_case_raises_no_further_open_tasks(): void
    {
        [$order] = $this->b2cOrder();

        $this->trigger($order, FeeReason::TuvNoShow);

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertTrue($detail['tasks']['is_closed']);
        $this->assertNull($detail['tasks']['next']);
    }

    // ---- audit --------------------------------------------------------------------

    public function test_the_trigger_is_auditable_beyond_the_log_file(): void
    {
        [$order] = $this->b2cOrder(termin: now()->addHours(2)->toIso8601String());

        $fee = $this->trigger($order, FeeReason::TuvLateCancellation);

        $this->assertSame(FeeReason::TuvLateCancellation, $fee->trigger_reason);
        $this->assertNotNull($fee->triggered_at);

        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'order_id' => $order->id,
            'action' => 'b2c_fee_triggered',
        ]);

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertSame(FeeReason::TuvLateCancellation->value, $detail['cancellation_fee']['trigger_reason']);
        $this->assertNotNull($detail['cancellation_fee']['triggered_at']);
        $this->assertSame(20000, $detail['cancellation_fee']['amount_cents']);
    }

    public function test_an_ignored_trigger_is_still_recorded(): void
    {
        [$order] = $this->b2cOrder();

        $this->trigger($order, FeeReason::TuvNoShow);
        $this->trigger($order->fresh(), FeeReason::RepairOfferRejected);

        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'order_id' => $order->id,
            'action' => 'b2c_fee_trigger_ignored',
        ]);
    }

    // ---- regression ------------------------------------------------------------------

    public function test_a_normal_repair_completion_never_produces_a_fee(): void
    {
        [$order] = $this->b2cOrder(OrderStatus::Reinspection->value);

        LeasybackOffer::factory()->create([
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

        app(TransitionOrderStatus::class)($order, OrderStatus::Delivered->value, 'test', 'PHPUnit');
        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));

        $repair = OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::Repair->value)
            ->first();

        $this->assertSame(PaymentStatus::Paid, $repair->status);

        app(TransitionOrderStatus::class)($order->fresh(), OrderStatus::Completed->value, 'test', 'PHPUnit');

        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->order_status);
    }

    public function test_the_fee_never_touches_the_repair_charge(): void
    {
        [$order] = $this->b2cOrder(OrderStatus::Delivered->value);

        $repair = OrderPayment::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => PaymentPurpose::Repair,
            'amount_cents' => 119000,
            'currency' => 'eur',
        ]);
        $repair->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now()->subDay()])->save();

        $before = $repair->fresh()->toArray();

        $this->trigger($order, FeeReason::RepairInactivity);

        $this->assertSame($before, $repair->fresh()->toArray());
        $this->assertSame(20000, $this->fee($order)->amount_cents);
        $this->assertSame(2, OrderPayment::where('order_id', $order->id)->count());
    }

    public function test_a_b2b_order_never_gets_the_fee(): void
    {
        $company = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => $company->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Inspected->value,
        ]);

        $this->publishedOffer($order, CarbonImmutable::now()->subDays(30));

        $this->assertNull($this->trigger($order, FeeReason::TuvNoShow));

        app(B2cFeeDeadlines::class)->process();

        $this->assertNull($this->fee($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_admin_cancelling_an_order_never_triggers_the_fee(): void
    {
        [$order] = $this->b2cOrder(termin: now()->addHour()->toIso8601String());

        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order->id), ['status' => OrderStatus::Cancelled->value])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
        $this->assertNull($this->fee($order));
    }

    public function test_the_transition_action_alone_never_triggers_the_fee(): void
    {
        [$order] = $this->b2cOrder(termin: now()->addHour()->toIso8601String());

        app(TransitionOrderStatus::class)($order, OrderStatus::Cancelled->value, 'test', 'PHPUnit');

        $this->assertNull($this->fee($order));
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
    }

    public function test_a_stranger_cannot_cancel_someone_elses_order(): void
    {
        [$order] = $this->b2cOrder(termin: now()->addHour()->toIso8601String());

        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($stranger)->postJson(route('orders.cancel', $order->id))->assertNotFound();

        $this->assertNull($this->fee($order));
    }
}
