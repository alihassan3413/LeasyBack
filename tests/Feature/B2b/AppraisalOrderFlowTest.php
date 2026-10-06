<?php

namespace Tests\Feature\B2b;

use App\Mail\Orders\AppraisalCompletedMail;
use App\Mail\Orders\AppraisalScheduledMail;
use App\Models\B2B;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAttachment;
use App\Modules\UserProfile\Order\Models\OrderVehicle;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The Gutachten (Vehicle Condition Appraisal) service end to end through the
 * Admin pages, against its brief of 17 September 2026: operations see the
 * whole order, confirm or adjust the appointment, coordinate the inspection
 * site, add the report that completes the order; the customer sees it all;
 * the two timed tasks; the statistics view — and that the other services stay
 * as they were. Booking itself is covered by AppraisalOrderBookingTest.
 */
class AppraisalOrderFlowTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private B2B $company;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Storage::fake(OrderAttachment::DISK);

        $this->company = $this->makeCompany('Flotte GmbH');
        $this->owner = $this->makeOwner($this->company);
        $this->admin = $this->makeAdmin();
    }

    // ------------------------------------------------------- operations

    public function test_operations_see_the_whole_order(): void
    {
        $order = $this->bookAppraisal(2);

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertSame('gutachten', $detail['service_type']);
        $this->assertSame('VW Leasing', data_get($detail, 'request_payload.leasing_company'));
        $this->assertSame('Köln', data_get($detail, 'request_payload.vehicle_location.city'));
        $this->assertCount(2, $detail['vehicles']);
        $this->assertSame(
            OrderVehicle::where('order_id', $order->id)->orderBy('position')->pluck('vehicle_id')->all(),
            array_column($detail['vehicles'], 'vehicle_id'),
        );
        $this->assertTrue($detail['editable']['collection']);
        $this->assertSame('appraisal_schedule_appointment', $detail['tasks']['next']['key']);

        $this->actingAs($this->admin)->get(route('admin.orders.index', ['service' => 'gutachten']))->assertOk();
    }

    public function test_confirming_the_appointment_schedules_the_order(): void
    {
        $order = $this->bookAppraisal(2);

        $this->schedule($order)->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $order->fresh()->order_status);

        $logistics = DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->first();
        $this->assertSame('08:00-12:00', $logistics->confirmed_collection_time_slot);
        $this->assertSame('DEKRA Köln', $logistics->inspection_site_name);
        $this->assertTrue((bool) $logistics->transport_confirmed);

        Mail::assertQueued(AppraisalScheduledMail::class);
        $this->assertSame('appraisal_add_report', app(AdminQueryService::class)->orderDetail($order->id)['tasks']['next']['key']);

        // The customer sees the confirmed appointment and the inspection site.
        $this->actingAs($this->owner)
            ->get(route('orders.show', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.collection.confirmed_collection_time_slot', '08:00-12:00')
                ->where('order.collection.inspection_site_name', 'DEKRA Köln')
                ->where('order.collection.transport_confirmed', true)
                ->has('order.vehicles', 2));
    }

    public function test_the_appointment_can_be_adjusted_afterwards(): void
    {
        $order = $this->bookAppraisal(1);
        $this->schedule($order)->assertSessionHasNoErrors();

        $moved = now()->addDays(9)->toDateString();

        $this->schedule($order, [
            'confirmed_collection_date' => $moved,
            'confirmed_time_from' => '13:00',
            'confirmed_time_to' => '16:00',
        ])->assertSessionHasNoErrors();

        $logistics = DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->first();
        $this->assertSame($moved, substr((string) $logistics->confirmed_collection_date, 0, 10));
        $this->assertSame('13:00-16:00', $logistics->confirmed_collection_time_slot);
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_a_date_needs_a_full_time_window_of_two_hours(): void
    {
        $order = $this->bookAppraisal(1);

        $this->schedule($order, ['confirmed_time_from' => null, 'confirmed_time_to' => null])
            ->assertSessionHasErrors('confirmed_time_from');

        $this->schedule($order, ['confirmed_time_from' => '10:00', 'confirmed_time_to' => '11:30'])
            ->assertSessionHasErrors('confirmed_time_to');

        $this->assertSame('order_requested', $order->fresh()->order_status);
    }

    public function test_the_order_cannot_be_completed_without_the_report(): void
    {
        $order = $this->bookAppraisal(1);
        $this->schedule($order);

        $this->assertNotContains('completed', app(AdminQueryService::class)->orderDetail($order->id)['available_transitions']);

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'completed'])->assertSessionHasErrors();
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_the_report_cannot_be_added_before_scheduling(): void
    {
        $order = $this->bookAppraisal(1);

        $this->uploadReport($order)->assertSessionHasErrors('files');

        $this->assertSame(0, OrderAttachment::where('order_id', $order->id)->count());
        $this->assertSame('order_requested', $order->fresh()->order_status);
    }

    public function test_the_report_completes_the_order_and_reaches_the_customer(): void
    {
        $order = $this->bookAppraisal(2);
        $this->schedule($order);

        $this->uploadReport($order)->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->order_status);
        Mail::assertQueued(AppraisalCompletedMail::class);

        $detail = app(AdminQueryService::class)->orderDetail($order->id);
        $this->assertTrue($detail['tasks']['is_closed']);
        $this->assertCount(1, $detail['attachments']);

        $this->actingAs($this->owner)
            ->get(route('orders.show', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.order_status', 'completed')
                ->has('order.attachments', 1)
                ->where('order.attachments.0.kind', 'final_document')
                ->where('order.attachments.0.original_name', 'gutachten.pdf'));
    }

    public function test_a_completed_order_always_keeps_a_report(): void
    {
        $order = $this->bookAppraisal(1);
        $this->schedule($order);
        $this->uploadReport($order);

        $first = OrderAttachment::where('order_id', $order->id)->sole();

        $this->adminDelete(route('admin.orders.appraisal.report.destroy', $first->id))->assertSessionHasErrors('files');
        $this->assertSame(1, OrderAttachment::where('order_id', $order->id)->count());

        // A corrected report is added first, then the old one can go.
        $this->uploadReport($order, 'gutachten-korrigiert.pdf')->assertSessionHasNoErrors();
        $this->adminDelete(route('admin.orders.appraisal.report.destroy', $first->id))->assertSessionHasNoErrors();

        $this->assertSame('gutachten-korrigiert.pdf', OrderAttachment::where('order_id', $order->id)->sole()->original_name);
        $this->assertSame('completed', $order->fresh()->order_status);
    }

    public function test_an_appraisal_never_enters_the_repair_process(): void
    {
        $order = $this->bookAppraisal(1);
        $this->schedule($order);

        $this->assertNotContains('vehicle_collected', TransitionOrderStatus::allowedNextStatuses('confirmed', true, true));
        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'vehicle_collected'])->assertSessionHasErrors();
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    // ----------------------------------------------------- traffic lights

    public function test_the_appointment_task_turns_yellow_at_24_hours_and_red_at_48_hours(): void
    {
        $order = $this->bookAppraisal(1);

        $this->assertSame('green', $this->taskPriority($order, 'appraisal_schedule_appointment'));

        $this->travel(25)->hours();
        $this->assertSame('yellow', $this->taskPriority($order, 'appraisal_schedule_appointment'));

        $this->travel(24)->hours();
        $this->assertSame('red', $this->taskPriority($order, 'appraisal_schedule_appointment'));
    }

    public function test_the_report_task_counts_calendar_days_from_the_appointment_date(): void
    {
        $order = $this->bookAppraisal(1);
        $date = now()->addDays(2)->toDateString();
        $this->schedule($order, ['confirmed_collection_date' => $date]);

        $noon = CarbonImmutable::parse($date.' 12:00:00', 'Europe/Berlin');

        $this->assertSame('green', $this->taskPriority($order, 'appraisal_add_report'), 'before the appointment');

        $this->travelTo($noon);
        $this->assertSame('green', $this->taskPriority($order, 'appraisal_add_report'), 'on the appointment day');

        $this->travelTo($noon->addDay());
        $this->assertSame('yellow', $this->taskPriority($order, 'appraisal_add_report'), 'the day after');

        $this->travelTo($noon->addDays(2));
        $this->assertSame('red', $this->taskPriority($order, 'appraisal_add_report'), 'from the second day after');
    }

    // ------------------------------------------------------ customer list

    public function test_the_customer_order_list_speaks_for_every_vehicle(): void
    {
        $order = $this->bookAppraisal(3);
        $plates = Vehicle::whereIn('vehicle_id', OrderVehicle::where('order_id', $order->id)->orderBy('position')->pluck('vehicle_id'))
            ->pluck('license_plate', 'vehicle_id');
        $lastVehicleId = OrderVehicle::where('order_id', $order->id)->orderByDesc('position')->value('vehicle_id');

        $service = app(VehicleService::class);

        $rows = $service->listCustomerOrders($this->company->b2b_id, 'B2B', [], $this->owner);
        $this->assertCount(1, $rows, 'one order, not one row per vehicle');
        $this->assertSame('gutachten', $rows[0]['service_type']);
        $this->assertSame(3, $rows[0]['vehicle_count']);
        $this->assertSame('Köln', $rows[0]['location']);

        // Found by a vehicle that is not the first one of the order.
        $found = $service->listCustomerOrders($this->company->b2b_id, 'B2B', ['search' => $plates[$lastVehicleId]], $this->owner);
        $this->assertCount(1, $found);
        $this->assertSame($order->id, $found[0]['id']);
    }

    // -------------------------------------------------------- statistics

    public function test_the_statistics_count_the_three_statuses_and_filter(): void
    {
        $this->bookAppraisal(1);

        $scheduled = $this->bookAppraisal(2);
        $this->schedule($scheduled);

        $done = $this->bookAppraisal(1);
        $this->schedule($done);
        $this->uploadReport($done);

        // Other services are not counted.
        $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'confirmed');

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Statistics/Appraisal')
                ->where('totals.total', 3)
                ->where('totals.requested', 1)
                ->where('totals.scheduled', 1)
                ->where('totals.completed', 1)
                ->has('companies', 1));

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal', ['status' => 'completed']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 1)->where('totals.completed', 1));

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal', ['company' => $this->company->b2b_id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 3));

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal', ['start_date' => now()->addDay()->toDateString()]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 0));

        // The vehicle filter finds an order by its second vehicle too.
        $secondVehicleId = OrderVehicle::where('order_id', $scheduled->id)->orderByDesc('position')->value('vehicle_id');
        $plate = Vehicle::where('vehicle_id', $secondVehicleId)->value('license_plate');

        $this->actingAs($this->admin)->get(route('admin.statistics.appraisal', ['vehicle' => $plate]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 1)->where('totals.scheduled', 1));
    }

    // ------------------------------------------------ other services kept

    public function test_the_other_services_are_unchanged(): void
    {
        $this->assertSame(['vehicle_collected', 'cancelled'], TransitionOrderStatus::allowedNextStatuses('confirmed', true));
        $this->assertSame(['inspected', 'cancelled'], TransitionOrderStatus::allowedNextStatuses('vehicle_collected', true));

        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_collected');
        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertSame('neutral', $detail['tasks']['priority']);
        $this->assertSame([], $detail['vehicles'], 'a single-vehicle order lists no further vehicles');
        $this->assertFalse(TransitionOrderStatus::isAppraisalOrder($order));
        $this->assertFalse(TransitionOrderStatus::isShortPathOrder($order));
    }

    // ------------------------------------------------------------ helpers

    private function bookAppraisal(int $vehicleCount): LeasybackOrder
    {
        $vehicles = [];

        for ($i = 0; $i < $vehicleCount; $i++) {
            $vehicles[] = $this->makeB2bVehicle($this->company);
        }

        $known = LeasybackOrder::where('service_type', 'gutachten')->pluck('id')->all();

        $this->actingAs($this->owner)->from('/dashboard')->post(route('orders.b2b.appraisal.store'), [
            'vehicle_ids' => array_map(fn (Vehicle $vehicle) => $vehicle->vehicle_id, $vehicles),
            'billing_address' => ['name' => 'Zentrale', 'street' => 'Hauptstr.', 'number' => '1', 'zip_code' => '50667', 'city' => 'Köln', 'country' => 'Deutschland'],
            'vehicle_location' => ['street' => 'Domstr.', 'number' => '3', 'zip_code' => '50667', 'city' => 'Köln'],
            'pickup_requested' => true,
            'return_transport' => false,
            'leasing_company' => 'VW Leasing',
            'preferred_date' => now()->addDays(5)->toDateString(),
            'time_from' => '08:00',
            'time_to' => '12:00',
            'location_contact' => ['name' => 'Anna Standort', 'phone' => '+49 221 1234', 'email' => 'anna@example.test'],
            'notes' => 'Schlüssel beim Empfang',
        ])->assertSessionHasNoErrors();

        return LeasybackOrder::where('service_type', 'gutachten')->whereNotIn('id', $known)->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function schedule(LeasybackOrder $order, array $overrides = []): TestResponse
    {
        return $this->adminPatch(route('admin.orders.appraisal.schedule', $order->id), array_replace([
            'confirmed_collection_date' => now()->addDays(6)->toDateString(),
            'confirmed_time_from' => '08:00',
            'confirmed_time_to' => '12:00',
            'inspection_site_name' => 'DEKRA Köln',
            'inspection_site_address' => 'Prüfweg 1, 50667 Köln',
            'transport_confirmed' => true,
        ], $overrides));
    }

    private function uploadReport(LeasybackOrder $order, string $name = 'gutachten.pdf'): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')
            ->post(route('admin.orders.appraisal.report.store', $order->id), [
                'files' => [UploadedFile::fake()->create($name, 300, 'application/pdf')],
            ]);
    }

    /** The colour of the order's next Admin task, after checking it is the expected one. */
    private function taskPriority(LeasybackOrder $order, string $expectedTask): string
    {
        $tasks = app(AdminQueryService::class)->orderDetail($order->id)['tasks'];

        $this->assertSame($expectedTask, $tasks['next']['key'] ?? null);

        return $tasks['priority'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function adminPatch(string $url, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->patch($url, $data);
    }

    private function adminDelete(string $url): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->delete($url);
    }
}
