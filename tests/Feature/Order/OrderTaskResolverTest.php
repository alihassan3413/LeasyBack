<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\B2B;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\OrderTaskResolver;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The Admin work queue for a B2C order.
 *
 * The gap this closes: OrderTaskResolver answered only for B2B and returned
 * null for every private customer, so the entire B2C repair journey — the one
 * with the most steps and the most places to stall — was the one an admin had
 * to hold in their head. The resolver now answers for both channels from the
 * same tree.
 *
 * What is pinned here is mostly *not* status. Almost the whole commercial flow
 * happens inside `inspected`, so "no positions yet", "waiting on a workshop",
 * "waiting on the customer" and "the customer accepted" are one status and four
 * different jobs; every one of them is derived from domain data instead.
 */
class OrderTaskResolverTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ------------------------------------------------- the B2C decision tree

    public function test_an_inspected_order_without_positions_asks_for_positions(): void
    {
        $task = $this->nextTask($this->b2cOrder('inspected'));

        $this->assertSame('capture_repair_positions', $task['key']);
        $this->assertSame('Reparaturpositionen erfassen', $task['title']);
        $this->assertSame(OrderTaskResolver::SECTION_POSITIONS, $task['section']);
        $this->assertSame('open', $task['state']);
        $this->assertSame(OrderTaskResolver::ACTOR_ADMIN, $task['actor']);
    }

    public function test_positions_without_a_workshop_invitation_ask_for_quotations(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));

        $task = $this->nextTask($order);

        $this->assertSame('request_workshop_quotations', $task['key']);
        $this->assertSame('Werkstattangebote anfragen', $task['title']);
        $this->assertSame(OrderTaskResolver::SECTION_OFFERS, $task['section']);
        $this->assertSame('open', $task['state']);
    }

    public function test_an_open_invitation_is_a_wait_on_the_workshop(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->invite($order, 'Werkstatt A');

        $task = $this->nextTask($order);

        $this->assertSame('await_workshop_quotations', $task['key']);
        $this->assertSame('waiting', $task['state']);
        $this->assertSame(OrderTaskResolver::ACTOR_WORKSHOP, $task['actor']);
        $this->assertNull($task['action']);
    }

    /**
     * A link nobody can answer any more is not a workshop anyone is waiting on,
     * so the ask re-opens rather than the order sitting in a wait forever.
     */
    public function test_a_revoked_invitation_re_opens_the_ask(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $quotation = $this->invite($order, 'Werkstatt A');

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->delete(route('admin.orders.workshop-quotations.revoke', $quotation->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('request_workshop_quotations', $this->nextTask($order)['key']);
    }

    public function test_a_submitted_quotation_asks_for_a_customer_offer(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->submitted($order, 'Werkstatt A', '600.00');

        $task = $this->nextTask($order);

        $this->assertSame('create_customer_offer', $task['key']);
        $this->assertSame('Kundenangebot erstellen', $task['title']);
        $this->assertSame('open', $task['state']);
    }

    public function test_a_draft_offer_asks_to_be_published(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->draftOffer($order, $this->submitted($order, 'Werkstatt A', '600.00'));

        $task = $this->nextTask($order);

        $this->assertSame('publish_customer_offer', $task['key']);
        $this->assertSame('patch', $task['action']['method']);
        $this->assertSame(
            route('admin.orders.offers.publish', LeasybackOffer::where('order_id', $order->id)->sole()->offer_id),
            $task['action']['url'],
        );
    }

    public function test_a_published_offer_is_a_wait_on_the_customer(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->publishedOffer($order, 'Werkstatt A', '600.00');

        $task = $this->nextTask($order);

        $this->assertSame('await_customer_decision', $task['key']);
        $this->assertSame('waiting', $task['state']);
        $this->assertSame(OrderTaskResolver::ACTOR_CUSTOMER, $task['actor']);
        $this->assertNull($task['action']);
    }

    /**
     * An offer whose validity ran out cannot be accepted, so presenting it as
     * "waiting for the customer" would be waiting for something that can no
     * longer happen.
     */
    public function test_an_expired_offer_is_an_admin_task_not_a_wait(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $offer = $this->publishedOffer($order, 'Werkstatt A', '600.00', validUntil: now()->addDay()->toDateString());

        $this->travel(3)->days();

        $task = $this->nextTask($order);

        $this->assertSame('renew_expired_offer', $task['key']);
        $this->assertSame('open', $task['state']);
        $this->assertSame(OrderTaskResolver::ACTOR_ADMIN, $task['actor']);
        $this->assertSame(route('admin.orders.offers.cancel', $offer->offer_id), $task['action']['url']);
    }

    /**
     * A rejection puts the order back where it was before the offer existed —
     * there is a submitted quotation and nothing live built from it.
     */
    public function test_a_rejected_offer_asks_for_a_new_one(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $offer = $this->publishedOffer($order, 'Werkstatt A', '600.00');

        $this->actingAs($this->ownerOf($order))
            ->from('/dashboard')
            ->post(route('offers.reject', $offer->offer_id), ['customer_comment' => 'Zu teuer'])
            ->assertSessionHasNoErrors();

        $this->assertSame('create_customer_offer', $this->nextTask($order)['key']);
    }

    public function test_an_accepted_offer_asks_to_commission_the_winning_workshop(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->accept($order, $this->publishedOffer($order, 'Karosserie Meier GmbH', '600.00'));

        $task = $this->nextTask($order);

        $this->assertSame('commission_workshop', $task['key']);
        $this->assertSame('Gewählte Werkstatt beauftragen', $task['title']);
        $this->assertSame(OrderTaskResolver::SECTION_COMMISSION, $task['section']);
        $this->assertSame('post', $task['action']['method']);
        $this->assertSame(route('admin.orders.commission-workshop', $order->id), $task['action']['url']);
    }

    /**
     * A manually typed offer has no workshop behind it, so offering the
     * commissioning button would offer something the server refuses. The repair
     * still has to start — that is what is offered instead.
     */
    public function test_a_manual_offer_is_never_offered_a_commissioning_button(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->accept($order, $this->manualOffer($order));

        $task = $this->nextTask($order);

        $this->assertSame('commission_workshop', $task['key']);
        $this->assertSame('Reparatur ohne Werkstattbeauftragung starten', $task['title']);
        $this->assertSame(route('admin.orders.status', $order->id), $task['action']['url']);
        $this->assertSame(['status' => 'workshop'], $task['action']['payload']);
    }

    public function test_a_commissioned_order_asks_for_the_repair_appointment(): void
    {
        $order = $this->commissioned();

        $task = $this->nextTask($order);

        $this->assertSame('set_repair_appointment', $task['key']);
        $this->assertSame('Reparaturtermin festlegen', $task['title']);
        $this->assertSame(OrderTaskResolver::SECTION_REPAIR, $task['section']);
    }

    public function test_an_order_in_repair_is_a_wait_on_the_workshop(): void
    {
        $order = $this->commissioned();
        $this->saveAppointment($order, '2026-09-01');

        $this->assertSame('workshop', $order->fresh()->order_status);

        $task = $this->nextTask($order);

        $this->assertSame('await_repair', $task['key']);
        $this->assertSame('Reparatur abwarten', $task['title']);
        $this->assertSame('waiting', $task['state']);
        $this->assertSame(OrderTaskResolver::ACTOR_WORKSHOP, $task['actor']);
        // Waiting, but the outcome is still Admin's to record when it arrives.
        $this->assertSame(['status' => 'reinspection'], $task['action']['payload']);
    }

    public function test_a_reinspection_asks_for_the_report_then_the_verdict(): void
    {
        $order = $this->inRepair();
        $this->advance($order, 'reinspection');

        $this->assertSame('upload_final_appraisal', $this->nextTask($order)['key']);

        $this->publishDocument($order, 'nachgutachten');

        $task = $this->nextTask($order);

        $this->assertSame('evaluate_reinspection', $task['key']);
        $this->assertSame('Nachprüfung auswerten', $task['title']);
        $this->assertSame('open', $task['state']);
        $this->assertSame(['status' => 'delivered'], $task['action']['payload']);
    }

    /**
     * The failed branch. `reworkshop` is the repair phase entered a second
     * time, so it resolves back to the repair wait rather than to a step that
     * pretends the case has moved on.
     */
    public function test_a_failed_reinspection_returns_to_the_repair_wait(): void
    {
        $order = $this->inRepair();
        $this->advance($order, 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order, 'reworkshop');

        $task = $this->nextTask($order);

        $this->assertSame('await_repair', $task['key']);
        $this->assertSame('Nachbesserung abwarten', $task['title']);
        $this->assertSame('waiting', $task['state']);
        $this->assertSame(['status' => 'reinspection'], $task['action']['payload']);
    }

    public function test_a_vehicle_ready_for_pickup_asks_for_the_collection(): void
    {
        $order = $this->readyForPickup();

        $task = $this->nextTask($order);

        $this->assertSame('confirm_pickup', $task['key']);
        $this->assertSame('Fahrzeugabholung bestätigen', $task['title']);
        $this->assertSame(['status' => 'completed'], $task['action']['payload']);
    }

    public function test_a_completed_order_has_no_task(): void
    {
        $order = $this->readyForPickup();
        $this->advance($order, 'completed');

        $tasks = $this->tasks($order);

        $this->assertNull($tasks['next']);
        $this->assertTrue($tasks['is_closed']);
        $this->assertSame('completed', $tasks['closed_status']);
        $this->assertNotEmpty($tasks['history']);
    }

    // ------------------------------------------------------- the early stages

    public function test_a_requested_order_asks_for_release(): void
    {
        $task = $this->nextTask($this->b2cOrder('order_requested'));

        $this->assertSame('release_order', $task['key']);
        $this->assertSame('post', $task['action']['method']);
        $this->assertSame(route('admin.orders.approve', LeasybackOrder::sole()->id), $task['action']['url']);
    }

    public function test_a_released_order_asks_to_confirm_the_inspection_appointment(): void
    {
        $task = $this->nextTask($this->b2cOrder('order_placed'));

        $this->assertSame('confirm_inspection_appointment', $task['key']);
        $this->assertSame(['status' => 'confirmed'], $task['action']['payload']);
    }

    public function test_a_confirmed_order_asks_for_the_report_then_the_completion(): void
    {
        $order = $this->b2cOrder('confirmed');

        $this->assertSame('upload_initial_appraisal', $this->nextTask($order)['key']);

        $this->publishDocument($order, 'gutachten');

        $task = $this->nextTask($order);

        $this->assertSame('complete_initial_appraisal', $task['key']);
        $this->assertSame(['status' => 'inspected'], $task['action']['payload']);
    }

    /**
     * An unpublished report is not a report the customer has: the upload step
     * stays open until it is actually released.
     */
    public function test_an_unpublished_report_does_not_satisfy_the_upload_step(): void
    {
        $order = $this->b2cOrder('confirmed');
        $this->publishDocument($order, 'gutachten', published: false);

        $this->assertSame('upload_initial_appraisal', $this->nextTask($order)['key']);
    }

    // ------------------------------------------------------------- no dead ends

    /**
     * Every live B2C status has to produce either a task or an explicit closed
     * state — an admin looking at an open order must never be shown nothing.
     */
    public function test_no_live_b2c_status_leaves_admin_without_a_task(): void
    {
        $statuses = ['order_requested', 'order_placed', 'confirmed', 'inspected', 'workshop_commissioned', 'workshop', 'reinspection', 'reworkshop', 'delivered'];

        foreach ($statuses as $status) {
            $tasks = $this->tasks($this->b2cOrder($status));

            $this->assertFalse($tasks['is_closed'], $status.' should be live');
            $this->assertNotNull($tasks['next'], $status.' left Admin with no task');
        }
    }

    /**
     * The same walk with the data filled in as it actually would be, so the
     * mid-journey combinations are covered too — a commissioned order that
     * already carries its appointment used to be the one that fell through.
     */
    public function test_the_whole_b2c_journey_always_offers_a_next_step(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $seen = [$this->nextTask($order)['key']];

        $this->submitted($order, 'Werkstatt A', '600.00');
        $seen[] = $this->nextTask($order)['key'];

        $offer = $this->publishedOffer($order, 'Werkstatt B', '600.00');
        $seen[] = $this->nextTask($order)['key'];

        $this->accept($order, $offer);
        $seen[] = $this->nextTask($order)['key'];

        $this->commission($order);
        $seen[] = $this->nextTask($order)['key'];

        $this->saveAppointment($order->fresh(), '2026-09-01');
        $seen[] = $this->nextTask($order)['key'];

        $this->advance($order->fresh(), 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $seen[] = $this->nextTask($order)['key'];

        $this->advance($order->fresh(), 'delivered');
        $seen[] = $this->nextTask($order)['key'];

        $this->assertSame([
            'request_workshop_quotations',
            'create_customer_offer',
            'await_customer_decision',
            'commission_workshop',
            'set_repair_appointment',
            'await_repair',
            'evaluate_reinspection',
            'confirm_pickup',
        ], $seen);

        $this->advance($order->fresh(), 'completed');
        $this->assertNull($this->tasks($order)['next']);
    }

    public function test_a_cancelled_order_keeps_its_history_and_offers_nothing(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->advance($order, 'cancelled');

        $tasks = $this->tasks($order);

        $this->assertNull($tasks['next']);
        $this->assertTrue($tasks['is_closed']);
        $this->assertSame('cancelled', $tasks['closed_status']);
        // The phase it reached is still readable rather than collapsing to none.
        $this->assertContains('complete_initial_appraisal', array_column($tasks['history'], 'key'));
    }

    // ------------------------------------------------- the actions are real

    /**
     * Every action the resolver offers on the B2C journey is fired for real.
     * A task that names a button an admin cannot press is worse than no task.
     */
    public function test_every_offered_action_actually_executes(): void
    {
        $order = $this->withPositions($this->b2cOrder('confirmed'));
        $this->publishDocument($order, 'gutachten');

        // confirmed -> inspected
        $this->runNextAction($order);
        $this->assertSame('inspected', $order->fresh()->order_status);

        $this->draftOffer($order, $this->submitted($order, 'Werkstatt A', '600.00'));

        // publish the draft
        $this->runNextAction($order);
        $this->assertSame('published', LeasybackOffer::where('order_id', $order->id)->sole()->offer_status);

        $this->accept($order, LeasybackOffer::where('order_id', $order->id)->sole());

        // commission the winning workshop
        $this->runNextAction($order);
        $this->assertSame('workshop_commissioned', $order->fresh()->order_status);

        $this->saveAppointment($order->fresh(), '2026-09-01');

        // workshop -> reinspection
        $this->runNextAction($order);
        $this->assertSame('reinspection', $order->fresh()->order_status);

        $this->publishDocument($order, 'nachgutachten');

        // reinspection -> delivered
        $this->runNextAction($order);
        $this->assertSame('delivered', $order->fresh()->order_status);

        // delivered -> completed
        $this->runNextAction($order);
        $this->assertSame('completed', $order->fresh()->order_status);
    }

    /**
     * A status action may only ever name a transition the canonical graph
     * actually allows from where the order stands.
     */
    public function test_status_actions_only_name_allowed_transitions(): void
    {
        foreach (['order_placed', 'confirmed', 'workshop_commissioned', 'workshop', 'reinspection', 'reworkshop', 'delivered'] as $status) {
            $order = $this->b2cOrder($status);
            $this->publishDocument($order, 'gutachten');
            $this->publishDocument($order, 'nachgutachten');

            $action = $this->nextTask($order)['action'];

            if ($action === null || $action['url'] !== route('admin.orders.status', $order->id)) {
                continue;
            }

            $this->assertContains(
                $action['payload']['status'],
                $this->availableTransitions($order),
                sprintf('%s offers a transition to %s that the graph forbids', $status, $action['payload']['status']),
            );
        }
    }

    // ------------------------------------------------------------ authorization

    public function test_a_customer_cannot_reach_the_admin_order_page(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));

        $this->actingAs($this->ownerOf($order))
            ->get(route('admin.orders.show', $order->id))
            ->assertForbidden();
    }

    public function test_a_customer_cannot_fire_a_task_action(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->accept($order, $this->publishedOffer($order, 'Werkstatt A', '600.00'));

        $action = $this->nextTask($order)['action'];

        $this->actingAs($this->ownerOf($order))
            ->post($action['url'], $action['payload'])
            ->assertForbidden();

        $this->assertSame('inspected', $order->fresh()->order_status);
    }

    public function test_a_guest_cannot_fire_a_task_action(): void
    {
        $order = $this->b2cOrder('confirmed');
        $this->publishDocument($order, 'gutachten');

        $action = $this->nextTask($order)['action'];

        // nextTask() reads the page as an admin, and actingAs() outlives the
        // request that used it — so the guard is cleared before this fires.
        $this->app['auth']->forgetGuards();

        $this->patch($action['url'], $action['payload'])->assertRedirect(route('login'));

        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    // ------------------------------------------------------------ B2B unchanged

    /**
     * The B2B tree keeps its own keys, titles, order and status graph. Its ranks
     * differ from B2C's at every step past `confirmed`, which is exactly why the
     * two rank maps stayed separate rather than being merged into one.
     */
    public function test_the_b2b_tree_is_unchanged(): void
    {
        $expected = [
            'order_requested' => 'release_order',
            'order_placed' => 'confirm_order',
            'vehicle_collected' => 'upload_initial_appraisal',
            'workshop' => 'monitor_repair',
            'repair_completed' => 'upload_final_appraisal',
            'reinspection' => 'confirm_vehicle_returned',
            'vehicle_returned' => 'prepare_invoice',
        ];

        foreach ($expected as $status => $key) {
            $order = $this->b2bOrder($status);
            $this->setCollectionDate($order);

            $this->assertSame($key, $this->nextTask($order)['key'], $status.' changed for B2B');
        }
    }

    public function test_b2b_keeps_its_own_offer_and_commissioning_steps(): void
    {
        $order = $this->b2bOrder('inspected');
        $this->setCollectionDate($order);
        $this->withPositions($order);

        $this->assertSame('request_workshop_quotations', $this->nextTask($order)['key']);

        $offer = $this->publishedOffer($order, 'Flottenwerkstatt GmbH', '600.00');
        $task = $this->nextTask($order);
        $this->assertSame('await_customer_approval', $task['key']);
        $this->assertSame(OrderTaskResolver::ACTOR_CUSTOMER, $task['actor']);

        $this->accept($order, $offer);
        $this->assertSame('commission_workshop', $this->nextTask($order)['key']);
    }

    /**
     * B2C has no billing record and no collection logistics, so none of the
     * B2B-only steps may leak into its tree.
     */
    public function test_the_b2c_tree_carries_no_b2b_only_steps(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->publishDocument($order, 'gutachten');

        $keys = array_column($this->tasks($order)['history'], 'key');

        foreach (['confirm_collection', 'mark_vehicle_collected', 'prepare_invoice', 'mark_invoice_processed'] as $b2bOnly) {
            $this->assertNotContains($b2bOnly, $keys);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function nextTask(LeasybackOrder $order): array
    {
        $next = $this->tasks($order)['next'];

        $this->assertIsArray($next, 'the resolver offered no task');

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    private function tasks(LeasybackOrder $order): array
    {
        $tasks = $this->adminOrder($order)['tasks'];

        $this->assertIsArray($tasks, 'the resolver returned nothing at all');

        return $tasks;
    }

    /**
     * @return array<int, string>
     */
    private function availableTransitions(LeasybackOrder $order): array
    {
        return $this->adminOrder($order)['available_transitions'];
    }

    /** Fires whatever the card's action button would fire, as an admin. */
    private function runNextAction(LeasybackOrder $order): void
    {
        $action = $this->nextTask($order)['action'];

        $this->assertIsArray($action, 'the task offered no action');

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->{$action['method']}($action['url'], $action['payload'])
            ->assertSessionHasNoErrors();
    }

    /**
     * @return array<string, mixed>
     */
    private function adminOrder(LeasybackOrder $order): array
    {
        return json_decode((string) json_encode(
            $this->actingAs($this->makeAdmin())
                ->get(route('admin.orders.show', $order->id))
                ->viewData('page')['props']['order'],
        ), true);
    }

    // ------------------------------------------------------------- the fixtures

    private function b2cOrder(string $status): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
            'make' => 'Volkswagen',
            'model' => 'Passat',
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);

        // A B2C customer stores a card when booking, so the repair charge on
        // `delivered` settles and the journey reaches `confirm_pickup`. Without
        // one the order correctly stops at `await_repair_payment` instead —
        // covered separately in the payment suite.
        OrderPaymentMethod::factory()->saved()->create(['order_id' => $order->id]);

        return $order;
    }

    private function b2bOrder(string $status): LeasybackOrder
    {
        $company = $this->makeCompany(fake()->unique()->company());
        $vehicle = $this->makeB2bVehicle($company);
        $this->companiesByVehicle[$vehicle->vehicle_id] = $company;

        return $this->makeB2bOrder($vehicle, $status);
    }

    /** @var array<string, B2B> */
    private array $companiesByVehicle = [];

    /**
     * B2B's very first step is the collection appointment, which would
     * otherwise win every comparison in that channel and hide the step under
     * test. B2C has no collection at all.
     */
    private function setCollectionDate(LeasybackOrder $order): void
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.collection', $order->id), [
                'confirmed_collection_date' => '2026-08-25',
            ])->assertSessionHasNoErrors();
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

    private function invite(LeasybackOrder $order, string $company): WorkshopQuotation
    {
        return app(WorkshopQuotationService::class)
            ->invite($order, $this->makeAdmin(), ['workshop_label' => $company])['quotation'];
    }

    private function submitted(LeasybackOrder $order, string $company, string $amountNet): WorkshopQuotation
    {
        $quotation = $this->invite($order, $company);

        app(WorkshopQuotationService::class)->submit($quotation, [
            'company_name' => $company,
            'contact_person' => 'Kontakt Person',
            'contact_email' => 'kontakt@werkstatt.test',
            'contact_phone' => '+49 30 123456',
            'earliest_repair_start' => '2026-09-15',
            'processing_days' => 3,
            'items' => AppraisalPosition::where('order_id', $order->id)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (AppraisalPosition $position) => [
                    'appraisal_position_id' => $position->id,
                    'amount_net' => $amountNet,
                ])
                ->all(),
        ]);

        return $quotation->fresh();
    }

    private function draftOffer(LeasybackOrder $order, WorkshopQuotation $quotation, ?string $validUntil = null): LeasybackOffer
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), array_filter([
                'workshop_quotation_id' => $quotation->id,
                'valid_until' => $validUntil,
            ]))
            ->assertSessionHasNoErrors();

        return LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->firstOrFail();
    }

    private function publishedOffer(LeasybackOrder $order, string $company, string $amountNet, ?string $validUntil = null): LeasybackOffer
    {
        $offer = $this->draftOffer($order, $this->submitted($order, $company, $amountNet), $validUntil);

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertSessionHasNoErrors();

        return $offer->fresh();
    }

    private function manualOffer(LeasybackOrder $order): LeasybackOffer
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.offers.store', $order->id), [
                'repair_cost_net' => '500.00', 'repair_cost_gross' => '595.00',
                'depreciation_value_net' => '0', 'depreciation_value_gross' => '0',
                'workshop_repair_quote_net' => '0', 'workshop_repair_quote_gross' => '0',
                'missing_parts_cost_net' => '0', 'missing_parts_cost_gross' => '0',
            ])->assertSessionHasNoErrors();

        $offer = LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->firstOrFail();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertSessionHasNoErrors();

        return $offer->fresh();
    }

    private function accept(LeasybackOrder $order, LeasybackOffer $offer): void
    {
        $this->actingAs($this->ownerOf($order))
            ->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertSessionHasNoErrors();
    }

    private function commission(LeasybackOrder $order): TestResponse
    {
        return $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.commission-workshop', $order->id))
            ->assertSessionHasNoErrors();
    }

    private function saveAppointment(LeasybackOrder $order, string $date): TestResponse
    {
        return $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.repair-appointment', $order->id), ['confirmed_repair_start_date' => $date])
            ->assertSessionHasNoErrors();
    }

    private function advance(LeasybackOrder $order, string $status): void
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.status', $order->id), ['status' => $status])
            ->assertSessionHasNoErrors();
    }

    private function publishDocument(LeasybackOrder $order, string $type, bool $published = true): void
    {
        VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => $type,
            'document_title' => ucfirst($type),
            'published' => $published,
        ]);
    }

    /** An order that has been commissioned and is waiting for its appointment. */
    private function commissioned(): LeasybackOrder
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->accept($order, $this->publishedOffer($order, 'Karosserie Meier GmbH', '600.00'));
        $this->commission($order);

        return $order->fresh();
    }

    /** An order in the workshop, appointment saved. */
    private function inRepair(): LeasybackOrder
    {
        $order = $this->commissioned();
        $this->saveAppointment($order, '2026-09-01');

        return $order->fresh();
    }

    private function readyForPickup(): LeasybackOrder
    {
        $order = $this->inRepair();
        $this->advance($order, 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order->fresh(), 'delivered');

        return $order->fresh();
    }

    private function ownerOf(LeasybackOrder $order): User
    {
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->sole();

        return $vehicle->b2c_user_id !== null
            ? User::findOrFail($vehicle->b2c_user_id)
            : $this->makeOwner($this->companiesByVehicle[$vehicle->vehicle_id]);
    }
}
