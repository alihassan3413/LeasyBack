<?php

namespace Tests\Feature\Order;

use App\Enums\TaskPriority;
use App\Enums\UserType;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\DetachedOrderTaskResolver;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Support\PortalTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The customer offer follow-up: a task that stands beside the guided workflow
 * instead of inside it, derived from the published offer's own lifecycle.
 */
class DetachedOrderTaskTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private const PUBLISHED_AT = '2026-09-01 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['broadcasting.default' => 'null']);
        $this->travelTo($this->berlin(self::PUBLISHED_AT));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ----------------------------------------------------------- the trigger

    public function test_a_published_offer_younger_than_48_hours_has_no_follow_up(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->assertSame([], $this->detached($order));

        $this->travelTo($this->berlin('2026-09-03 08:59:59'));

        $this->assertSame([], $this->detached($order));
    }

    public function test_the_follow_up_appears_at_exactly_48_hours(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));

        $task = $this->onlyDetached($order);

        $this->assertSame(DetachedOrderTaskResolver::CALL_CUSTOMER_ABOUT_PENDING_OFFER, $task['key']);
        $this->assertSame('open', $task['state']);
        $this->assertSame('admin', $task['actor']);
        $this->assertSame(
            $this->berlin(self::PUBLISHED_AT)->getTimestamp(),
            PortalTimestamp::instant($task['date'])?->getTimestamp(),
        );
    }

    public function test_the_follow_up_is_immediately_red_and_never_green_or_yellow(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));
        $this->assertSame(TaskPriority::ImmediateRed->value, $this->onlyDetached($order)['priority']);

        $this->travelTo($this->berlin('2026-09-20 09:00:00'));
        $task = $this->onlyDetached($order);

        $this->assertSame(TaskPriority::ImmediateRed->value, $task['priority']);
        $this->assertTrue(TaskPriority::from($task['priority'])->isOverdue());
    }

    public function test_the_48_hour_boundary_is_the_same_instant_in_any_zone(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->travelTo(CarbonImmutable::parse('2026-09-03 06:59:59', 'UTC'));
        $this->assertSame([], $this->detached($order));

        $this->travelTo(CarbonImmutable::parse('2026-09-03 07:00:00', 'UTC'));
        $this->assertCount(1, $this->detached($order));

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));
        $this->assertCount(1, $this->detached($order));
    }

    public function test_repeated_reads_produce_one_logical_follow_up(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->travelTo($this->berlin('2026-09-05 09:00:00'));

        $first = $this->detached($order);
        $second = $this->detached($order);
        $third = $this->detached($order);

        $this->assertCount(1, $first);
        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    // --------------------------------------------------------- the coexistence

    public function test_the_guided_task_is_untouched_while_the_follow_up_is_due(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->travelTo($this->berlin('2026-09-05 09:00:00'));

        $tasks = $this->tasks($order);

        $this->assertSame('await_customer_decision', $tasks['next']['key']);
        $this->assertSame('waiting', $tasks['next']['state']);
        $this->assertSame('customer', $tasks['next']['actor']);
        $this->assertSame(TaskPriority::Neutral->value, $tasks['priority']);
        $this->assertCount(1, $tasks['detached']);
        $this->assertNotSame($tasks['next']['key'], $tasks['detached'][0]['key']);
    }

    // ---------------------------------------------------------- the completion

    public function test_accepting_before_48_hours_never_produces_the_follow_up(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->travelTo($this->berlin('2026-09-02 09:00:00'));
        $this->accept($order, $this->publishedOfferOf($order));

        $this->travelTo($this->berlin('2026-09-06 09:00:00'));

        $this->assertSame([], $this->detached($order));
    }

    public function test_accepting_after_the_follow_up_is_due_leaves_nothing_stale(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));
        $this->assertCount(1, $this->detached($order));

        $this->accept($order, $this->publishedOfferOf($order));

        $this->assertSame([], $this->detached($order));
    }

    public function test_a_customer_reply_after_publication_completes_the_follow_up(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));
        $this->assertCount(1, $this->detached($order));

        $this->customerWrites($order, 'Ich melde mich morgen.');

        $this->assertSame([], $this->detached($order));
    }

    public function test_a_customer_message_from_before_publication_does_not_complete_it(): void
    {
        $this->travelTo($this->berlin('2026-09-01 08:00:00'));
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->customerWrites($order, 'Wann kommt das Angebot?');

        $this->travelTo($this->berlin(self::PUBLISHED_AT));
        $this->publishOfferFor($order);

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));

        $this->assertCount(1, $this->detached($order));
    }

    public function test_an_admin_message_does_not_complete_the_follow_up(): void
    {
        $order = $this->orderWithPublishedOffer();

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));

        $this->actingAs($this->makeAdmin())
            ->postJson(route('orders.messages.store', $order->id), ['body' => 'Wir haben versucht Sie zu erreichen.'])
            ->assertCreated();

        $this->assertCount(1, $this->detached($order));
    }

    // ------------------------------------------------------- the offer identity

    public function test_an_expired_offer_produces_no_follow_up(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->publishOfferFor($order, validUntil: $this->berlin('2026-09-02 09:00:00')->toDateString());

        $this->travelTo($this->berlin('2026-09-05 09:00:00'));

        $this->assertSame('renew_expired_offer', $this->tasks($order)['next']['key']);
        $this->assertSame([], $this->detached($order));
    }

    public function test_the_clock_belongs_to_the_offer_that_is_published_now(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $first = $this->publishOfferFor($order);

        $this->travelTo($this->berlin('2026-09-02 09:00:00'));
        $this->cancelOffer($first);
        $second = $this->publishOfferFor($order);

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));
        $this->assertSame([], $this->detached($order));

        $this->travelTo($this->berlin('2026-09-04 09:00:00'));
        $task = $this->onlyDetached($order);

        $this->assertSame(
            $this->berlin('2026-09-02 09:00:00')->getTimestamp(),
            PortalTimestamp::instant($task['date'])?->getTimestamp(),
        );
        $this->assertNotSame(
            $this->berlin(self::PUBLISHED_AT)->getTimestamp(),
            PortalTimestamp::instant($task['date'])?->getTimestamp(),
        );
        $this->assertSame($second->offer_id, LeasybackOffer::where('order_id', $order->id)
            ->where('offer_status', 'published')->sole()->offer_id);
    }

    // ------------------------------------------------------------- the guards

    public function test_a_cancelled_order_surfaces_no_follow_up(): void
    {
        $order = $this->orderWithPublishedOffer();
        $order->update(['order_status' => 'cancelled']);

        $this->travelTo($this->berlin('2026-09-06 09:00:00'));

        $this->assertSame([], $this->detached($order->fresh()));
    }

    public function test_b2b_orders_never_receive_the_follow_up(): void
    {
        $company = $this->makeCompany('Flotten GmbH');
        $vehicle = $this->makeB2bVehicle($company);
        $order = $this->makeB2bOrder($vehicle, 'inspected');
        $this->withPositions($order);
        $this->publishOfferFor($order);

        $this->travelTo($this->berlin('2026-09-06 09:00:00'));

        $this->assertSame([], $this->detached($order));
    }

    // ----------------------------------------------------------------- helpers

    private function berlin(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, PortalTimestamp::TIME_ZONE);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detached(LeasybackOrder $order): array
    {
        return $this->tasks($order)['detached'];
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyDetached(LeasybackOrder $order): array
    {
        $detached = $this->detached($order);

        $this->assertCount(1, $detached);

        return $detached[0];
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

    // ------------------------------------------------------------- the states

    private function orderWithPublishedOffer(): LeasybackOrder
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->publishOfferFor($order);

        return $order;
    }

    private function publishedOfferOf(LeasybackOrder $order): LeasybackOffer
    {
        return LeasybackOffer::where('order_id', $order->id)->where('offer_status', 'published')->sole();
    }

    // ----------------------------------------------------------- the fixtures

    private function b2cOrder(string $status): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);

        OrderPaymentMethod::factory()->saved()->create(['order_id' => $order->id]);

        return $order;
    }

    private function withPositions(LeasybackOrder $order): LeasybackOrder
    {
        AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Stoßfänger vorne',
            'damage_description' => 'Kratzer',
            'original_amount_net' => '1000.00',
            'repair_method' => 'Instandsetzung',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);

        return $order;
    }

    private function submitted(LeasybackOrder $order, string $company): WorkshopQuotation
    {
        $quotation = app(WorkshopQuotationService::class)
            ->invite($order, $this->makeAdmin(), ['workshop_label' => $company])['quotation'];

        app(WorkshopQuotationService::class)->submit($quotation, [
            'company_name' => $company,
            'contact_person' => 'Kontakt Person',
            'contact_email' => 'kontakt@werkstatt.test',
            'contact_phone' => '+49 30 123456',
            'earliest_repair_start' => '2026-10-15',
            'processing_days' => 3,
            'items' => AppraisalPosition::where('order_id', $order->id)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (AppraisalPosition $position) => [
                    'appraisal_position_id' => $position->id,
                    'amount_net' => '600.00',
                ])
                ->all(),
        ]);

        return $quotation->fresh();
    }

    private function publishOfferFor(LeasybackOrder $order, ?string $validUntil = null): LeasybackOffer
    {
        $quotation = $this->submitted($order, 'Werkstatt '.LeasybackOffer::where('order_id', $order->id)->count());

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), array_filter([
                'workshop_quotation_id' => $quotation->id,
                'valid_until' => $validUntil,
            ]))
            ->assertSessionHasNoErrors();

        $offer = LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->firstOrFail();

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertSessionHasNoErrors();

        return $offer->fresh();
    }

    private function cancelOffer(LeasybackOffer $offer): void
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $offer->order_id))
            ->patch(route('admin.orders.offers.cancel', $offer->offer_id))
            ->assertSessionHasNoErrors();
    }

    private function accept(LeasybackOrder $order, LeasybackOffer $offer): void
    {
        $this->actingAs($this->ownerOf($order))
            ->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertSessionHasNoErrors();
    }

    private function customerWrites(LeasybackOrder $order, string $body): void
    {
        $this->actingAs($this->ownerOf($order))
            ->postJson(route('orders.messages.store', $order->id), ['body' => $body])
            ->assertCreated();
    }

    private function ownerOf(LeasybackOrder $order): User
    {
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->sole();

        return User::findOrFail($vehicle->b2c_user_id);
    }
}
