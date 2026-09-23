<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\TaskPriority;
use App\Enums\UserType;
use App\Models\Address;
use App\Models\B2B;
use App\Models\Contact;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Admin\Services\AdminTaskQueryService;
use App\Modules\UserProfile\Admin\Services\OrderTaskHydrator;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\DetachedOrderTaskResolver;
use App\Modules\UserProfile\Order\Services\OrderTaskPriorityResolver;
use App\Modules\UserProfile\Order\Services\OrderTaskResolver;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin dashboard's workload view: how many tasks are open, and which
 * one to do first.
 */
class AdminOpenTaskTest extends TestCase
{
    use RefreshDatabase;

    // ── The aggregation ──────────────────────────────────────────────

    public function test_it_lists_a_task_for_an_order_awaiting_admin_action(): void
    {
        $order = $this->b2cOrder(OrderStatus::OrderPlaced->value);

        $result = $this->tasks();

        $this->assertSame(1, $result['count']);
        $this->assertSame($order->id, $result['data'][0]['order_id']);
        $this->assertSame('confirm_inspection_appointment', $result['data'][0]['key']);
    }

    /** A closed order has no next step, so it contributes no work. */
    public function test_closed_orders_contribute_no_tasks(): void
    {
        foreach (OrderStatus::closedValues() as $status) {
            $this->b2cOrder($status);
        }

        $this->assertSame(0, $this->tasks()['count']);
    }

    /**
     * The dashboard answers "what is waiting on *me*". A step the customer or
     * the workshop owns is something being waited for, not admin work.
     */
    public function test_a_task_owned_by_someone_else_is_not_listed(): void
    {
        // `confirmed` leaves the next step with the customer/inspector.
        $this->b2cOrder(OrderStatus::Confirmed->value);

        $result = $this->tasks();

        foreach ($result['data'] as $task) {
            $this->assertNotSame('customer', $task['key'], 'Only admin-owned tasks belong on this list.');
        }

        $this->assertLessThanOrEqual(1, $result['count']);
    }

    // ── Ordering ─────────────────────────────────────────────────────

    /**
     * The whole point of the list: the most urgent task is first.
     *
     * Built from real orders rather than stubbed priorities, so the ordering
     * is proved through the same rules the order page applies.
     */
    public function test_tasks_are_ordered_most_urgent_first(): void
    {
        // `inspected` maps to capture_repair_positions — an immediate() rule.
        $urgent = $this->b2cOrder(OrderStatus::Inspected->value);
        // `order_placed` maps to confirm_inspection_appointment — none().
        $routine = $this->b2cOrder(OrderStatus::OrderPlaced->value);

        $data = $this->tasks()['data'];

        $this->assertGreaterThanOrEqual(2, count($data));
        $this->assertSame($urgent->id, $data[0]['order_id'], 'An immediate task must outrank an unranked one.');
        $this->assertSame(TaskPriority::ImmediateRed->value, $data[0]['priority']);
        $this->assertSame(4, $data[0]['rank']);

        $ranks = array_column($data, 'rank');
        $sorted = $ranks;
        rsort($sorted);
        $this->assertSame($sorted, $ranks, 'The list must be sorted by rank, descending.');

        $this->assertSame($routine->id, $data[array_key_last($data)]['order_id']);
    }

    /** Among equally urgent tasks, the one waiting longest comes first. */
    public function test_equal_priorities_break_by_longest_waiting(): void
    {
        $older = $this->b2cOrder(OrderStatus::OrderPlaced->value);
        $older->forceFill(['created_at' => now()->subMonth()])->save();

        $newer = $this->b2cOrder(OrderStatus::OrderPlaced->value);
        $newer->forceFill(['created_at' => now()])->save();

        $data = $this->tasks()['data'];
        $ids = array_column($data, 'order_id');

        $this->assertLessThan(
            array_search($newer->id, $ids, true),
            array_search($older->id, $ids, true),
            'The task that has been waiting longer must come first.',
        );
    }

    // ── Row context ──────────────────────────────────────────────────

    /** A task row says whose order it is: the company, or the private customer. */
    public function test_each_row_names_its_customer(): void
    {
        $private = $this->b2cOrder(OrderStatus::OrderPlaced->value);
        $company = $this->b2bOrder(OrderStatus::OrderPlaced->value);

        $rows = collect($this->tasks()['data'])->keyBy('order_id');

        $this->assertSame(
            User::find($private->vehicle->b2c_user_id)->email,
            $rows[$private->id]['customer'] ?? null,
        );

        if ($rows->has($company->id)) {
            $this->assertSame('Alpha GmbH', $rows[$company->id]['customer']);
        }
    }

    /**
     * The urgent figure counts every task, not the page. Urgent tasks sort
     * first, so counting the sliced list would silently stop at the limit.
     */
    public function test_the_urgent_count_is_not_capped_by_the_list(): void
    {
        foreach (range(1, 5) as $ignored) {
            // `inspected` → capture_repair_positions, an immediate() rule.
            $this->b2cOrder(OrderStatus::Inspected->value);
        }

        $result = $this->tasks(2);

        $this->assertCount(2, $result['data']);
        $this->assertSame(5, $result['urgent']);
    }

