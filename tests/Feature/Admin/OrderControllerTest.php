<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\TaskPriority;
use App\Enums\UserType;
use App\Models\Address;
use App\Models\B2B;
use App\Models\Contact;
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

    // ── Ordering: open before closed, most urgent first ────────────────
    //
    // Reuses TaskPriority and OrderTaskPriorityResolver exactly as
    // AdminTaskQueryService::openTasks() does for the dashboard's task
    // list — these prove the order list applies the same ranking, built
    // from real orders and real task rules rather than stubbed priorities.

    /**
     * The client's own ask: closed orders sit behind every open one,
     * however recently they closed — recency alone used to be the whole
     * sort.
     */
    public function test_closed_orders_sort_behind_open_orders_regardless_of_recency(): void
    {
        $admin = $this->admin();

        $closed = LeasybackOrder::factory()->withStatus(OrderStatus::Completed)->create();
        $closed->forceFill(['created_at' => now()])->save();

        $open = LeasybackOrder::factory()->withStatus(OrderStatus::OrderPlaced)->create();
        $open->forceFill(['created_at' => now()->subMonth()])->save();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.id', $open->id)
                ->where('orders.data.1.id', $closed->id)
            );
    }

    /**
     * Within the open tier, the order whose next task carries the higher
     * TaskPriority::rank() sorts first — the same ranking
     * AdminTaskQueryService already uses for the dashboard's task list.
     */
    public function test_open_orders_are_sorted_with_the_most_urgent_task_first(): void
    {
        $admin = $this->admin();

        // `inspected` -> capture_repair_positions, an immediate() rule.
        $urgent = LeasybackOrder::factory()->withStatus(OrderStatus::Inspected)->create();
        // `order_placed` -> confirm_inspection_appointment, a none() rule.
        $routine = LeasybackOrder::factory()->withStatus(OrderStatus::OrderPlaced)->create();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.id', $urgent->id)
                ->where('orders.data.0.priority', TaskPriority::ImmediateRed->value)
                ->where('orders.data.1.id', $routine->id)
                ->where('orders.data.1.priority', TaskPriority::Neutral->value)
            );
    }

    /** Among equally urgent open orders, the one waiting longest comes first. */
    public function test_equal_priority_open_orders_break_by_how_long_they_have_waited(): void
    {
        $admin = $this->admin();

        $older = LeasybackOrder::factory()->withStatus(OrderStatus::OrderPlaced)->create();
        $older->forceFill(['created_at' => now()->subMonth()])->save();

        $newer = LeasybackOrder::factory()->withStatus(OrderStatus::OrderPlaced)->create();
        $newer->forceFill(['created_at' => now()])->save();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.id', $older->id)
                ->where('orders.data.1.id', $newer->id)
            );
    }

    /** Closed orders carry no urgency signal — there is no open task to rank. */
    public function test_closed_orders_carry_no_priority(): void
    {
        $admin = $this->admin();
        $closed = LeasybackOrder::factory()->withStatus(OrderStatus::Cancelled)->create();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.id', $closed->id)
                ->where('orders.data.0.priority', null)
            );
    }

    /**
     * OrderTaskPriorityResolver keeps every B2B order Neutral today (no
     * SLA-driven priority rules exist for that channel yet) — they must
     * still land in the open tier, ahead of every closed order, just
     * without urgency differentiation among themselves.
     */
    public function test_b2b_open_orders_still_sort_ahead_of_closed_orders(): void
    {
        $admin = $this->admin();

        $company = B2B::create([
            'contact_id' => Contact::factory()->create()->contact_id,
            'address_id' => Address::factory()->create()->address_id,
            'company_name' => 'Beta GmbH',
            'contact_email' => fake()->unique()->safeEmail(),
        ]);
        $b2bVehicle = Vehicle::factory()->forB2b($company->b2b_id)->create();

        $b2bOpen = LeasybackOrder::factory()->create([
            'vehicle_id' => $b2bVehicle->vehicle_id,
            'order_status' => OrderStatus::OrderPlaced->value,
            'leasyback_partner' => 'leasyback',
            'request_payload' => ['order_type' => 'b2b_collection'],
        ]);

        $closed = LeasybackOrder::factory()->withStatus(OrderStatus::Completed)->create();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.id', $b2bOpen->id)
                ->where('orders.data.0.priority', TaskPriority::Neutral->value)
                ->where('orders.data.1.id', $closed->id)
            );
    }

    /** The explicit column sort is unaffected by the new urgency-first default. */
    public function test_explicit_license_plate_sort_is_unaffected_by_the_urgency_default(): void
    {
        $admin = $this->admin();
        $first = Vehicle::factory()->create(['license_plate' => 'B AA 1']);
        $second = Vehicle::factory()->create(['license_plate' => 'B AA 2']);
        $a = LeasybackOrder::factory()->create(['vehicle_id' => $first->vehicle_id]);
        $b = LeasybackOrder::factory()->create(['vehicle_id' => $second->vehicle_id]);

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['sort_by' => 'license_plate', 'sort_order' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.id', $b->id)
                ->where('orders.data.1.id', $a->id)
            );
    }

    // ── Status-group filter tabs (Offen / In Bearbeitung / Abgeschlossen) ──
    //
    // The order list's `status` parameter now accepts these three group
    // keywords alongside the 16 exact OrderStatus values it already did —
    // never instead of them (test_admin_can_search_the_order_list and the
    // license-plate sort test above still pass, using the parameter exactly
    // as before).

    public function test_the_open_status_group_filters_to_not_yet_started_orders(): void
    {
        $admin = $this->admin();
        $open = LeasybackOrder::factory()->withStatus(OrderStatus::OrderPlaced)->create();
        LeasybackOrder::factory()->withStatus(OrderStatus::Workshop)->create();
        LeasybackOrder::factory()->withStatus(OrderStatus::Completed)->create();

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['status' => 'open']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $open->id)
            );
    }

    public function test_the_in_progress_status_group_filters_to_active_work(): void
    {
        $admin = $this->admin();
        LeasybackOrder::factory()->withStatus(OrderStatus::OrderPlaced)->create();
        $inProgress = LeasybackOrder::factory()->withStatus(OrderStatus::Workshop)->create();
        LeasybackOrder::factory()->withStatus(OrderStatus::Completed)->create();

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['status' => 'in_progress']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $inProgress->id)
            );
    }

    /** Unlike total_delivered (completedValues() only), the "closed" group also covers cancelled and discarded. */
    public function test_the_closed_status_group_covers_completed_cancelled_and_discarded(): void
    {
        $admin = $this->admin();
        LeasybackOrder::factory()->withStatus(OrderStatus::OrderPlaced)->create();
        $completed = LeasybackOrder::factory()->withStatus(OrderStatus::Completed)->create();
        $cancelled = LeasybackOrder::factory()->withStatus(OrderStatus::Cancelled)->create();
        $discarded = LeasybackOrder::factory()->withStatus(OrderStatus::Discarded)->create();

        $ids = $this->actingAs($admin)
            ->get(route('admin.orders.index', ['status' => 'closed']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 3))
            ->viewData('page')['props']['orders']['data'];

        $this->assertEqualsCanonicalizing(
            [$completed->id, $cancelled->id, $discarded->id],
            array_column($ids, 'id'),
        );
    }

    /** An unknown status keyword is still rejected, exactly as an unknown exact status already was. */
    public function test_an_unknown_status_group_keyword_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['status' => 'not-a-real-group']))
            ->assertSessionHasErrors('order_status');
    }

    /** The header/tab counts use the same three groups the filter itself uses. */
    public function test_the_status_group_counts_match_the_group_filters(): void
    {
        $admin = $this->admin();
        LeasybackOrder::factory()->withStatus(OrderStatus::OrderPlaced)->create();
        LeasybackOrder::factory()->withStatus(OrderStatus::Workshop)->create();
        LeasybackOrder::factory()->withStatus(OrderStatus::Completed)->create();
        LeasybackOrder::factory()->withStatus(OrderStatus::Cancelled)->create();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.total', 4)
                ->where('orders.total_open', 1)
                ->where('orders.total_in_progress', 1)
                ->where('orders.total_closed', 2)
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
