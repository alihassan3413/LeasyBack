<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Support\RepairPaymentPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How `delivered` is presented while the repair charge is outstanding.
 *
 * The contradiction these tests exist to prevent: `delivered` is reached
 * *before* the money arrives, because reaching it is what triggers the charge.
 * Read literally it says "ready for pickup" — so the customer was shown a
 * pay-now banner and "your vehicle can be collected" at the same time, on the
 * same screen, while the completion gate quietly refused the pickup.
 *
 * The presented stage is therefore derived, never stored, and both audiences
 * derive it from the same rule.
 */
class RepairPaymentPresentationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A delivered B2C order with a repair charge in the given state.
     *
     * @return array{0: LeasybackOrder, 1: User}
     */
    private function deliveredOrder(?PaymentStatus $paymentStatus, int $amountCents = 119000): array
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2c_user_id' => $customer->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Delivered->value,
        ]);

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

        OrderPaymentMethod::factory()->saved()->create(['order_id' => $order->id]);

        if ($paymentStatus !== null) {
            $payment = OrderPayment::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'purpose' => PaymentPurpose::Repair,
                'amount_cents' => $amountCents,
                'currency' => 'eur',
            ]);

            $payment->forceFill([
                'status' => $paymentStatus,
                'notified_status' => $paymentStatus->value,
                'paid_at' => $paymentStatus === PaymentStatus::Paid ? now() : null,
            ])->save();
        }

        return [$order, $customer];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerOrderPayload(User $customer): array
    {
        $payload = [];

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertInertia(function (AssertableInertia $page) use (&$payload) {
                $payload = $page->toArray()['props']['vehicles'][0]['orders'][0];
            });

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function adminOrderPayload(LeasybackOrder $order): array
    {
        return app(AdminQueryService::class)->orderDetail($order->id);
    }

    // ---- the derivation itself ----------------------------------------------

    /**
     * @return array<string, array{0: ?PaymentStatus, 1: string, 2: bool}>
     */
    public static function paymentStates(): array
    {
        return [
            // status, expected stage, blocks pickup
            'pending' => [PaymentStatus::Pending, RepairPaymentPresentation::AWAITING, true],
            'processing' => [PaymentStatus::Processing, RepairPaymentPresentation::PROCESSING, true],
            'requires action' => [PaymentStatus::RequiresAction, RepairPaymentPresentation::AWAITING, true],
            'failed' => [PaymentStatus::Failed, RepairPaymentPresentation::AWAITING, true],
            'requires manual collection' => [PaymentStatus::RequiresManualCollection, RepairPaymentPresentation::AWAITING, true],
            'cancelled' => [PaymentStatus::Cancelled, RepairPaymentPresentation::AWAITING, true],
            'paid' => [PaymentStatus::Paid, RepairPaymentPresentation::SETTLED, false],
            'not required' => [PaymentStatus::NotRequired, RepairPaymentPresentation::NOT_REQUIRED, false],
            'no charge opened' => [null, RepairPaymentPresentation::NONE, false],
        ];
    }

    #[DataProvider('paymentStates')]
    public function test_the_derived_stage_follows_the_payment_state(
        ?PaymentStatus $status,
        string $expectedStage,
        bool $blocksPickup,
    ): void {
        $stage = RepairPaymentPresentation::stageFor(OrderStatus::Delivered->value, $status?->value);

        $this->assertSame($expectedStage, $stage);
        $this->assertSame($blocksPickup, RepairPaymentPresentation::blocksPickup($stage));
    }

    /**
     * The gate and the presentation must agree by construction: a stage that
     * says "ready" while `delivered → completed` is refused is the whole bug.
     */
    #[DataProvider('paymentStates')]
    public function test_the_presented_stage_never_disagrees_with_the_release_gate(
        ?PaymentStatus $status,
        string $expectedStage,
        bool $blocksPickup,
    ): void {
        if ($status === null) {
            $this->assertFalse($blocksPickup);

            return;
        }

        $this->assertSame(
            ! $status->satisfiesReleaseGate(),
            RepairPaymentPresentation::blocksPickup($expectedStage),
            "Stage {$expectedStage} disagrees with PaymentStatus::satisfiesReleaseGate().",
        );
    }

    // ---- both audiences, one stage ------------------------------------------

    #[DataProvider('paymentStates')]
    public function test_customer_and_admin_are_shown_the_same_derived_stage(
        ?PaymentStatus $status,
        string $expectedStage,
    ): void {
        [$order, $customer] = $this->deliveredOrder($status);

        $customerPayload = $this->customerOrderPayload($customer);
        $adminPayload = $this->adminOrderPayload($order);

        $this->assertSame($expectedStage, $customerPayload['payment']['repair_stage']);
        $this->assertSame($expectedStage, $adminPayload['repair_payment_stage']);
        $this->assertSame(
            $customerPayload['payment']['repair_stage'],
            $adminPayload['repair_payment_stage'],
            'Admin and the customer must never present the same order differently.',
        );
    }

    public function test_an_outstanding_charge_reaches_the_customer_with_the_amount_and_the_hold(): void
    {
        [, $customer] = $this->deliveredOrder(PaymentStatus::Failed);

        $payment = $this->customerOrderPayload($customer)['payment'];

        $this->assertSame(RepairPaymentPresentation::AWAITING, $payment['repair_stage']);
        $this->assertSame(119000, $payment['repair']['amount_cents']);
        $this->assertTrue($payment['repair']['blocks_pickup']);
        $this->assertTrue($payment['repair']['payable']);
    }

    public function test_a_settled_charge_releases_the_presentation(): void
    {
        [$order, $customer] = $this->deliveredOrder(PaymentStatus::Paid);

        $payment = $this->customerOrderPayload($customer)['payment'];

        $this->assertSame(RepairPaymentPresentation::SETTLED, $payment['repair_stage']);
        $this->assertFalse($payment['repair']['blocks_pickup']);
        $this->assertFalse($payment['repair']['payable']);
        $this->assertFalse($this->adminOrderPayload($order)['repair_payment']['blocks_pickup']);
    }

    public function test_a_zero_cost_repair_never_presents_as_awaiting_payment(): void
    {
        [$order, $customer] = $this->deliveredOrder(PaymentStatus::NotRequired, 0);

        $this->assertSame(
            RepairPaymentPresentation::NOT_REQUIRED,
            $this->customerOrderPayload($customer)['payment']['repair_stage'],
        );
        $this->assertSame(
            RepairPaymentPresentation::NOT_REQUIRED,
            $this->adminOrderPayload($order)['repair_payment_stage'],
        );
    }

    /**
     * Admin can see what is owed, and must never be offered a way to settle it
     * — authenticating a customer's card is not something anyone can do on
     * their behalf.
     */
    public function test_admin_sees_the_amount_but_is_never_offered_the_payment(): void
    {
        [$order] = $this->deliveredOrder(PaymentStatus::RequiresAction);

        $adminPayload = $this->adminOrderPayload($order);

        $this->assertSame(RepairPaymentPresentation::AWAITING, $adminPayload['repair_payment_stage']);
        $this->assertSame(119000, $adminPayload['repair_payment']['amount_cents']);
        $this->assertTrue($adminPayload['repair_payment']['blocks_pickup']);

        // Admin's summary carries no `payable` at all — there is no field for a
        // pay action to key off, rather than a field set to false.
        $this->assertArrayNotHasKey('payable', $adminPayload['repair_payment']);
    }

    // ---- terminal orders -----------------------------------------------------

    public function test_a_cancelled_order_presents_nothing_about_payment(): void
    {
        $stage = RepairPaymentPresentation::stageFor(
            OrderStatus::Cancelled->value,
            PaymentStatus::Failed->value,
        );

        $this->assertSame(RepairPaymentPresentation::NONE, $stage);
        $this->assertFalse(RepairPaymentPresentation::blocksPickup($stage));
    }

    public function test_a_completed_order_still_presents_its_payment_as_settled(): void
    {
        $this->assertSame(
            RepairPaymentPresentation::SETTLED,
            RepairPaymentPresentation::stageFor(OrderStatus::Completed->value, PaymentStatus::Paid->value),
        );
    }

    // ---- B2B -----------------------------------------------------------------

    public function test_b2b_never_gets_a_payment_stage(): void
    {
        $this->assertSame(
            RepairPaymentPresentation::NONE,
            RepairPaymentPresentation::stageFor(OrderStatus::Delivered->value, PaymentStatus::Failed->value, true),
        );
    }

    public function test_a_b2b_order_carries_no_payment_block_and_a_none_stage(): void
    {
        $company = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2B', 'b2c_user_id' => $company->id]);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => OrderStatus::Delivered->value,
        ]);

        $adminPayload = $this->adminOrderPayload($order);

        // No charge, no stage, and nothing on the B2B timeline that could
        // present one — B2B settles through `b2b_order_billing` and its own
        // completion gate, which this work does not touch.
        $this->assertNull($adminPayload['repair_payment']);
        $this->assertSame(RepairPaymentPresentation::NONE, $adminPayload['repair_payment_stage']);
        $this->assertDatabaseMissing('order_payments', ['order_id' => $order->id]);
    }
}
