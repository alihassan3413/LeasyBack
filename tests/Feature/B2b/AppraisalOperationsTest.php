<?php

namespace Tests\Feature\B2b;

use App\Enums\TaskPriority;
use App\Models\B2B;
use App\Models\User;
use App\Models\Vehicle as VehicleRecord;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAttachment;
use App\Modules\UserProfile\Order\Services\AccidentDamageAttachmentService;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use App\Modules\UserProfile\Order\Services\OrderTaskPriorityResolver;
use App\Modules\UserProfile\Order\Services\OrderTaskResolver;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Steps 3 and 4 of the Vehicle Condition Appraisal, at the level of the
 * services operations go through: scheduling by date and time window,
 * completion by the final report, what the customer is sent, and the two
 * timed tasks. The Admin pages themselves are covered once their routes exist.
 */
class AppraisalOperationsTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private B2B $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Storage::fake(OrderAttachment::DISK);

        $this->company = $this->makeCompany('Flotte GmbH');
        $this->owner = $this->makeOwner($this->company);
    }

    public function test_saving_date_and_time_window_schedules_the_appraisal(): void
    {
        $order = $this->bookAppraisal(2);

        $this->schedule($order, [
            'confirmed_collection_date' => now()->addDays(6)->toDateString(),
            'confirmed_time_from' => '09:00',
            'confirmed_time_to' => '12:00',
            'inspection_site_name' => 'DEKRA Köln',
            'inspection_site_address' => 'Prüfweg 1, 50667 Köln',
            'transport_confirmed' => true,
        ]);

        $this->assertSame('confirmed', $order->fresh()->order_status);

        $logistics = DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->first();
        $this->assertSame('09:00-12:00', $logistics->confirmed_collection_time_slot);
        $this->assertSame('DEKRA Köln', $logistics->inspection_site_name);
        $this->assertSame('Prüfweg 1, 50667 Köln', $logistics->inspection_site_address);
        $this->assertTrue((bool) $logistics->transport_confirmed);
    }

    public function test_a_date_without_a_full_time_window_does_not_schedule(): void
    {
        $order = $this->bookAppraisal(1);

        try {
            $this->schedule($order, ['confirmed_collection_date' => now()->addDays(6)->toDateString()]);
            $this->fail('A date without a time window was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmed_time_from', $e->errors());
        }

        try {
            $this->schedule($order, [
                'confirmed_collection_date' => now()->addDays(6)->toDateString(),
                'confirmed_time_from' => '10:00',
                'confirmed_time_to' => '11:00',
            ]);
            $this->fail('A window under two hours was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmed_time_to', $e->errors());
        }

        $this->assertSame('order_requested', $order->fresh()->order_status);
    }

    public function test_the_appraisal_cannot_be_completed_without_its_report(): void
    {
        $order = $this->scheduledAppraisal(1);

        $this->assertNotNull(TransitionOrderStatus::unmetB2bPrerequisite($order, 'completed'));

        $this->expectException(ValidationException::class);
        $this->complete($order);
    }

    public function test_the_report_completes_the_order_and_reaches_the_customer(): void
    {
        $order = $this->scheduledAppraisal(2);

        app(AccidentDamageAttachmentService::class)->storeFinalDocuments(
            $order,
            $this->owner,
            [UploadedFile::fake()->create('gutachten.pdf', 200, 'application/pdf')],
        );

        // Stored, but the order is still only scheduled: the customer sees every
        // vehicle of the order and no report yet.
        $this->actingAs($this->owner)
            ->get(route('orders.show', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.service_type', 'gutachten')
                ->has('order.vehicles', 2)
                ->has('order.attachments', 0));

        $this->assertNull(TransitionOrderStatus::unmetB2bPrerequisite($order, 'completed'));
        $this->complete($order);
        $this->assertSame('completed', $order->fresh()->order_status);

        $this->actingAs($this->owner)
            ->get(route('orders.show', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('order.attachments', 1)
                ->where('order.attachments.0.kind', 'final_document')
                ->where('order.attachments.0.original_name', 'gutachten.pdf'));
    }

    public function test_an_appraisal_never_enters_the_repair_process(): void
    {
        $order = $this->bookAppraisal(1);

        $this->assertTrue(TransitionOrderStatus::isAppraisalOrder($order));
        $this->assertTrue(TransitionOrderStatus::isShortPathOrder($order));
        $this->assertSame(['completed', 'cancelled'], TransitionOrderStatus::allowedNextStatuses('confirmed', true, true));
    }

    public function test_the_appointment_task_turns_yellow_at_24_hours_and_red_at_48_hours(): void
    {
        $now = new DateTimeImmutable('2026-10-10T12:00:00+00:00');

        $priority = fn (string $createdAt): TaskPriority => $this->priorityOf(
            ['order_status' => 'order_requested', 'created_at' => $createdAt],
            $now,
            OrderTaskPriorityResolver::APPRAISAL_APPOINTMENT_TASK,
        );

        $this->assertSame(TaskPriority::Green, $priority('2026-10-10T07:00:00+00:00'));
        $this->assertSame(TaskPriority::Yellow, $priority('2026-10-09T05:00:00+00:00'));
        $this->assertSame(TaskPriority::Red, $priority('2026-10-08T11:00:00+00:00'));
    }

    public function test_the_report_task_counts_calendar_days_from_the_appointment_date(): void
    {
        $now = new DateTimeImmutable('2026-10-10T10:00:00+02:00');

        $priority = fn (string $appointment): TaskPriority => $this->priorityOf(
            [
                'order_status' => 'confirmed',
                'created_at' => '2026-10-01T08:00:00+00:00',
                'collection' => ['confirmed_collection_date' => $appointment, 'confirmed_collection_time_slot' => '08:00-12:00'],
            ],
            $now,
            OrderTaskPriorityResolver::APPRAISAL_REPORT_TASK,
        );

        $this->assertSame(TaskPriority::Green, $priority('2026-10-10'));
        $this->assertSame(TaskPriority::Yellow, $priority('2026-10-09'));
        $this->assertSame(TaskPriority::Red, $priority('2026-10-08'));
    }

    // ------------------------------------------------------------ helpers

    private function bookAppraisal(int $vehicleCount): LeasybackOrder
    {
        $vehicles = [];

        for ($i = 0; $i < $vehicleCount; $i++) {
            $vehicles[] = $this->makeB2bVehicle($this->company);
        }

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

        return LeasybackOrder::where('service_type', 'gutachten')->latest('created_at')->firstOrFail();
    }

    private function scheduledAppraisal(int $vehicleCount): LeasybackOrder
    {
        $order = $this->bookAppraisal($vehicleCount);

        $this->schedule($order, [
            'confirmed_collection_date' => now()->addDays(6)->toDateString(),
            'confirmed_time_from' => '08:00',
            'confirmed_time_to' => '12:00',
        ]);

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function schedule(LeasybackOrder $order, array $data): void
    {
        app(OrderCollectionService::class)->updateByAdmin(
            $order,
            VehicleRecord::where('vehicle_id', $order->vehicle_id)->firstOrFail(),
            $this->owner,
            $data,
        );
    }

    private function complete(LeasybackOrder $order): void
    {
        app(TransitionOrderStatus::class)($order, 'completed', 'admin', 'Test', $this->owner->id);
    }

    /**
     * The colour of the order's next Admin task, after checking it is the expected one.
     *
     * @param  array<string, mixed>  $order
     */
    private function priorityOf(array $order, DateTimeImmutable $now, string $expectedTask): TaskPriority
    {
        $tasks = app(OrderTaskResolver::class)->forOrderDetail([
            'id' => 'order-1',
            'vehicle_belongs' => 'B2B',
            'service_type' => 'gutachten',
            'status_updates' => [],
            ...$order,
        ]);

        $this->assertSame($expectedTask, $tasks['next']['key'] ?? null);

        return app(OrderTaskPriorityResolver::class)->forOrderTasks($tasks, true, $now);
    }
}
