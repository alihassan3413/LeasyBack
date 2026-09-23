<?php

namespace Tests\Feature\B2b;

use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\B2bBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * b2b.txt §20: "Mandatory billing prevents premature completion", and §21's
 * "must not mark an order complete before mandatory billing".
 *
 * The gate lives in TransitionOrderStatus — the single writer of
 * `order_status` — so these go through the action rather than a controller:
 * a controller test would prove one route is gated, this proves the gate
 * cannot be routed around at all.
 */
class B2bCompletionGateTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private function orderAtInvoiceProcessed(): LeasybackOrder
    {
        $company = $this->makeCompany();

        return $this->makeB2bOrder($this->makeB2bVehicle($company), 'invoice_processed');
    }

    public function test_completion_is_blocked_when_no_billing_record_exists(): void
    {
        $order = $this->orderAtInvoiceProcessed();

        try {
            app(TransitionOrderStatus::class)($order, 'completed', 'admin', 'tester');
            $this->fail('Expected completion without a billing record to be refused');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Abrechnung', $exception->getMessage());
        }

        $this->assertSame('invoice_processed', $order->fresh()->order_status);
    }

    public function test_completion_is_blocked_when_billing_exists_but_is_not_processed(): void
    {
        $order = $this->orderAtInvoiceProcessed();
        $admin = $this->makeAdmin();

        app(B2bBillingService::class)->update($order, $this->shimVehicle($order), $admin, [
            'invoice_reference' => 'RE-2026-001',
            'mark_processed' => false,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(TransitionOrderStatus::class)($order, 'completed', 'admin', 'tester');
        } finally {
            $this->assertSame('invoice_processed', $order->fresh()->order_status);
        }
    }

    public function test_completion_succeeds_once_billing_is_marked_processed(): void
    {
        $order = $this->orderAtInvoiceProcessed();
        $admin = $this->makeAdmin();

        app(B2bBillingService::class)->update($order, $this->shimVehicle($order), $admin, [
            'invoice_reference' => 'RE-2026-002',
            'mark_processed' => true,
        ]);

        $result = app(TransitionOrderStatus::class)($order, 'completed', 'admin', 'tester');

        $this->assertSame('completed', $result->order_status);
        $this->assertDatabaseHas('b2b_order_billing', [
            'order_id' => $order->id,
            'billing_status' => 'processed',
        ]);
    }

    /**
     * The gate must be a no-op for B2C: `completed` is a B2B-only status, so
     * guardChannel() rejects it first and the billing check is never reached.
     * A B2C order's own terminal is `delivered`, which must still work.
     */
    public function test_a_b2c_order_still_reaches_its_own_terminal_status(): void
    {
        $order = LeasybackOrder::factory()->create(['order_status' => 'reinspection']);

        $result = app(TransitionOrderStatus::class)($order, 'delivered', 'admin', 'tester');

        $this->assertSame('delivered', $result->order_status);
        $this->assertDatabaseCount('b2b_order_billing', 0);
    }

    public function test_the_completion_transition_is_recorded_in_the_audit_history(): void
    {
        $order = $this->orderAtInvoiceProcessed();

        app(B2bBillingService::class)->update($order, $this->shimVehicle($order), $this->makeAdmin(), [
            'invoice_reference' => 'RE-2026-003',
            'mark_processed' => true,
        ]);

        app(TransitionOrderStatus::class)($order, 'completed', 'admin', 'tester');

        $this->assertDatabaseHas('leasyback_order_status_updates', [
            'auftragsnummer' => $order->auftragsnummer,
            'old_status' => 'invoice_processed',
            'new_status' => 'completed',
        ]);
    }
}
