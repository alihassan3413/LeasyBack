<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_non_admin_cannot_list_orders(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->withHeaders($this->bearer($user))
            ->getJson('/admin/list/orders')
            ->assertForbidden();
    }

    public function test_admin_can_list_orders_filtered_by_valid_status(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $vehicle = Vehicle::factory()->create();
        LeasybackOrder::factory()->withStatus(OrderStatus::Confirmed)->create(['vehicle_id' => $vehicle->vehicle_id]);
        LeasybackOrder::factory()->withStatus(OrderStatus::Delivered)->create(['vehicle_id' => $vehicle->vehicle_id]);

        $response = $this->withHeaders($this->bearer($admin))
            ->getJson('/admin/list/orders?order_status=confirmed');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('confirmed', $data[0]['order_status']);
    }

    /**
     * Regression test for the fixed SQL injection: `order_status` used to be
     * string-interpolated straight into raw SQL
     * (`WHERE 1=1 AND o.order_status = '{$status}'`). AdminQueryService now
     * validates it against an allow-list before it ever reaches a query, so
     * an injection payload must be rejected outright (422), never silently
     * accepted or used to alter the query.
     */
    public function test_order_status_injection_payload_is_rejected(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $vehicle = Vehicle::factory()->create();
        LeasybackOrder::factory()->withStatus(OrderStatus::Confirmed)->create(['vehicle_id' => $vehicle->vehicle_id]);

        $payloads = [
            "confirmed' OR '1'='1",
            "confirmed'; DROP TABLE leasyback_orders; --",
            "' UNION SELECT * FROM users --",
        ];

        foreach ($payloads as $payload) {
            $response = $this->withHeaders($this->bearer($admin))
                ->getJson('/admin/list/orders?order_status='.urlencode($payload));

            $response->assertStatus(422);
        }

        // The table (and its one legitimate row) must still be intact.
        $this->assertDatabaseCount('leasyback_orders', 1);
    }
}
