<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Mail\Orders\RepairPaymentReceivedMail;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Repair-charge webhooks: resolution by intent id, and the guarantee that a
 * settled payment never moves backwards.
 */
class RepairPaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
        Mail::fake();
    }

    /**
     * @return array{0: User, 1: LeasybackOrder, 2: OrderPayment, 3: OrderPaymentIntent}
     */
    private function pendingRepairCharge(): array
    {
        $customer = User::factory()->create([
            'user_type' => UserType::Privatkunde,
            'stripe_customer_id' => 'cus_repair',
        ]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $customer->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Delivered->value,
        ]);

        OrderPaymentMethod::factory()->saved()->create([
            'order_id' => $order->id,
            'stripe_customer_id' => 'cus_repair',
        ]);

        $payment = OrderPayment::factory()->repair()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'amount_cents' => 119000,
            'status' => PaymentStatus::Processing,
            'notified_status' => PaymentStatus::Processing->value,
            'intent_count' => 1,
        ]);

        $intent = OrderPaymentIntent::factory()->processing()->create([
            'payment_id' => $payment->id,
            'sequence' => 1,
            'payment_intent_id' => 'pi_repair',
        ]);

        return [$customer, $order, $payment, $intent];
    }

    private function sendEvent(string $type, array $objectOverrides = [], ?string $customerId = 'cus_repair'): TestResponse
    {
        $event = [
            'id' => 'evt_repair',
            'type' => $type,
            'data' => ['object' => [
                'id' => 'pi_repair',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 119000,
                'currency' => 'eur',
                'customer' => $customerId,
                'payment_method' => 'pm_saved',
                'metadata' => ['purpose' => 'repair'],
                ...$objectOverrides,
            ]],
        ];

        $this->stripe->webhookEvent = $event;

        return $this->postJson(route('webhooks.stripe'), $event, ['Stripe-Signature' => 't=1,v1=fake']);
    }

    public function test_succeeded_marks_the_repair_paid_and_sends_the_pickup_mail(): void
    {
        [, , $payment] = $this->pendingRepairCharge();

        $this->sendEvent('payment_intent.succeeded')->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        Mail::assertQueued(RepairPaymentReceivedMail::class, 1);
    }

    /**
     * The event's object status is `requires_payment_method`, so the event name
     * is what identifies a decline — reading the status alone would be right by
     * accident here and wrong for a genuine retry.
     */
    public function test_payment_failed_marks_the_repair_failed(): void
    {
        [, , $payment, $intent] = $this->pendingRepairCharge();

        $this->sendEvent('payment_intent.payment_failed', [
            'status' => 'requires_payment_method',
            'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
        ])->assertOk();

        $payment->refresh();
        $intent->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('card_declined', $intent->failure_code);
        Mail::assertNotQueued(RepairPaymentReceivedMail::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('intentEventOutcomes')]
    public function test_each_intent_event_maps_to_its_payment_status(string $type, array $overrides, PaymentStatus $expected): void
    {
        [, , $payment] = $this->pendingRepairCharge();

        $this->sendEvent($type, $overrides)->assertOk();

        $this->assertSame($expected, $payment->fresh()->status);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, PaymentStatus}>
     */
    public static function intentEventOutcomes(): array
    {
        return [
            'succeeded' => ['payment_intent.succeeded', ['status' => 'succeeded'], PaymentStatus::Paid],
            'requires_action' => ['payment_intent.requires_action', ['status' => 'requires_action'], PaymentStatus::RequiresAction],
            'processing stays' => ['payment_intent.processing', ['status' => 'processing'], PaymentStatus::Processing],
            'canceled' => ['payment_intent.canceled', ['status' => 'canceled'], PaymentStatus::Cancelled],
        ];
    }

    /**
     * Stripe redelivers, and events can arrive out of order. Neither may
     * un-pay a settled repair.
     */
    public function test_a_late_failure_cannot_move_a_paid_repair_backwards(): void
    {
        [, , $payment] = $this->pendingRepairCharge();

        $this->sendEvent('payment_intent.succeeded')->assertOk();
        $this->sendEvent('payment_intent.payment_failed', [
            'status' => 'requires_payment_method',
            'last_payment_error' => ['code' => 'card_declined', 'message' => 'stale'],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    /**
     * The late failure is still recorded on the intent it describes — refusing
     * the payment-level move must not erase the attempt's own history.
     */
    public function test_a_refused_move_still_records_the_failure_on_the_intent(): void
    {
        [, , , $intent] = $this->pendingRepairCharge();

        $this->sendEvent('payment_intent.succeeded')->assertOk();
        $this->sendEvent('payment_intent.payment_failed', [
            'status' => 'requires_payment_method',
            'last_payment_error' => ['code' => 'expired_card', 'message' => 'stale'],
        ])->assertOk();

        $this->assertSame('expired_card', $intent->fresh()->failure_code);
    }

    public function test_a_replayed_success_sends_only_one_pickup_mail(): void
    {
        $this->pendingRepairCharge();

        $this->sendEvent('payment_intent.succeeded')->assertOk();
        $this->sendEvent('payment_intent.succeeded')->assertOk();

        Mail::assertQueued(RepairPaymentReceivedMail::class, 1);
    }

    public function test_an_unknown_payment_intent_is_ignored_with_200(): void
    {
        [, , $payment] = $this->pendingRepairCharge();

        $this->sendEvent('payment_intent.succeeded', ['id' => 'pi_never_seen'])->assertOk();

        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
    }

    public function test_a_customer_that_is_not_the_order_owner_is_ignored(): void
    {
        [, , $payment] = $this->pendingRepairCharge();

        $this->sendEvent('payment_intent.succeeded', [], customerId: 'cus_a_stranger')->assertOk();

        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
    }

    public function test_metadata_naming_another_order_is_ignored(): void
    {
        [, , $payment] = $this->pendingRepairCharge();
        $other = LeasybackOrder::factory()->create();

        $this->sendEvent('payment_intent.succeeded', [
            'metadata' => ['order_id' => $other->id, 'purpose' => 'repair'],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
    }
}
