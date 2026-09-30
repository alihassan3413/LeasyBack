<?php

namespace Tests\Feature\B2b;

use App\Mail\Orders\B2bCollectionRequestedMail;
use App\Mail\Orders\B2bCollectionScheduledMail;
use App\Mail\Orders\RelocationCompletedMail;
use App\Mail\Orders\RelocationRequestedMail;
use App\Mail\Orders\RelocationScheduledMail;
use App\Models\B2B;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use App\Modules\UserProfile\Order\Services\OrderTaskPriorityResolver;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The Überführung (vehicle relocation) service: booking one or several
 * vehicles, its REQUESTED → SCHEDULED → COMPLETED path, the two timed Admin
 * tasks of the traffic-light spec, and that it leaves the Leasingrückgabe
 * process exactly as it was.
 */
class RelocationOrderFlowTest extends TestCase
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
        Storage::fake('documents');

        $this->company = $this->makeCompany('Flotte GmbH');
        $this->owner = $this->makeOwner($this->company);
        $this->admin = $this->makeAdmin();
    }

    // ----------------------------------------------------------- booking

    public function test_a_company_can_book_one_relocation_per_selected_vehicle(): void
    {
        $first = $this->makeB2bVehicle($this->company);
        $second = $this->makeB2bVehicle($this->company);

        $this->book([$first->vehicle_id, $second->vehicle_id])->assertCreated();

        $orders = LeasybackOrder::where('service_type', 'ueberfuehrung')->get();
        $this->assertCount(2, $orders);
        $this->assertEqualsCanonicalizing([$first->vehicle_id, $second->vehicle_id], $orders->pluck('vehicle_id')->all());

        foreach ($orders as $order) {
            $this->assertSame('order_requested', $order->order_status);
            $this->assertSame('vehicle_relocation', data_get($order->request_payload, 'order_type'));
            $this->assertSame('08:00-12:00', data_get($order->request_payload, 'time_slot'));
            $this->assertSame('Berlin', data_get($order->request_payload, 'destination_address.city'));
            $this->assertArrayNotHasKey('vehicle_ids', (array) $order->request_payload);
            $this->assertDatabaseHas('leasyback_order_audit_log', ['order_id' => $order->id, 'action' => 'REQUEST_RELOCATION']);
        }

        Mail::assertQueued(RelocationRequestedMail::class, 2);
        Mail::assertNotQueued(B2bCollectionRequestedMail::class);
    }

    public function test_the_time_window_must_be_at_least_two_hours(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);

        $this->book([$vehicle->vehicle_id], ['time_from' => '08:00', 'time_to' => '09:30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('time_to');

        $this->assertSame(0, LeasybackOrder::count());
    }

    public function test_a_started_billing_address_must_be_complete(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);

        $this->book([$vehicle->vehicle_id], ['billing_address' => ['name' => 'Zentrale', 'street' => 'Hauptstr. 1']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['billing_address.zip_code', 'billing_address.city']);

        $this->assertSame(0, LeasybackOrder::count());
    }

    public function test_one_busy_vehicle_stops_the_whole_booking(): void
    {
        $free = $this->makeB2bVehicle($this->company);
        $busy = $this->makeB2bVehicle($this->company);
        $this->makeB2bOrder($busy, 'confirmed');

        $this->book([$free->vehicle_id, $busy->vehicle_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vehicle_ids');

        $this->assertSame(0, LeasybackOrder::where('service_type', 'ueberfuehrung')->count());
    }

    public function test_another_companys_vehicle_cannot_be_booked(): void
    {
        $foreign = $this->makeB2bVehicle($this->makeCompany('Andere GmbH'));

        $this->book([$foreign->vehicle_id])->assertNotFound();

        $this->assertSame(0, LeasybackOrder::count());
    }

    // ---------------------------------------------- phase 1: scheduling

    public function test_saving_date_and_time_window_schedules_the_relocation(): void
    {
        $order = $this->makeRelocationOrder($this->makeB2bVehicle($this->company), 'order_requested');

        $this->schedule($order)->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertSame('08:00-12:00', DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->value('confirmed_collection_time_slot'));
        Mail::assertQueued(RelocationScheduledMail::class);
        Mail::assertNotQueued(B2bCollectionScheduledMail::class);

        // Phase 1 is done, phase 2 is the next task.
        $this->assertSame(OrderTaskPriorityResolver::RELOCATION_PROTOCOL_TASK, $this->tasks($order)['next']['key']);
    }

    public function test_a_date_without_a_full_time_window_does_not_schedule(): void
    {
        $order = $this->makeRelocationOrder($this->makeB2bVehicle($this->company), 'order_requested');

        $this->adminPatch(route('admin.orders.collection', $order->id), [
            'confirmed_collection_date' => now()->addDays(3)->toDateString(),
        ])->assertSessionHasErrors('confirmed_time_from');

        $this->adminPatch(route('admin.orders.collection', $order->id), [
            'confirmed_collection_date' => now()->addDays(3)->toDateString(),
            'confirmed_time_from' => '08:00',
            'confirmed_time_to' => '09:00',
        ])->assertSessionHasErrors('confirmed_time_to');

        $this->assertSame('order_requested', $order->fresh()->order_status);
    }

    // ------------------------------------ phase 2: the transfer protocol

    public function test_a_transfer_protocol_link_completes_the_relocation(): void
    {
        $order = $this->scheduledOrder();

        $this->saveProtocol($order, ['url' => 'https://protokolle.example.test/K-RL-101'])->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->order_status);
        Mail::assertQueued(RelocationCompletedMail::class);

        $tasks = $this->tasks($order);
        $this->assertNull($tasks['next'], 'a completed relocation has no open task');
        $this->assertTrue($tasks['is_closed']);
        $this->assertSame('neutral', $tasks['priority']);
        $this->assertSame('link', app(AdminQueryService::class)->orderDetail($order->id)['collection']['transfer_protocol']['format']);
    }

    public function test_a_transfer_protocol_pdf_completes_the_relocation(): void
    {
        $order = $this->scheduledOrder();

        $this->saveProtocol($order, ['file' => UploadedFile::fake()->create('uebergabe.pdf', 120, 'application/pdf')])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->order_status);
        $path = DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->value('transfer_protocol_path');
        Storage::disk('documents')->assertExists($path);
    }

    public function test_saving_the_protocol_again_completes_nothing_twice(): void
    {
        $order = $this->scheduledOrder();

        $this->saveProtocol($order, ['url' => 'https://protokolle.example.test/1'])->assertSessionHasNoErrors();
        $this->saveProtocol($order, ['url' => 'https://protokolle.example.test/2'])->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('leasyback_order_status_updates')
            ->where('auftragsnummer', $order->auftragsnummer)
            ->where('new_status', 'completed')
            ->count());
    }

    public function test_the_protocol_cannot_be_saved_before_scheduling(): void
    {
        $order = $this->makeRelocationOrder($this->makeB2bVehicle($this->company), 'order_requested');

        $this->saveProtocol($order, ['url' => 'https://protokolle.example.test/1'])->assertSessionHasErrors('file');

        $this->assertSame('order_requested', $order->fresh()->order_status);
    }

    public function test_the_status_menu_cannot_complete_without_the_protocol(): void
    {
        $order = $this->scheduledOrder();

        $this->assertSame(['cancelled'], app(AdminQueryService::class)->orderDetail($order->id)['available_transitions']);

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'completed'])->assertSessionHasErrors();
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_a_relocation_never_enters_the_repair_process(): void
    {
        $order = $this->scheduledOrder();
        $this->meetB2bPrerequisite($order, 'inspected');

        try {
            app(TransitionOrderStatus::class)($order, 'inspected', 'admin', 'tester');
            $this->fail('A relocation must not move to inspected');
        } catch (ValidationException) {
            $this->assertSame('confirmed', $order->fresh()->order_status);
        }
    }

    // --------------------------------------------------- traffic lights

    public function test_phase_one_turns_yellow_at_24_hours_and_red_at_48_hours(): void
    {
        $receivedAt = CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/Berlin');
        $this->travelTo($receivedAt);
        $order = $this->makeRelocationOrder($this->makeB2bVehicle($this->company), 'order_requested');

        $this->assertSame(OrderTaskPriorityResolver::RELOCATION_APPOINTMENT_TASK, $this->tasks($order)['next']['key']);

        $this->travelTo($receivedAt->addHours(23)->addMinutes(59));
        $this->assertSame('green', $this->tasks($order)['priority']);

        $this->travelTo($receivedAt->addHours(24));
        $this->assertSame('yellow', $this->tasks($order)['priority']);

        $this->travelTo($receivedAt->addHours(48));
        $this->assertSame('red', $this->tasks($order)['priority']);
    }

    public function test_phase_two_counts_calendar_days_from_the_appointment_date(): void
    {
        // Monday pickup at 14:00: Monday green, Tuesday yellow, Wednesday 00:00 red.
        $order = $this->relocationScheduledFor('2026-10-05', '14:00-16:00');

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Europe/Berlin'));
        $this->assertSame('green', $this->tasks($order)['priority'], 'before the appointment date');

        $this->travelTo(CarbonImmutable::parse('2026-10-05 23:59:00', 'Europe/Berlin'));
        $this->assertSame('green', $this->tasks($order)['priority']);

        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:00:00', 'Europe/Berlin'));
        $this->assertSame('yellow', $this->tasks($order)['priority']);

        $this->travelTo(CarbonImmutable::parse('2026-10-06 23:59:00', 'Europe/Berlin'));
        $this->assertSame('yellow', $this->tasks($order)['priority']);

        $this->travelTo(CarbonImmutable::parse('2026-10-07 00:00:00', 'Europe/Berlin'));
        $this->assertSame('red', $this->tasks($order)['priority']);
    }

    public function test_the_daylight_saving_change_does_not_shift_midnight(): void
    {
        // Summer time ends in the night to Sunday, 25 October 2026.
        $order = $this->relocationScheduledFor('2026-10-24', '08:00-10:00');

        $this->travelTo(CarbonImmutable::parse('2026-10-25 00:00:00', 'Europe/Berlin'));
        $this->assertSame('yellow', $this->tasks($order)['priority']);

        $this->travelTo(CarbonImmutable::parse('2026-10-25 23:59:00', 'Europe/Berlin'));
        $this->assertSame('yellow', $this->tasks($order)['priority']);

        $this->travelTo(CarbonImmutable::parse('2026-10-26 00:00:00', 'Europe/Berlin'));
        $this->assertSame('red', $this->tasks($order)['priority']);
    }

    public function test_the_dashboard_task_list_shows_the_relocation_task_and_colour(): void
    {
        $receivedAt = CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/Berlin');
        $this->travelTo($receivedAt);
        $order = $this->makeRelocationOrder($this->makeB2bVehicle($this->company), 'order_requested');

        $this->travelTo($receivedAt->addHours(30));

        $row = collect(app(\App\Modules\UserProfile\Admin\Services\AdminTaskQueryService::class)->openTasks(50)['data'])
            ->firstWhere('order_id', $order->id);

        $this->assertNotNull($row, 'the relocation appears in the dashboard task list');
        $this->assertSame(OrderTaskPriorityResolver::RELOCATION_APPOINTMENT_TASK, $row['key']);
        $this->assertSame('yellow', $row['priority'], 'the list shows the same colour as the order page');
    }

    public function test_the_admin_order_detail_carries_the_relocation_booking(): void
    {
        $order = $this->makeRelocationOrder($this->makeB2bVehicle($this->company), 'order_requested');

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertSame('ueberfuehrung', $detail['service_type']);
        $this->assertSame('Köln', data_get($detail['request_payload'], 'pickup_address.city'));
    }

    // ------------------------------------------------ Leasingrückgabe kept

    public function test_the_leasing_return_path_is_unchanged(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_collected');

        $this->assertSame(['inspected', 'cancelled'], TransitionOrderStatus::allowedNextStatuses('vehicle_collected', true));
        $this->assertSame(['vehicle_collected', 'cancelled'], TransitionOrderStatus::allowedNextStatuses('confirmed', true));

        try {
            app(TransitionOrderStatus::class)($order, 'vehicle_returned', 'admin', 'tester');
            $this->fail('A leasing return must not skip the repair process');
        } catch (ValidationException) {
            $this->assertSame('vehicle_collected', $order->fresh()->order_status);
        }

        $detail = app(AdminQueryService::class)->orderDetail($order->id);
        $this->assertSame('leasingrueckgabe', $detail['service_type']);
        $this->assertNotSame(OrderTaskPriorityResolver::RELOCATION_APPOINTMENT_TASK, $detail['tasks']['next']['key'] ?? null);
        $this->assertSame('neutral', $detail['tasks']['priority'], 'B2B leasing return tasks stay untimed');
    }

    // ----------------------------------------------------------- helpers

    /**
     * @param  list<string>  $vehicleIds
     * @param  array<string, mixed>  $overrides
     */
    private function book(array $vehicleIds, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner)->postJson(route('orders.b2b.relocation.batch'), array_replace_recursive([
            'vehicle_ids' => $vehicleIds,
            'pickup_address' => ['street' => 'Domstr.', 'number' => '1', 'zip_code' => '50667', 'city' => 'Köln', 'country' => 'Deutschland'],
            'destination_address' => ['street' => 'Unter den Linden', 'number' => '5', 'zip_code' => '10117', 'city' => 'Berlin', 'country' => 'Deutschland'],
            'preferred_date' => now()->addDays(5)->toDateString(),
            'time_from' => '08:00',
            'time_to' => '12:00',
            'pickup_contact' => ['name' => 'Anna Abholung', 'phone' => '0221 123', 'email' => 'anna@example.test'],
            'destination_contact' => ['name' => 'Ben Ziel', 'phone' => '030 456', 'email' => 'ben@example.test'],
            'cost_centre' => ['name' => 'Vertrieb', 'number' => '4711'],
            'vehicle_ready' => true,
            'notes' => 'Schlüssel beim Empfang',
        ], $overrides));
    }

    private function makeRelocationOrder(Vehicle $vehicle, string $status): LeasybackOrder
    {
        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
            'leasyback_partner' => 'leasyback',
            'service_type' => 'ueberfuehrung',
            'request_payload' => [
                'order_type' => 'vehicle_relocation',
                'pickup_address' => ['street' => 'Domstr.', 'zip_code' => '50667', 'city' => 'Köln'],
                'destination_address' => ['street' => 'Unter den Linden', 'zip_code' => '10117', 'city' => 'Berlin'],
                'preferred_date' => now()->addDays(5)->toDateString(),
                'time_slot' => '08:00-12:00',
            ],
        ]);
    }

    private function schedule(LeasybackOrder $order): TestResponse
    {
        return $this->adminPatch(route('admin.orders.collection', $order->id), [
            'confirmed_collection_date' => now()->addDays(3)->toDateString(),
            'confirmed_time_from' => '08:00',
            'confirmed_time_to' => '12:00',
        ]);
    }

    private function scheduledOrder(): LeasybackOrder
    {
        $order = $this->makeRelocationOrder($this->makeB2bVehicle($this->company), 'order_requested');
        $this->schedule($order)->assertSessionHasNoErrors();

        return $order->fresh();
    }

    /** A scheduled relocation with a fixed appointment, independent of "today". */
    private function relocationScheduledFor(string $date, string $slot): LeasybackOrder
    {
        $order = $this->makeRelocationOrder($this->makeB2bVehicle($this->company), 'confirmed');
        OrderLogistics::updateOrCreate(['auftragsnummer' => $order->auftragsnummer], ['confirmed_collection_date' => $date]);
        DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->update(['confirmed_collection_time_slot' => $slot]);

        $this->assertSame(OrderTaskPriorityResolver::RELOCATION_PROTOCOL_TASK, $this->tasks($order)['next']['key']);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveProtocol(LeasybackOrder $order, array $data): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->post(route('admin.orders.transfer-protocol', $order->id), $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function tasks(LeasybackOrder $order): array
    {
        return app(AdminQueryService::class)->orderDetail($order->id)['tasks'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function adminPatch(string $url, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->patch($url, $data);
    }
}