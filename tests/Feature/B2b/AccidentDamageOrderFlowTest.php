<?php

namespace Tests\Feature\B2b;

use App\Mail\Orders\AccidentDamageCompletedMail;
use App\Mail\Orders\AccidentDamageRequestedMail;
use App\Mail\Orders\AccidentDamageScheduledMail;
use App\Mail\Orders\B2bCollectionRequestedMail;
use App\Models\B2B;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\CompanyBillingAddress;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAttachment;
use App\Modules\UserProfile\Order\Services\AccidentDamageAttachmentService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
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
 * The Unfallschaden (Accident Damage) service, against its brief of
 * 17 September 2026: the report form for one vehicle, its validation, the
 * uploads, REQUESTED → SCHEDULED → COMPLETED, the final documentation, the
 * statistics view — and that the Leasingrückgabe stays as it was.
 */
class AccidentDamageOrderFlowTest extends TestCase
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

    // ------------------------------------------------------------- booking

    public function test_a_report_creates_one_requested_order_with_its_files(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);

        $response = $this->report($vehicle, [
            'files' => [
                UploadedFile::fake()->create('unfallbericht.pdf', 300, 'application/pdf'),
                UploadedFile::fake()->image('schaden.jpg'),
            ],
        ], inertia: true);

        $order = LeasybackOrder::where('service_type', 'unfallschaden')->sole();
        $response->assertRedirect(route('orders.confirmation', $order->id));

        $this->assertSame('order_requested', $order->order_status);
        $this->assertNotEmpty($order->auftragsnummer);
        $this->assertSame('accident_damage', data_get($order->request_payload, 'order_type'));
        $this->assertSame('Köln', data_get($order->request_payload, 'vehicle_location.city'));
        $this->assertSame('Zentrale', data_get($order->request_payload, 'billing_address.name'));
        $this->assertNull(data_get($order->request_payload, 'return_address'), 'no return address without the checkbox');
        $this->assertArrayNotHasKey('save_billing_address', (array) $order->request_payload);

        $files = OrderAttachment::where('order_id', $order->id)->get();
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertSame(OrderAttachment::KIND_CUSTOMER_UPLOAD, $file->kind);
            Storage::disk('documents')->assertExists($file->path);
        }

        Mail::assertQueued(AccidentDamageRequestedMail::class);
        Mail::assertNotQueued(B2bCollectionRequestedMail::class);
    }

    public function test_the_confirmation_page_shows_the_new_order(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);
        $this->report($vehicle, inertia: true);
        $order = LeasybackOrder::where('service_type', 'unfallschaden')->sole();

        $this->actingAs($this->owner)
            ->get(route('orders.confirmation', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('b2b/OrderConfirmation')
                ->where('order.auftragsnummer', $order->auftragsnummer)
                ->where('order.order_status', 'order_requested'));
    }

    public function test_billing_information_is_mandatory(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);

        $this->report($vehicle, ['billing_address' => ['name' => '', 'street' => '', 'zip_code' => '', 'city' => '', 'country' => '']])
            ->assertSessionHasErrors(['billing_address.name', 'billing_address.street', 'billing_address.zip_code', 'billing_address.city', 'billing_address.country']);

        $this->assertSame(0, LeasybackOrder::count());
    }

    public function test_the_vehicle_location_is_mandatory(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);

        $this->report($vehicle, ['vehicle_location' => ['street' => '', 'zip_code' => '', 'city' => '']])
            ->assertSessionHasErrors(['vehicle_location.street', 'vehicle_location.zip_code', 'vehicle_location.city']);
    }

    public function test_a_different_return_location_makes_its_address_mandatory(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);

        $this->report($vehicle, ['return_differs' => true])
            ->assertSessionHasErrors(['return_address.street', 'return_address.zip_code', 'return_address.city']);

        $this->report($vehicle, [
            'return_differs' => true,
            'return_address' => ['street' => 'Hafenstr. 2', 'zip_code' => '20457', 'city' => 'Hamburg'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Hamburg', data_get(LeasybackOrder::sole()->request_payload, 'return_address.city'));
    }

    public function test_entered_contact_details_must_be_valid(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);

        $this->report($vehicle, ['location_contact' => ['name' => 'Max', 'phone' => 'keine Nummer', 'email' => 'nicht-gültig']])
            ->assertSessionHasErrors(['location_contact.phone', 'location_contact.email']);
    }

    public function test_a_file_over_20_mb_is_rejected(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);

        $this->report($vehicle, ['files' => [UploadedFile::fake()->create('zu-gross.pdf', 20481, 'application/pdf')]])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, LeasybackOrder::count());
    }

    public function test_a_second_submission_for_the_same_vehicle_is_refused(): void
    {
        $vehicle = $this->makeB2bVehicle($this->company);

        $this->report($vehicle)->assertSessionHasNoErrors();
        $this->report($vehicle)->assertSessionHasErrors('vehicle_id');

        $this->assertSame(1, LeasybackOrder::count());
    }

    public function test_a_billing_address_is_only_saved_when_asked_and_can_become_the_default(): void
    {
        $this->report($this->makeB2bVehicle($this->company))->assertSessionHasNoErrors();
        $this->assertSame(0, CompanyBillingAddress::count(), 'nothing is saved without the checkbox');

        $this->report($this->makeB2bVehicle($this->company), ['save_billing_address' => true, 'billing_address_default' => true])
            ->assertSessionHasNoErrors();

        $saved = CompanyBillingAddress::sole();
        $this->assertSame('Zentrale', $saved->name);
        $this->assertTrue($saved->is_default);
    }

    // ------------------------------------------------------- operations

    public function test_scheduling_needs_what_was_arranged_and_the_date(): void
    {
        $order = $this->accidentOrder('order_requested');

        $this->adminPatch(route('admin.orders.collection', $order->id), [
            'confirmed_collection_date' => now()->addDays(2)->toDateString(),
        ])->assertSessionHasErrors('confirmed_arrangement');
        $this->assertSame('order_requested', $order->fresh()->order_status);

        $this->schedule($order)->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertSame('inspection', DB::table('leasyback_order_logistics')->where('auftragsnummer', $order->auftragsnummer)->value('confirmed_arrangement'));
        Mail::assertQueued(AccidentDamageScheduledMail::class);
    }

    public function test_operations_complete_the_order_without_a_billing_step(): void
    {
        $order = $this->accidentOrder('order_requested');
        $this->schedule($order)->assertSessionHasNoErrors();

        $this->assertSame(['completed', 'cancelled'], app(AdminQueryService::class)->orderDetail($order->id)['available_transitions']);

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'completed'])->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->order_status);
        Mail::assertQueued(AccidentDamageCompletedMail::class);
        $this->assertTrue(app(AdminQueryService::class)->orderDetail($order->id)['tasks']['is_closed']);
    }

    public function test_an_accident_damage_order_never_enters_the_repair_process(): void
    {
        $order = $this->accidentOrder('order_requested');
        $this->schedule($order);

        $this->assertNotContains('vehicle_collected', TransitionOrderStatus::allowedNextStatuses('confirmed', true, true));
        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'vehicle_collected'])->assertSessionHasErrors();
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_final_documents_reach_the_customer_once_the_order_is_completed(): void
    {
        $order = $this->accidentOrder('order_requested');
        $this->schedule($order);

        $this->actingAs($this->admin)->from('/admin/dashboard')
            ->post(route('admin.orders.accident-documents.store', $order->id), [
                'files' => [UploadedFile::fake()->create('gutachten.pdf', 500, 'application/pdf')],
            ])->assertSessionHasNoErrors();

        $service = app(AccidentDamageAttachmentService::class);

        $this->assertCount(1, $service->forOrders([$order->id], [$order->id => 'confirmed'], forOperations: true)[$order->id]);
        $this->assertArrayNotHasKey($order->id, $service->forOrders([$order->id], [$order->id => 'confirmed']), 'customer does not see it yet');

        $this->assertCount(1, $service->forOrders([$order->id], [$order->id => 'completed'])[$order->id], 'customer sees it once completed');
    }

    public function test_the_admin_tasks_stay_green(): void
    {
        $order = $this->accidentOrder('order_requested');
        $this->travel(5)->days();

        $tasks = app(AdminQueryService::class)->orderDetail($order->id)['tasks'];
        $this->assertSame('accident_schedule_next_step', $tasks['next']['key']);
        $this->assertSame('green', $tasks['priority'], 'no time-based escalation for Accident Damage');

        $this->schedule($order);
        $this->travel(10)->days();

        $tasks = app(AdminQueryService::class)->orderDetail($order->id)['tasks'];
        $this->assertSame('accident_complete', $tasks['next']['key']);
        $this->assertSame('green', $tasks['priority']);
    }

    // -------------------------------------------------------- statistics

    public function test_the_statistics_count_the_three_statuses_and_filter(): void
    {
        $this->accidentOrder('order_requested');
        $scheduled = $this->accidentOrder('order_requested');
        $this->schedule($scheduled);
        $done = $this->accidentOrder('order_requested');
        $this->schedule($done);
        $this->adminPatch(route('admin.orders.status', $done->id), ['status' => 'completed']);
        // A Leasingrückgabe is not counted.
        $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'confirmed');

        $this->actingAs($this->admin)->get(route('admin.statistics.accident-damage'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Statistics/AccidentDamage')
                ->where('totals.total', 3)
                ->where('totals.requested', 1)
                ->where('totals.scheduled', 1)
                ->where('totals.completed', 1));

        $this->actingAs($this->admin)->get(route('admin.statistics.accident-damage', ['status' => 'completed']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 1)->where('totals.completed', 1));

        $plate = Vehicle::where('vehicle_id', $scheduled->vehicle_id)->value('license_plate');
        $this->actingAs($this->admin)->get(route('admin.statistics.accident-damage', ['vehicle' => $plate]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totals.total', 1)->where('totals.scheduled', 1));
    }

    // -------------------------------------------------- Leasingrückgabe kept

    public function test_the_leasing_return_path_is_unchanged(): void
    {
        $this->assertSame(['vehicle_collected', 'cancelled'], TransitionOrderStatus::allowedNextStatuses('confirmed', true));
        $this->assertSame(['inspected', 'cancelled'], TransitionOrderStatus::allowedNextStatuses('vehicle_collected', true));

        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_collected');
        $this->assertSame('neutral', app(AdminQueryService::class)->orderDetail($order->id)['tasks']['priority']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function report(Vehicle $vehicle, array $overrides = [], bool $inertia = false): TestResponse
    {
        $request = $this->actingAs($this->owner)->from('/dashboard');

        if ($inertia) {
            $request = $request->withHeaders(['X-Inertia' => 'true']);
        }

               $response = $request->post(route('orders.b2b.accident-damage.store'), array_replace([
            'vehicle_id' => $vehicle->vehicle_id,
            'billing_address' => ['name' => 'Zentrale', 'street' => 'Hauptstr.', 'number' => '1', 'zip_code' => '50667', 'city' => 'Köln', 'country' => 'Deutschland'],
            'vehicle_location' => ['street' => 'Domstr.', 'number' => '3', 'zip_code' => '50667', 'city' => 'Köln'],
            'location_contact' => ['name' => 'Anna Standort', 'phone' => '+49 221 1234', 'email' => 'anna@example.test'],
            'return_differs' => false,
            'notes' => 'Frontschaden links',
        ], $overrides));

        // withHeaders() sticks to every later request in the test. A GET that
        // still carries X-Inertia without the asset version is answered 409
        // by the Inertia middleware, so the header is cleared again here.
        $this->flushHeaders();

        return $response;
    }

    private function accidentOrder(string $status): LeasybackOrder
    {
        return LeasybackOrder::factory()->create([
            'vehicle_id' => $this->makeB2bVehicle($this->company)->vehicle_id,
            'order_status' => $status,
            'leasyback_partner' => 'leasyback',
            'service_type' => 'unfallschaden',
            'request_payload' => [
                'order_type' => 'accident_damage',
                'billing_address' => ['name' => 'Zentrale', 'street' => 'Hauptstr.', 'zip_code' => '50667', 'city' => 'Köln', 'country' => 'Deutschland'],
                'vehicle_location' => ['street' => 'Domstr.', 'zip_code' => '50667', 'city' => 'Köln'],
                'return_differs' => false,
            ],
        ]);
    }

    private function schedule(LeasybackOrder $order): TestResponse
    {
        return $this->adminPatch(route('admin.orders.collection', $order->id), [
            'confirmed_collection_date' => now()->addDays(2)->toDateString(),
            'confirmed_arrangement' => 'inspection',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function adminPatch(string $url, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->patch($url, $data);
    }
}