<?php

namespace Tests\Feature\Payment;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Services\PaymentService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * What both sides are told when a held repair charge finally settles.
 *
 * The charge clears through a Stripe webhook, minutes or days after the
 * customer's own page stopped watching for it — so the settlement is the one
 * event in the B2C flow that nobody is looking at when it happens. The mail
 * has always gone out; these are the in-app notifications, which are also what
 * the two pages listen to in order to refresh themselves.
 */
class RepairPaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
        Mail::fake();
        Notification::fake();
    }

    /**
     * An order at `delivered` whose repair charge is owed and not moving —
     * no mandate, so nothing was charged and the gate holds the car.
     *
     * @return array{0: LeasybackOrder, 1: User, 2: Vehicle}
     */
    private function heldOrder(string $gross = '1190.00', bool $withMandate = false): array
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $customer->id,
            'license_plate' => 'K LB 2026E',
        ]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Reinspection->value,
        ]);

        if ($gross !== '0.00') {
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
                'stripe_customer_id' => 'cus_repair_notification_test',
            ]);
        }

        $order = app(TransitionOrderStatus::class)($order, OrderStatus::Delivered->value, 'test', 'PHPUnit');

        return [$order, $customer, $vehicle];
    }

    private function repairPayment(LeasybackOrder $order): OrderPayment
    {
        return OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::Repair->value)
            ->firstOrFail();
    }

    private function settle(LeasybackOrder $order): void
    {
        app(PaymentService::class)->transition($this->repairPayment($order), PaymentStatus::Paid);
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin, 'is_active' => true]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payloadsOfType(User $recipient, NotificationType $type): array
    {
        $payloads = [];

        Notification::assertSentTo($recipient, SystemNotification::class, function ($notification) use ($type, &$payloads) {
            $payload = $notification->payload->toArray();

            if ($payload['type'] === $type->value) {
                $payloads[] = $payload;
            }

            return true;
        });

        return $payloads;
    }

    public function test_settling_the_charge_tells_the_customer_the_vehicle_can_be_collected(): void
    {
        [$order, $customer] = $this->heldOrder();

        $this->settle($order);

        $payloads = $this->payloadsOfType($customer, NotificationType::VehicleReadyForPickup);

        $this->assertCount(1, $payloads);
        $this->assertSame('Fahrzeug abholbereit', $payloads[0]['title']);
        $this->assertStringContainsString('K LB 2026E', $payloads[0]['body']);
        $this->assertSame($order->id, $payloads[0]['meta']['order_id']);
        $this->assertSame('success', $payloads[0]['variant']);
    }

    /**
     * Admin's `confirm_pickup` task is shut until this moment and nothing
     * previously announced that it had opened.
     */
    public function test_settling_the_charge_tells_admin_the_pickup_can_be_confirmed(): void
    {
        $admin = $this->admin();
        [$order] = $this->heldOrder();

        $this->settle($order);

        $payloads = $this->payloadsOfType($admin, NotificationType::VehicleReadyForPickup);

        $this->assertCount(1, $payloads);
        $this->assertSame('Zahlungseingang bestätigt', $payloads[0]['title']);
        $this->assertStringContainsString($order->auftragsnummer, $payloads[0]['body']);
        $this->assertSame('/admin/orders/'.$order->id, $payloads[0]['url']);
    }

    /**
     * A repair that costs nothing releases the car inside the same `delivered`
     * transition that opens it, and the status change already says so. A
     * pickup notification here would be the same fact twice, one line apart.
     */
    public function test_a_zero_cost_repair_sends_no_separate_pickup_notification(): void
    {
        [, $customer] = $this->heldOrder('0.00');

        $this->assertSame([], $this->payloadsOfType($customer, NotificationType::VehicleReadyForPickup));
    }

    /**
     * `delivered` on its own reads "Abholbereit", which is false while the
     * charge is outstanding — the gate refuses pickup and the portal is
     * showing a pay-now banner at the same moment.
     */
    public function test_reaching_delivered_while_the_charge_is_owed_does_not_announce_pickup(): void
    {
        [, $customer] = $this->heldOrder();

        $payloads = $this->payloadsOfType($customer, NotificationType::OrderStatusChanged);

        $this->assertNotEmpty($payloads);
        $this->assertStringContainsString('Zahlung erforderlich', end($payloads)['body']);
        $this->assertStringNotContainsString('Abholbereit', end($payloads)['body']);
    }

    /**
     * The same transition on an order with nothing to pay says what it always
     * said — the override is the exception, not the new default.
     */
    public function test_reaching_delivered_with_nothing_owed_still_announces_pickup(): void
    {
        [, $customer] = $this->heldOrder('0.00');

        $payloads = $this->payloadsOfType($customer, NotificationType::OrderStatusChanged);

        $this->assertNotEmpty($payloads);
        $this->assertStringContainsString('Abholbereit', end($payloads)['body']);
    }
}
