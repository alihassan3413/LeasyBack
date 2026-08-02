<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    public function test_non_admin_cannot_view_the_order_list(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($user)
            ->get(route('admin.orders.index'))
            ->assertForbidden();
    }

    public function test_admin_sees_all_orders(): void
    {
        $admin = $this->admin();
        LeasybackOrder::factory()->create();
        LeasybackOrder::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Orders/Index')
                ->has('orders.data', 2)
            );
    }

    /**
     * The order list gained the same free-text `?search=` the vehicle list
     * already had (both now go through AdminQueryService::applyListSearch()),
     * and the header counts are computed after it so they describe the rows
     * actually shown.
     */
    public function test_admin_can_search_the_order_list(): void
    {
        $admin = $this->admin();
        $wanted = Vehicle::factory()->create(['license_plate' => 'K SEARCH 1']);
        LeasybackOrder::factory()->create(['vehicle_id' => $wanted->vehicle_id, 'auftragsnummer' => 'AUF-WANTED']);
        LeasybackOrder::factory()->create(['auftragsnummer' => 'AUF-OTHER']);

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['search' => 'K SEARCH']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.auftragsnummer', 'AUF-WANTED')
                ->where('orders.total', 1)
                ->where('filters.search', 'K SEARCH')
            );

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['search' => 'AUF-OTHER']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.auftragsnummer', 'AUF-OTHER')
            );
    }

    public function test_admin_can_view_an_order_detail(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()
            ->withStatus(OrderStatus::Confirmed)
            ->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Orders/Show')
                ->where('order.id', $order->id)
                ->where('order.user_id', $owner->id)
                ->where('order.available_transitions', ['inspected', 'cancelled'])
            );
    }

    /**
     * order_placed (approve()'s own job) and discarded (the not-yet-confirmed
     * reject action) must never appear as generic manage-status options,
     * even though TransitionOrderStatus::allowedNextStatuses() itself would
     * include them for an order_requested order.
     */
    public function test_available_transitions_excludes_order_placed_and_discarded(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::OrderRequested)->create();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.available_transitions', ['cancelled'])
            );
    }

    public function test_show_returns_404_for_unknown_order(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', fake()->uuid()))
            ->assertNotFound();
    }

    public function test_admin_can_approve_an_order_requested_order(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::OrderRequested)->create();

        $this->actingAs($admin)
            ->post(route('admin.orders.approve', $order->id))
            ->assertRedirect();

        $this->assertSame(OrderStatus::OrderPlaced->value, $order->fresh()->order_status);
        $this->assertDatabaseHas('leasyback_order_status_updates', [
            'auftragsnummer' => $order->auftragsnummer,
            'old_status' => 'order_requested',
            'new_status' => 'order_placed',
            'auth_source' => 'admin',
        ]);
    }

    public function test_approving_a_non_requested_order_fails(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::Confirmed)->create();

        $this->actingAs($admin)
            ->post(route('admin.orders.approve', $order->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::Confirmed->value, $order->fresh()->order_status);
    }

    public function test_non_admin_cannot_approve_an_order(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::OrderRequested)->create();

        $this->actingAs($user)
            ->post(route('admin.orders.approve', $order->id))
            ->assertForbidden();
    }

    public function test_admin_can_progress_an_order_through_a_manual_status_transition(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::Confirmed)->create();

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order->id), ['status' => 'inspected'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Inspected->value, $order->fresh()->order_status);
    }

    public function test_admin_can_cancel_an_order(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::Workshop)->create();

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order->id), ['status' => 'cancelled'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->order_status);
    }

    public function test_status_update_rejects_order_placed_and_discarded(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::OrderRequested)->create();

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order->id), ['status' => 'order_placed'])
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order->id), ['status' => 'discarded'])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::OrderRequested->value, $order->fresh()->order_status);
    }

    public function test_status_update_rejects_an_invalid_transition(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::Confirmed)->create();

        $this->actingAs($admin)
            ->patch(route('admin.orders.status', $order->id), ['status' => 'delivered'])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::Confirmed->value, $order->fresh()->order_status);
    }

    public function test_non_admin_cannot_update_order_status(): void
    {
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::Confirmed)->create();

        $this->actingAs($user)
            ->patch(route('admin.orders.status', $order->id), ['status' => 'inspected'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::Confirmed->value, $order->fresh()->order_status);
    }
}
