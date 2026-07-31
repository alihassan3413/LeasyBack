<?php

namespace Tests\Feature\Order;

use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Every row of docs/B2C_ADMIN_STATUS_MATRIX.md §1's recommended transition
 * table, both directions (valid transitions succeed, everything else is
 * rejected) — the regression test the checkpoint plan explicitly asked for.
 */
class TransitionOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function validTransitions(): array
    {
        return [
            ['order_requested', 'order_placed'],
            ['order_requested', 'discarded'],
            ['order_requested', 'cancelled'],
            ['order_placed', 'confirmed'],
            ['order_placed', 'cancelled'],
            ['confirmed', 'inspected'],
            ['confirmed', 'cancelled'],
            ['inspected', 'workshop'],
            ['inspected', 'cancelled'],
            ['workshop', 'reinspection'],
            ['workshop', 'cancelled'],
            ['reinspection', 'reworkshop'],
            ['reinspection', 'delivered'],
            ['reinspection', 'cancelled'],
            ['reworkshop', 'cancelled'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function invalidTransitions(): array
    {
        return [
            ['delivered', 'order_placed'],
            ['delivered', 'cancelled'],
            ['cancelled', 'confirmed'],
            ['discarded', 'order_placed'],
            ['order_requested', 'confirmed'],
            ['order_requested', 'inspected'],
            ['confirmed', 'order_placed'],
            ['inspected', 'confirmed'],
            // Not in the documented table — an open product question
            // (does reworkshop loop back?), deliberately not implemented.
            ['reworkshop', 'reinspection'],
            ['reworkshop', 'delivered'],
        ];
    }

    public function test_every_documented_valid_transition_succeeds(): void
    {
        $action = app(TransitionOrderStatus::class);

        foreach (self::validTransitions() as [$from, $to]) {
            $order = LeasybackOrder::factory()->create(['order_status' => $from]);

            $result = $action($order, $to, 'admin', 'tester');

            $this->assertSame($to, $result->order_status, "Expected {$from} -> {$to} to succeed");
            $this->assertDatabaseHas('leasyback_order_status_updates', [
                'auftragsnummer' => $order->auftragsnummer,
                'old_status' => $from,
                'new_status' => $to,
            ]);
        }
    }

    public function test_undocumented_transitions_are_rejected(): void
    {
        $action = app(TransitionOrderStatus::class);

        foreach (self::invalidTransitions() as [$from, $to]) {
            $order = LeasybackOrder::factory()->create(['order_status' => $from]);

            try {
                $action($order, $to, 'admin', 'tester');
                $this->fail("Expected {$from} -> {$to} to be rejected");
            } catch (ValidationException) {
                // expected
            }

            $this->assertSame($from, $order->fresh()->order_status, "Order status must not change for rejected {$from} -> {$to}");
        }

        $this->assertDatabaseCount('leasyback_order_status_updates', 0);
    }

    public function test_transitioning_to_the_current_status_is_a_no_op(): void
    {
        $order = LeasybackOrder::factory()->create(['order_status' => 'confirmed']);
        $action = app(TransitionOrderStatus::class);

        $result = $action($order, 'confirmed', 'api_key', 'tuvsud');

        $this->assertSame('confirmed', $result->order_status);
        $this->assertDatabaseCount('leasyback_order_status_updates', 0);
    }

    public function test_additional_attributes_are_persisted_with_the_transition(): void
    {
        $order = LeasybackOrder::factory()->create(['order_status' => 'order_requested']);
        $action = app(TransitionOrderStatus::class);

        $result = $action($order, 'order_placed', 'admin', 'tester', null, null, null, ['response_status' => 200]);

        $this->assertSame(200, $result->response_status);
    }

    public function test_writes_the_provided_actor_and_audit_context(): void
    {
        $order = LeasybackOrder::factory()->create(['order_status' => 'order_placed']);
        $action = app(TransitionOrderStatus::class);

        $action($order, 'confirmed', 'api_key', 'tuvsud_api_key', null, '203.0.113.5', 'BEWERTUNG-1');

        $this->assertDatabaseHas('leasyback_order_status_updates', [
            'auftragsnummer' => $order->auftragsnummer,
            'auth_source' => 'api_key',
            'updated_by' => 'tuvsud_api_key',
            'caller_ip' => '203.0.113.5',
            'bewertung_id' => 'BEWERTUNG-1',
        ]);
    }
}
