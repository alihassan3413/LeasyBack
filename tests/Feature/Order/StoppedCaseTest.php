<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\FeeReason;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Services\B2cFeeService;
use App\Modules\UserProfile\Payment\Services\PaymentService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A case stopped by a fee — a rejected offer or a TÜV no-show — and what the
 * surfaces are allowed to say about it afterwards.
 *
 * Settling the fee closes the order as `completed`, which on its own reads as a
 * repair that ran to the end. The timeline only knows better because the
 * payload tells it a fee exists: both customer pages were building their flow
 * context without `cancellation_fee`, so a stopped case rendered every rung
 * green — workshop commissioned, in repair, follow-up done, ready for pickup.
 * These pin the input that fix depends on.
 */
class StoppedCaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ------------------------------------------- the payload the timeline reads

    public function test_the_customer_order_page_reports_a_no_show_fee(): void
    {
        [$order, $customer] = $this->b2cOrder();

        app(B2cFeeService::class)->trigger($order, FeeReason::TuvNoShow);

        $payment = $this->orderPagePayment($customer, $order);

        $this->assertNotNull($payment['cancellation_fee']);
        $this->assertSame(20000, $payment['cancellation_fee']['amount_cents']);
        $this->assertSame('Termin nicht wahrgenommen', $payment['cancellation_fee']['trigger_label']);
    }

    public function test_the_customer_vehicle_page_reports_the_fee_too(): void
    {
        [$order, $customer] = $this->b2cOrder();

        app(B2cFeeService::class)->trigger($order, FeeReason::TuvNoShow);

        $this->assertNotNull($this->vehiclePagePayment($customer, $order)['cancellation_fee']);
    }

    public function test_the_fee_survives_the_order_being_closed_by_it(): void
    {
        [$order, $customer] = $this->b2cOrder();

        $fee = app(B2cFeeService::class)->trigger($order, FeeReason::RepairOfferRejected);
        $this->settle($fee);

        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->order_status);

        $payment = $this->orderPagePayment($customer, $order);

        $this->assertSame(PaymentStatus::Paid->value, $payment['cancellation_fee']['status']);
    }

    public function test_an_order_with_no_fee_reports_none(): void
    {
        [$order, $customer] = $this->b2cOrder();

        $this->assertNull($this->orderPagePayment($customer, $order)['cancellation_fee']);
    }

    // ------------------------------------------------ publishing into a dead case

    public function test_an_offer_cannot_be_published_once_a_stopped_case_has_closed(): void
    {
        [$order] = $this->b2cOrder();

        $fee = app(B2cFeeService::class)->trigger($order, FeeReason::RepairOfferRejected);
        $this->settle($fee);

        $offer = $this->draftOffer($order);

        $this->publish($offer)->assertSessionHasErrors('offer');

        $this->assertSame('draft', $offer->fresh()->offer_status);
    }

    public function test_an_offer_cannot_be_published_on_a_cancelled_order(): void
    {
        [$order] = $this->b2cOrder();
        $order->forceFill(['order_status' => OrderStatus::Cancelled->value])->save();

        $offer = $this->draftOffer($order);

        $this->publish($offer)->assertSessionHasErrors('offer');

        $this->assertSame('draft', $offer->fresh()->offer_status);
    }

    public function test_a_running_order_still_publishes_normally(): void
    {
        [$order] = $this->b2cOrder();

        $offer = $this->draftOffer($order);

        $this->publish($offer)->assertRedirect();

        $this->assertSame('published', $offer->fresh()->offer_status);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function orderPagePayment(User $customer, LeasybackOrder $order): array
    {
        $payload = [];

        $this->actingAs($customer)
            ->get(route('orders.show', $order->id))
            ->assertInertia(function (AssertableInertia $page) use (&$payload) {
                $payload = $page->toArray()['props']['order']['payment'];
            });

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function vehiclePagePayment(User $customer, LeasybackOrder $order): array
    {
        $payload = [];

        $this->actingAs($customer)
            ->get(route('vehicles.show', $order->vehicle_id))
            ->assertInertia(function (AssertableInertia $page) use (&$payload) {
                $payload = $page->toArray()['props']['vehicle']['current_order']['payment'];
            });

        return $payload;
    }

    private function settle(OrderPayment $fee): void
    {
        app(PaymentService::class)
            ->transition($fee, PaymentStatus::Paid);
    }

    private function draftOffer(LeasybackOrder $order): LeasybackOffer
    {
        return LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'draft',
            'repair_cost_net' => '1000.00',
            'repair_cost_gross' => '1190.00',
        ]);
    }

    private function publish(LeasybackOffer $offer): TestResponse
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        return $this->actingAs($admin)
            ->from(route('admin.orders.show', $offer->order_id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id));
    }

    /**
     * @return array{0: LeasybackOrder, 1: User}
     */
    private function b2cOrder(): array
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => $customer->id,
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Confirmed->value,
        ]);

        return [$order->fresh(), $customer];
    }
}
