<?php

namespace Tests\Feature\Order;

use App\Enums\TaskPriority;
use App\Enums\UserType;
use App\Models\LeasybackOffer;
use App\Models\OrderConfirmation;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\OrderTaskPriorityRules;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Services\PaymentService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use App\Support\PortalTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The B2C task-to-rule catalogue, exercised through real orders: every
 * priority below comes from the persisted trigger OrderTaskResolver already
 * dates the task from, never from a timestamp the priority layer looks up.
 */
class OrderTaskPriorityRulesTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private const SETUP_AT = '2026-08-25 09:00:00';

    private const APPOINTMENT_AT = '2026-09-10 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo($this->berlin(self::SETUP_AT));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------- the appointment-relative rules

    public function test_the_initial_appraisal_is_green_before_the_tuv_appointment(): void
    {
        $order = $this->awaitingInitialAppraisal();

        $this->assertSame('upload_initial_appraisal', $this->task($order)['key']);

        $this->travelTo($this->berlin('2026-09-09 12:00:00'));
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));
    }

    public function test_the_initial_appraisal_turns_yellow_exactly_24h_after_the_appointment(): void
    {
        $order = $this->awaitingInitialAppraisal();

        $this->travelTo($this->berlin('2026-09-11 08:59:59'));
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-09-11 09:00:00'));
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));
    }

    public function test_the_initial_appraisal_turns_red_exactly_48h_after_the_appointment(): void
    {
        $order = $this->awaitingInitialAppraisal();

        $this->travelTo($this->berlin('2026-09-12 08:59:59'));
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-09-12 09:00:00'));
        $this->assertSame(TaskPriority::Red->value, $this->priority($order));
    }

    public function test_the_initial_appraisal_is_timed_from_the_appointment_not_from_its_displayed_date(): void
    {
        $order = $this->awaitingInitialAppraisal();
        $task = $this->task($order);

        $this->assertNotSame($task['date'], $task['priority_date']);
        $this->assertSame(
            $this->berlin(self::APPOINTMENT_AT)->getTimestamp(),
            PortalTimestamp::instant($task['priority_date'])?->getTimestamp(),
        );
    }

    public function test_the_final_appraisal_is_timed_from_the_recorded_reinspection(): void
    {
        $order = $this->awaitingFinalAppraisal();

        $this->assertSame('upload_final_appraisal', $this->task($order)['key']);
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-26 08:59:59'));
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-26 09:00:00'));
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-27 09:00:00'));
        $this->assertSame(TaskPriority::Red->value, $this->priority($order));
    }

    // --------------------------------------------------- the elapsed-time rules

    public function test_a_workshop_request_runs_green_yellow_red_from_when_it_was_sent(): void
    {
        $order = $this->awaitingQuotations();

        $this->assertSame('await_workshop_quotations', $this->task($order)['key']);
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-26 08:59:59'));
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-26 09:00:00'));
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-27 08:59:59'));
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-27 09:00:00'));
        $this->assertSame(TaskPriority::Red->value, $this->priority($order));
    }

    public function test_the_repair_wait_runs_on_days_from_the_recorded_repair_start(): void
    {
        $order = $this->inRepair();

        $this->assertSame('await_repair', $this->task($order)['key']);

        $this->travelTo($this->berlin('2026-09-02 23:59:59'));
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-09-03 00:00:00'));
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-09-03 23:59:59'));
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-09-04 00:00:00'));
        $this->assertSame(TaskPriority::Red->value, $this->priority($order));
    }

    // -------------------------------------------------------- the immediate set

    public function test_the_appraisal_processing_tasks_are_immediately_red(): void
    {
        $order = $this->b2cOrder('confirmed');
        $this->publishDocument($order, 'gutachten');
        $this->assertImmediate($order, 'complete_initial_appraisal');

        $this->advance($order, 'inspected');
        $this->assertImmediate($order, 'capture_repair_positions');

        $this->withPositions($order);
        $this->assertImmediate($order, 'request_workshop_quotations');
    }

    public function test_the_offer_tasks_are_immediately_red(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $quotation = $this->submitted($order, 'Werkstatt A', '600.00');
        $this->assertImmediate($order, 'create_customer_offer');

        $this->draftOffer($order, $quotation);
        $this->assertImmediate($order, 'publish_customer_offer');
    }

    public function test_the_commissioning_and_repair_appointment_tasks_are_immediately_red(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->accept($order, $this->publishedOffer($order, 'Karosserie Meier GmbH', '600.00'));
        $this->assertImmediate($order, 'commission_workshop');

        $this->commission($order);
        $this->assertImmediate($order->fresh(), 'set_repair_appointment');
    }

    public function test_the_reinspection_verdict_is_immediately_red(): void
    {
        $order = $this->awaitingFinalAppraisal();
        $this->publishDocument($order, 'nachgutachten');

        $this->assertImmediate($order, 'evaluate_reinspection');
    }

    public function test_the_pickup_confirmation_runs_from_the_settled_repair_payment(): void
    {
        $order = $this->readyForPickup();
        $task = $this->task($order);

        $this->assertSame('confirm_pickup', $task['key']);
        $this->assertNotNull($task['priority_date']);
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-26 08:59:59'));
        $this->assertSame(TaskPriority::Green->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-26 09:00:00'));
        $this->assertSame(TaskPriority::Yellow->value, $this->priority($order));

        $this->travelTo($this->berlin('2026-08-27 09:00:00'));
        $this->assertSame(TaskPriority::Red->value, $this->priority($order));
    }

    // ------------------------------------------------------ the neutral cases

    public function test_an_expired_offer_carries_no_traffic_light(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->publishedOffer($order, 'Werkstatt A', '600.00', validUntil: now()->addDay()->toDateString());

        $this->travelTo($this->berlin('2026-08-29 09:00:00'));

        $this->assertSame('renew_expired_offer', $this->task($order)['key']);
        $this->assertSame(TaskPriority::Neutral->value, $this->priority($order));
    }

    public function test_the_billing_and_customer_follow_up_tasks_stay_neutral_for_now(): void
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->publishedOffer($order, 'Werkstatt A', '600.00');

        $this->travelTo($this->berlin('2026-09-30 09:00:00'));

        $this->assertSame('await_customer_decision', $this->task($order)['key']);
        $this->assertSame(TaskPriority::Neutral->value, $this->priority($order));

        $this->travelTo($this->berlin(self::SETUP_AT));
        $invoiceless = $this->awaitingFinalAppraisal();
        $this->publishDocument($invoiceless, 'nachgutachten');
        $this->advance($invoiceless->fresh(), 'delivered');
        $this->settleRepairPayment($invoiceless);

        $this->travelTo($this->berlin('2026-09-30 09:00:00'));

        $this->assertSame('provide_invoice', $this->task($invoiceless)['key']);
        $this->assertSame(TaskPriority::Neutral->value, $this->priority($invoiceless));
    }

    public function test_the_tuv_appointment_confirmation_stays_neutral_for_now(): void
    {
        $order = $this->b2cOrder('order_placed');

        $this->travelTo($this->berlin('2026-09-30 09:00:00'));

        $this->assertSame('confirm_inspection_appointment', $this->task($order)['key']);
        $this->assertSame(TaskPriority::Neutral->value, $this->priority($order));
    }

    public function test_a_task_the_catalogue_does_not_name_stays_neutral(): void
    {
        $order = $this->b2cOrder('order_requested');

        $this->travelTo($this->berlin('2026-09-30 09:00:00'));

        $this->assertSame('release_order', $this->task($order)['key']);
        $this->assertSame(TaskPriority::Neutral->value, $this->priority($order));

        $rule = app(OrderTaskPriorityRules::class)->for('a_task_that_does_not_exist');

        $this->assertFalse($rule->isTimed());
        $this->assertFalse($rule->isImmediate());
    }

    public function test_b2b_orders_stay_neutral_on_shared_task_keys(): void
    {
        $company = $this->makeCompany('Flotten GmbH');
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'inspected');

        $this->travelTo($this->berlin('2026-09-30 09:00:00'));

        $this->assertSame('request_workshop_quotations', $this->task($order)['key']);
        $this->assertSame(TaskPriority::Neutral->value, $this->tasks($order)['priority']);
    }

    // ------------------------------------------------------------- completion

    public function test_a_red_task_gives_way_to_the_next_task_once_it_is_satisfied(): void
    {
        $order = $this->inRepair();

        $this->travelTo($this->berlin('2026-09-10 00:00:00'));

        $this->assertSame('await_repair', $this->task($order)['key']);
        $this->assertSame(TaskPriority::Red->value, $this->priority($order));

        $this->advance($order, 'reinspection');
        $tasks = $this->tasks($order->fresh());

        $this->assertSame('upload_final_appraisal', $tasks['next']['key']);
        $this->assertContains('await_repair', array_column($tasks['history'], 'key'));
        $this->assertSame(TaskPriority::Green->value, $tasks['priority']);
    }

    public function test_the_catalogue_times_the_appraisal_uploads_from_an_appointment(): void
    {
        $rules = app(OrderTaskPriorityRules::class);

        $this->assertTrue($rules->for('upload_initial_appraisal')->isAppointmentRelative());
        $this->assertTrue($rules->for('upload_final_appraisal')->isAppointmentRelative());
        $this->assertFalse($rules->for('await_workshop_quotations')->isAppointmentRelative());
    }

    // ----------------------------------------------------------------- helpers

    private function assertImmediate(LeasybackOrder $order, string $expectedKey): void
    {
        $task = $this->task($order);

        $this->assertSame($expectedKey, $task['key']);
        $this->assertSame(TaskPriority::ImmediateRed->value, $this->tasks($order)['priority']);
    }

    private function berlin(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, PortalTimestamp::TIME_ZONE);
    }

    private function priority(LeasybackOrder $order): string
    {
        return $this->tasks($order)['priority'];
    }

    /**
     * @return array<string, mixed>
     */
    private function task(LeasybackOrder $order): array
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
        return json_decode((string) json_encode(
            $this->actingAs($this->makeAdmin())
                ->get(route('admin.orders.show', $order->id))
                ->viewData('page')['props']['order'],
        ), true)['tasks'];
    }

    // ------------------------------------------------------------- the states

    private function awaitingInitialAppraisal(): LeasybackOrder
    {
        $order = $this->b2cOrder('confirmed');

        OrderConfirmation::updateOrCreate(
            ['auftragsnummer' => $order->auftragsnummer],
            [
                'confirmation_date' => $this->berlin(self::APPOINTMENT_AT)->utc(),
                'confirmed_by_type' => 'api_key',
                'confirmed_by_name' => 'tuvsud',
            ],
        );

        return $order;
    }

    private function awaitingQuotations(): LeasybackOrder
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->invite($order, 'Werkstatt A');

        return $order;
    }

    private function inRepair(): LeasybackOrder
    {
        $order = $this->withPositions($this->b2cOrder('inspected'));
        $this->accept($order, $this->publishedOffer($order, 'Karosserie Meier GmbH', '600.00'));
        $this->commission($order);
        $this->saveAppointment($order->fresh(), '2026-09-01');

        return $order->fresh();
    }

    private function awaitingFinalAppraisal(): LeasybackOrder
    {
        $order = $this->inRepair();
        $this->advance($order, 'reinspection');

        return $order->fresh();
    }

    private function readyForPickup(): LeasybackOrder
    {
        $order = $this->awaitingFinalAppraisal();
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order->fresh(), 'delivered');
        $this->settleRepairPayment($order);
        $this->publishDocument($order, 'rechnung');

        return $order->fresh();
    }

    private function settleRepairPayment(LeasybackOrder $order): void
    {
        $payment = OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::Repair->value)
            ->first();

        if ($payment !== null && $payment->status === PaymentStatus::Pending) {
            app(PaymentService::class)->transition($payment, PaymentStatus::Paid);
        }
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

    private function accept(LeasybackOrder $order, LeasybackOffer $offer): void
    {
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->sole();

        $this->actingAs(User::findOrFail($vehicle->b2c_user_id))
            ->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertSessionHasNoErrors();
    }

    private function commission(LeasybackOrder $order): void
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.commission-workshop', $order->id))
            ->assertSessionHasNoErrors();
    }

    private function saveAppointment(LeasybackOrder $order, string $date): void
    {
        $this->actingAs($this->makeAdmin())
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

    private function publishDocument(LeasybackOrder $order, string $type): void
    {
        VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => $type,
            'document_title' => ucfirst($type),
            'published' => true,
        ]);
    }
}