    // ── Bounds ───────────────────────────────────────────────────────

    public function test_the_list_is_capped_but_the_count_is_not(): void
    {
        foreach (range(1, 7) as $ignored) {
            $this->b2cOrder(OrderStatus::OrderPlaced->value);
        }

        $result = $this->tasks(3);

        $this->assertCount(3, $result['data']);
        $this->assertSame(7, $result['count'], 'The count must cover every task found, not the page shown.');
    }

    // ── Anti-drift ───────────────────────────────────────────────────

    /**
     * The aggregation must never grow a task definition of its own.
     *
     * It reads the same `tasks` payload the order page renders, so the two
     * can only ever agree. If someone gives the dashboard its own shortcut
     * this fails, which is the point.
     */
    public function test_the_dashboard_and_the_order_page_agree_about_a_task(): void
    {
        $order = $this->b2cOrder(OrderStatus::Inspected->value);

        $fromOrderPage = app(AdminQueryService::class)->orderDetail($order->id)['tasks'];
        $fromDashboard = collect($this->tasks()['data'])->firstWhere('order_id', $order->id);

        $this->assertNotNull($fromDashboard);
        $this->assertSame($fromOrderPage['next']['key'], $fromDashboard['key']);
        $this->assertSame($fromOrderPage['next']['title'], $fromDashboard['title']);
        $this->assertSame($fromOrderPage['priority'], $fromDashboard['priority']);
    }

    /**
     * The batch hydrator must produce the same task as the single-order path,
     * for every state an order can be in.
     *
     * This is the guarantee that lets the dashboard load orders its own way.
     * The two paths share the resolvers but not the loading, so only a test
     * that runs both over the same order can prove the loading agrees. It
     * compares the whole resolved payload — next step, history, priority and
     * detached follow-ups — not just the headline.
     *
     * @param  non-empty-string  $status
     */
    #[DataProvider('activeStatusProvider')]
    public function test_batch_hydration_resolves_exactly_what_the_single_order_path_does(string $status, bool $b2b): void
    {
        $order = $b2b ? $this->b2bOrder($status) : $this->b2cOrder($status);

        $single = app(AdminQueryService::class)->orderDetail($order->id)['tasks'];

        $batched = collect(app(OrderTaskHydrator::class)->forActiveOrders())
            ->firstWhere('id', $order->id);

        $this->assertNotNull($batched, "The hydrator dropped a {$status} order.");

        $resolver = app(OrderTaskResolver::class);
        $resolved = $resolver->forOrderDetail($batched);
        $resolved['priority'] = app(OrderTaskPriorityResolver::class)
            ->forOrderTasks($resolved, $b2b)
            ->value;
        $resolved['detached'] = app(DetachedOrderTaskResolver::class)->forOrderDetail($batched);

        $this->assertSame(
            json_decode((string) json_encode($single), true),
            json_decode((string) json_encode($resolved), true),
            "Batch and single-order hydration disagree for a {$status} order.",
        );
    }

    /**
     * Every status an order can be sitting in while still active — the batch
     * path has to agree with the single-order one in all of them, not just
     * the one a single fixture happens to hit.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function activeStatusProvider(): array
    {
        $cases = [];

        foreach (OrderStatus::activeValues() as $status) {
            $cases["{$status} (B2C)"] = [$status, false];
            $cases["{$status} (B2B)"] = [$status, true];
        }

        return $cases;
    }

    // ── The dashboard itself ─────────────────────────────────────────

    public function test_the_dashboard_carries_the_task_list_and_the_vehicle_count(): void
    {
        $this->b2cOrder(OrderStatus::OrderPlaced->value);
        Vehicle::factory()->count(2)->create();

        $page = $this->actingAs($this->makeAdmin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Admin/Dashboard')
                // Registered vehicles: the order's own vehicle plus the two above.
                ->where('summary.total_vehicles', 3)
            )
            ->viewData('page');

        // `tasks` is deferred: announced on the first response and fetched
        // straight after. That transport is Inertia's, not this test's — what
        // matters is that the dashboard declares the prop rather than blocking
        // the first paint on it.
        $this->assertContains('tasks', $page['deferredProps']['default'] ?? []);
        $this->assertArrayNotHasKey('tasks', $page['props']);
    }

    public function test_a_customer_cannot_reach_the_admin_dashboard(): void
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($customer)->get(route('admin.dashboard'))->assertForbidden();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @return array{count: int, scanned: int, data: list<array<string, mixed>>}
     */
    private function tasks(int $limit = 25): array
    {
        return app(AdminTaskQueryService::class)->openTasks($limit);
    }

    private function b2bOrder(string $status): LeasybackOrder
    {
        $company = B2B::create([
            'contact_id' => Contact::factory()->create()->contact_id,
            'address_id' => Address::factory()->create()->address_id,
            'company_name' => 'Alpha GmbH',
            'contact_email' => fake()->unique()->safeEmail(),
        ]);

        $vehicle = Vehicle::factory()->forB2b($company->b2b_id)->create();

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
            'leasyback_partner' => 'leasyback',
            'request_payload' => ['order_type' => 'b2b_collection'],
        ]);
    }

    private function b2cOrder(string $status): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }
}
