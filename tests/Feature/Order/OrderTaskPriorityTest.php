<?php

namespace Tests\Feature\Order;

use App\Enums\TaskPriority;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\OrderTaskPriorityRules;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Support\PortalTimestamp;
use App\Support\TaskPriorityRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The Admin task priority system, end to end: OrderTaskResolver still answers
 * "which task", OrderTaskPriorityResolver answers "how urgent is it", and the
 * answer is derived from the task's persisted business timestamp rather than
 * from anything the frontend times or the database stores as a colour.
 */
class OrderTaskPriorityTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private const PLACED_AT = '2026-09-01 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_no_rule_leaves_every_task_neutral(): void
    {
        $order = $this->b2cOrder();

        $this->travelTo('2026-10-01 08:00:00');

        $this->assertSame(TaskPriority::Neutral->value, $this->priority($order));
    }

    public function test_a_timed_task_runs_green_then_yellow_then_red(): void
    {
        $this->rules(['release_order' => TaskPriorityRule::afterHours(24, 48)]);
        $order = $this->b2cOrder();

        $this->travelTo('2026-09-01 09:00:00');
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo('2026-09-02 07:59:59');
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo('2026-09-02 08:00:00');
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));

        $this->travelTo('2026-09-03 07:59:59');
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));

        $this->travelTo('2026-09-03 08:00:00');
        $this->assertSame(TaskPriority::Red->value, $this->priority($order));
    }

    public function test_a_red_task_stays_red_until_its_business_condition_is_satisfied(): void
    {
        $this->rules(['release_order' => TaskPriorityRule::afterHours(24, 48)]);
        $order = $this->b2cOrder();

        $this->travelTo('2026-09-20 08:00:00');

        $this->assertSame('release_order', $this->tasks($order)['next']['key']);
        $this->assertSame(TaskPriority::Red->value, $this->priority($order));

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.approve', $order->id))
            ->assertSessionHasNoErrors();

        $tasks = $this->tasks($order->fresh());

        $this->assertSame('confirm_inspection_appointment', $tasks['next']['key']);
        $this->assertContains('release_order', array_column($tasks['history'], 'key'));
        $this->assertSame(TaskPriority::Neutral->value, $tasks['priority']);
    }

    public function test_an_immediate_task_is_red_from_its_first_second(): void
    {
        $this->rules(['release_order' => TaskPriorityRule::immediate()]);
        $order = $this->b2cOrder();

        $this->travelTo('2026-09-01 08:00:01');
        $this->assertSame(TaskPriority::ImmediateRed->value, $this->priority($order));

        $this->travelTo('2026-09-20 08:00:00');
        $this->assertSame(TaskPriority::ImmediateRed->value, $this->priority($order));
    }

    public function test_a_closed_order_carries_no_priority(): void
    {
        $this->rules(['release_order' => TaskPriorityRule::immediate()]);
        $order = $this->b2cOrder();
        $order->update(['order_status' => 'cancelled']);

        $this->travelTo('2026-09-20 08:00:00');

        $tasks = $this->tasks($order->fresh());

        $this->assertTrue($tasks['is_closed']);
        $this->assertNull($tasks['next']);
        $this->assertSame(TaskPriority::Neutral->value, $tasks['priority']);
    }

    /**
     * The boundary is an instant, not a wall clock. The order's timestamp is
     * stored in UTC and the verdict is taken in Europe/Berlin, so the colour
     * must flip 24 hours after the stored instant — 10:00 Berlin, not 10:00
     * UTC — and the payload must hand the frontend the same instant back.
     */
    public function test_evaluation_is_timezone_safe(): void
    {
        $this->rules(['release_order' => TaskPriorityRule::afterHours(24, 48)]);
        $order = $this->b2cOrder();

        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:59:59', PortalTimestamp::TIME_ZONE));
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo(CarbonImmutable::parse('2026-09-02 10:00:00', PortalTimestamp::TIME_ZONE));
        $tasks = $this->tasks($order);

        $this->assertSame(TaskPriority::Yellow->value, $tasks['priority']);

        $date = PortalTimestamp::instant($tasks['next']['date']);

        $this->assertSame(PortalTimestamp::TIME_ZONE, $date?->timezoneName);
        $this->assertSame(
            CarbonImmutable::parse(self::PLACED_AT, 'UTC')->getTimestamp(),
            $date?->getTimestamp(),
        );
    }

    public function test_b2b_orders_are_never_prioritised(): void
    {
        $this->rules([
            'release_order' => TaskPriorityRule::immediate(),
            'confirm_collection' => TaskPriorityRule::afterHours(24, 48),
        ]);

        $company = $this->makeCompany('Flotten GmbH');
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'order_requested');
        $order->forceFill(['created_at' => self::PLACED_AT])->save();

        $this->travelTo('2026-09-20 08:00:00');

        $tasks = $this->tasks($order);

        $this->assertSame('confirm_collection', $tasks['next']['key']);
        $this->assertSame(TaskPriority::Neutral->value, $tasks['priority']);
    }

    /**
     * @param  array<string, TaskPriorityRule>  $rules
     */
    private function rules(array $rules): void
    {
        $this->app->bind(OrderTaskPriorityRules::class, fn () => new class($rules) extends OrderTaskPriorityRules
        {
            /**
             * @param  array<string, TaskPriorityRule>  $rules
             */
            public function __construct(private readonly array $rules) {}

            protected function definitions(): array
            {
                return $this->rules;
            }
        });
    }

    private function b2cOrder(): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_requested',
        ]);

        $order->forceFill(['created_at' => CarbonImmutable::parse(self::PLACED_AT, 'UTC')])->save();

        OrderPaymentMethod::factory()->saved()->create(['order_id' => $order->id]);

        return $order->fresh();
    }

    private function priority(LeasybackOrder $order): string
    {
        return $this->tasks($order)['priority'];
    }

    /**
     * @return array<string, mixed>
     */
    private function tasks(LeasybackOrder $order): array
    {
        return json_decode((string) json_encode(
            $this->actingAs($this->makeAdmin())
                ->get(route('admin.orders.show', $order->id))
                ->viewData('page')['props']['order'],
        ), true)['tasks'];
    }
}
