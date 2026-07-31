<?php

namespace Tests\Feature\Api;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public (no Sanctum) TÜV SÜD webhook routes — VerifyTuvsudApiKey
 * middleware and the status() endpoint's now-validated status value.
 */
class OrderWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_fails_closed_when_no_key_is_configured(): void
    {
        config(['services.tuvsud.api_key' => null]);

        $this->getJson('/order/tuvsud/status?auftragsnummer=X&status=confirmed')
            ->assertStatus(503);
    }

    public function test_webhook_rejects_a_wrong_key(): void
    {
        config(['services.tuvsud.api_key' => 'the-real-key']);

        $this->withHeader('X-API-Key', 'wrong-key')
            ->getJson('/order/tuvsud/status?auftragsnummer=X&status=confirmed')
            ->assertStatus(401);
    }

    public function test_webhook_rejects_a_missing_key(): void
    {
        config(['services.tuvsud.api_key' => 'the-real-key']);

        $this->getJson('/order/tuvsud/status?auftragsnummer=X&status=confirmed')
            ->assertStatus(401);
    }

    public function test_webhook_accepts_the_correct_key_via_x_api_key_header(): void
    {
        config(['services.tuvsud.api_key' => 'the-real-key']);
        $order = LeasybackOrder::factory()->create(['order_status' => 'order_placed', 'auftragsnummer' => 'AUF-TEST-1']);

        $this->withHeader('X-API-Key', 'the-real-key')
            ->getJson('/order/tuvsud/status?auftragsnummer=AUF-TEST-1&status=confirmed')
            ->assertOk();

        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_webhook_accepts_the_correct_key_via_bearer_header(): void
    {
        config(['services.tuvsud.api_key' => 'the-real-key']);
        $order = LeasybackOrder::factory()->create(['order_status' => 'order_placed', 'auftragsnummer' => 'AUF-TEST-2']);

        $this->withHeader('Authorization', 'Bearer the-real-key')
            ->getJson('/order/tuvsud/status?auftragsnummer=AUF-TEST-2&status=confirmed')
            ->assertOk();

        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    /**
     * Regression test for the fixed free-text status-override: status() no
     * longer accepts an arbitrary string as the new order_status.
     */
    public function test_webhook_status_rejects_an_unknown_status_value(): void
    {
        config(['services.tuvsud.api_key' => 'the-real-key']);
        $order = LeasybackOrder::factory()->create(['order_status' => 'order_placed', 'auftragsnummer' => 'AUF-TEST-3']);

        $this->withHeader('X-API-Key', 'the-real-key')
            ->getJson('/order/tuvsud/status?auftragsnummer=AUF-TEST-3&status=totally_made_up')
            ->assertStatus(422);

        $this->assertSame('order_placed', $order->fresh()->order_status);
    }

    public function test_webhook_status_rejects_a_disallowed_transition(): void
    {
        config(['services.tuvsud.api_key' => 'the-real-key']);
        $order = LeasybackOrder::factory()->create(['order_status' => 'delivered', 'auftragsnummer' => 'AUF-TEST-4']);

        $this->withHeader('X-API-Key', 'the-real-key')
            ->getJson('/order/tuvsud/status?auftragsnummer=AUF-TEST-4&status=order_placed')
            ->assertStatus(422);

        $this->assertSame('delivered', $order->fresh()->order_status);
    }
}
