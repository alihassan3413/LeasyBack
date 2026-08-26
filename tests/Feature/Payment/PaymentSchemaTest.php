<?php

namespace Tests\Feature\Payment;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The structural guarantees the rest of the payment work is allowed to assume.
 *
 * Everything here is about invariants the *database* enforces, or model
 * predicates that decide whether money moves — the two categories where a
 * later refactor that quietly weakens them would not otherwise fail a test.
 */
class PaymentSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Double-charging is prevented by a constraint, not by a careful service.
     * This is the same belt-and-braces reasoning as `active_vehicle_id` and
     * the one-selected-offer index: bypassing the service must not be able to
     * produce a second repair charge either.
     */
    public function test_an_order_cannot_have_two_payments_of_the_same_purpose(): void
    {
        $order = LeasybackOrder::factory()->create();

        OrderPayment::factory()->repair()->create(['order_id' => $order->id]);

        $this->expectException(QueryException::class);

        OrderPayment::factory()->repair()->create(['order_id' => $order->id]);
    }

    /**
     * The other half of that constraint: the two purposes are independent
     * obligations, so one order legitimately carries both.
     */
    public function test_an_order_may_hold_a_repair_and_a_cancellation_fee_at_once(): void
    {
        $order = LeasybackOrder::factory()->create();

        OrderPayment::factory()->repair()->create(['order_id' => $order->id]);
        OrderPayment::factory()->cancellationFee()->create(['order_id' => $order->id]);

        $this->assertSame(2, OrderPayment::where('order_id', $order->id)->count());
    }

    /**
     * A `payment_intent.*` webhook carries an intent id and nothing else
     * useful. If two rows could share one, an event could settle the wrong
     * charge — a cancellation fee paying off a repair, or the reverse.
     */
    public function test_a_stripe_payment_intent_id_cannot_be_reused_across_payments(): void
    {
        $first = OrderPayment::factory()->repair()->create();
        $second = OrderPayment::factory()->cancellationFee()->create();

        OrderPaymentIntent::factory()->create([
            'payment_id' => $first->id,
            'payment_intent_id' => 'pi_shared',
        ]);

        $this->expectException(QueryException::class);

        OrderPaymentIntent::factory()->create([
            'payment_id' => $second->id,
            'payment_intent_id' => 'pi_shared',
        ]);
    }

    public function test_intent_sequence_is_unique_within_a_payment(): void
    {
        $payment = OrderPayment::factory()->create();

        OrderPaymentIntent::factory()->create(['payment_id' => $payment->id, 'sequence' => 1]);

        $this->expectException(QueryException::class);

        OrderPaymentIntent::factory()->create(['payment_id' => $payment->id, 'sequence' => 1]);
    }

    public function test_current_intent_is_the_highest_sequence(): void
    {
        $payment = OrderPayment::factory()->create();

        OrderPaymentIntent::factory()->canceled()->create(['payment_id' => $payment->id, 'sequence' => 1]);
        $latest = OrderPaymentIntent::factory()->create(['payment_id' => $payment->id, 'sequence' => 2]);

        $this->assertSame($latest->id, $payment->currentIntent()?->id);
    }

    /**
     * Every one of these four conditions has to hold. A card saved but never
     * server-verified proves only that a browser said so; a verified card with
     * no recorded mandate is one we have no permission to use unattended.
     */
    #[DataProvider('unchargeableMandateStates')]
    public function test_a_mandate_is_only_chargeable_when_verified_and_authorized(string $state): void
    {
        $mandate = OrderPaymentMethod::factory()->{$state}()->create();

        $this->assertFalse($mandate->isChargeableOffSession(), "State '{$state}' must not be chargeable.");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unchargeableMandateStates(): array
    {
        return [
            'never started' => ['awaitingMethod'],
            'browser said so, never verified' => ['unverified'],
            'verified but no off-session mandate' => ['withoutAuthorization'],
            'card removed at Stripe' => ['detached'],
        ];
    }

    public function test_a_fully_saved_mandate_is_chargeable(): void
    {
        $mandate = OrderPaymentMethod::factory()->saved()->create();

        $this->assertTrue($mandate->isChargeableOffSession());
    }

    /**
     * The fee acknowledgement is captured at booking, before any card exists.
     * That independence is the whole reason a customer who never completed the
     * payment step still owes the cancellation fee.
     */
    public function test_fee_acknowledgement_is_recorded_independently_of_any_card(): void
    {
        $mandate = OrderPaymentMethod::factory()->feeAcknowledged()->create();

        $this->assertNotNull($mandate->fee_acknowledged_at);
        $this->assertNull($mandate->payment_method_id);
        $this->assertFalse($mandate->isChargeableOffSession());
    }

    /**
     * `not_required` opens the gate. A car whose repair cost nothing must not
     * be held hostage to a payment that was never owed.
     */
    public function test_release_gate_is_satisfied_by_paid_and_not_required_only(): void
    {
        $opens = array_filter(
            PaymentStatus::cases(),
            fn (PaymentStatus $status) => $status->satisfiesReleaseGate(),
        );

        $this->assertEqualsCanonicalizing(
            [PaymentStatus::Paid, PaymentStatus::NotRequired],
            array_values($opens),
        );
    }

    /**
     * An unpaid cancellation fee must never withhold a vehicle: it is owed on
     * an order that is already terminal, so there is no car left to hold.
     */
    public function test_only_an_unsettled_repair_blocks_vehicle_release(): void
    {
        $unpaidRepair = OrderPayment::factory()->repair()->withStatus(PaymentStatus::Failed)->create();
        $unpaidFee = OrderPayment::factory()->requiresManualCollection()->create();
        $paidRepair = OrderPayment::factory()->repair()->paid()->create();
        $freeRepair = OrderPayment::factory()->notRequired()->create();

        $this->assertTrue($unpaidRepair->blocksRelease());
        $this->assertFalse($unpaidFee->blocksRelease());
        $this->assertFalse($paidRepair->blocksRelease());
        $this->assertFalse($freeRepair->blocksRelease());
    }

    /**
     * The cap counts unattended retries only. A person clicking "try again" is
     * not the behaviour that attracts card-network penalties.
     */
    public function test_auto_confirmation_cap_is_read_from_config(): void
    {
        config(['payments.max_auto_confirmations' => 1]);

        $fresh = OrderPayment::factory()->create();
        $capped = OrderPayment::factory()->atAutoConfirmationCap()->create();

        $this->assertTrue($fresh->mayAutoConfirmAgain());
        $this->assertFalse($capped->mayAutoConfirmAgain());
    }

    /**
     * A decline leaves an intent at `requires_payment_method`, which Stripe
     * intends to be confirmed again — so it is reusable, and only a cancelled
     * intent forces a new one. Getting this backwards would either strand 3DS
     * challenges or create a fresh intent per decline.
     */
    public function test_only_a_cancelled_intent_forces_a_new_one(): void
    {
        $this->assertTrue(OrderPaymentIntent::factory()->requiresAction()->make()->isReusable());
        $this->assertTrue(OrderPaymentIntent::factory()->declined()->make()->isReusable());
        $this->assertTrue(OrderPaymentIntent::factory()->make()->isReusable());

        $this->assertFalse(OrderPaymentIntent::factory()->canceled()->make()->isReusable());
        $this->assertTrue(OrderPaymentIntent::factory()->canceled()->make()->isSuperseded());

        $this->assertFalse(OrderPaymentIntent::factory()->succeeded()->make()->isReusable());
        $this->assertFalse(OrderPaymentIntent::factory()->processing()->make()->isReusable());
    }

    /**
     * Keyed on the payment alone, Stripe would replay the first intent forever
     * and every retry would silently become a no-op that still reports success.
     */
    public function test_idempotency_key_changes_with_each_confirmation(): void
    {
        $payment = OrderPayment::factory()->repair()->create();
        $intent = OrderPaymentIntent::factory()->create([
            'payment_id' => $payment->id,
            'sequence' => 1,
            'confirmation_count' => 0,
        ]);

        $first = $intent->idempotencyKeyForNextConfirmation();

        $intent->update(['confirmation_count' => 1]);

        $this->assertNotSame($first, $intent->fresh()->idempotencyKeyForNextConfirmation());
        $this->assertSame("{$payment->id}:repair:1:1", $first);
    }

    /**
     * Amounts are stored in minor units because that is what Stripe takes, but
     * every figure a human reads has to come back out as a decimal.
     */
    public function test_amount_round_trips_from_minor_units(): void
    {
        $payment = OrderPayment::factory()->create(['amount_cents' => 119000]);

        $this->assertSame('1190.00', $payment->amountDecimal());
    }

    public function test_cancellation_fee_factory_uses_the_configured_amount(): void
    {
        $payment = OrderPayment::factory()->cancellationFee()->create();

        $this->assertSame(20000, $payment->amount_cents);
        $this->assertSame('200.00', $payment->amountDecimal());
        $this->assertSame(PaymentPurpose::CancellationFee, $payment->purpose);
    }

    /**
     * Deleting an order must not strand payment records behind it.
     */
    public function test_payments_and_intents_cascade_with_the_order(): void
    {
        $order = LeasybackOrder::factory()->create();
        $payment = OrderPayment::factory()->repair()->create(['order_id' => $order->id]);
        OrderPaymentIntent::factory()->create(['payment_id' => $payment->id]);
        OrderPaymentMethod::factory()->saved()->create(['order_id' => $order->id]);

        $order->delete();

        $this->assertSame(0, OrderPayment::count());
        $this->assertSame(0, OrderPaymentIntent::count());
        $this->assertSame(0, OrderPaymentMethod::count());
    }
}
