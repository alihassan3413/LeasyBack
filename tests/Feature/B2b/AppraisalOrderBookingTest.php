<?php

namespace Tests\Feature\B2b;

use App\Mail\Orders\AppraisalRequestedMail;
use App\Mail\Orders\B2bCollectionRequestedMail;
use App\Models\B2B;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\CompanyBillingAddress;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderVehicle;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Step 2 of the Vehicle Condition Appraisal: the order form, against the brief
 * of 17 September 2026 — one order for every selected vehicle, its validation
 * rules, the confirmation page and the confirmation email.
 */
class AppraisalOrderBookingTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private B2B $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->company = $this->makeCompany('Flotte GmbH');
        $this->owner = $this->makeOwner($this->company);
    }

    public function test_one_order_is_created_for_every_selected_vehicle(): void
    {
        $vehicles = [$this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company)];

        $response = $this->book($vehicles, [], inertia: true);

        $order = LeasybackOrder::where('service_type', 'gutachten')->sole();
        $response->assertRedirect(route('orders.confirmation', $order->id));

        $this->assertSame('order_requested', $order->order_status);
        $this->assertNotEmpty($order->auftragsnummer);
        $this->assertSame($vehicles[0]->vehicle_id, $order->vehicle_id);
        $this->assertSame('vehicle_appraisal', data_get($order->request_payload, 'order_type'));
        $this->assertSame('VW Leasing', data_get($order->request_payload, 'leasing_company'));
        $this->assertSame('08:00-12:00', data_get($order->request_payload, 'time_slot'));
        $this->assertCount(3, data_get($order->request_payload, 'vehicles'));

        $this->assertSame(
            array_map(fn (Vehicle $vehicle) => $vehicle->vehicle_id, $vehicles),
            OrderVehicle::where('order_id', $order->id)->orderBy('position')->pluck('vehicle_id')->all(),
        );

        foreach ($vehicles as $vehicle) {
            $this->assertTrue(app(VehicleService::class)->blocksNewOrder($vehicle->vehicle_id));
        }

        Mail::assertQueued(AppraisalRequestedMail::class);
        Mail::assertNotQueued(B2bCollectionRequestedMail::class);
    }

    public function test_the_confirmation_page_lists_every_vehicle(): void
    {
        $vehicles = [$this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company)];
        $this->book($vehicles, [], inertia: true);
        $order = LeasybackOrder::where('service_type', 'gutachten')->sole();

        $this->actingAs($this->owner)
            ->get(route('orders.confirmation', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('b2b/OrderConfirmation')
                ->where('order.auftragsnummer', $order->auftragsnummer)
                ->has('vehicles', 2));
    }

    public function test_at_least_one_vehicle_is_required(): void
    {
        $this->actingAs($this->owner)->from('/dashboard')
            ->post(route('orders.b2b.appraisal.store'), [...$this->validData(), 'vehicle_ids' => []])
            ->assertSessionHasErrors('vehicle_ids');

        $this->assertSame(0, LeasybackOrder::count());
    }

    public function test_billing_location_and_leasing_company_are_mandatory(): void
    {
        $this->book([$this->makeB2bVehicle($this->company)], [
            'billing_address' => ['name' => '', 'street' => '', 'zip_code' => '', 'city' => '', 'country' => ''],
            'vehicle_location' => ['street' => '', 'zip_code' => '', 'city' => ''],
            'leasing_company' => '',
        ])->assertSessionHasErrors([
            'billing_address.name', 'billing_address.street', 'billing_address.zip_code', 'billing_address.city', 'billing_address.country',
            'vehicle_location.street', 'vehicle_location.zip_code', 'vehicle_location.city',
            'leasing_company',
        ]);

        $this->assertSame(0, LeasybackOrder::count());
    }

    public function test_the_preferred_date_cannot_be_in_the_past(): void
    {
        $this->book([$this->makeB2bVehicle($this->company)], ['preferred_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('preferred_date');
    }

    public function test_the_time_window_must_be_at_least_two_hours(): void
    {
        $this->book([$this->makeB2bVehicle($this->company)], ['time_from' => '10:00', 'time_to' => '11:30'])
            ->assertSessionHasErrors('time_to');

        $this->book([$this->makeB2bVehicle($this->company)], ['time_from' => '12:00', 'time_to' => '10:00'])
            ->assertSessionHasErrors('time_to');

        $this->assertSame(0, LeasybackOrder::count());
    }

    public function test_return_transport_needs_pickup(): void
    {
        $this->book([$this->makeB2bVehicle($this->company)], ['pickup_requested' => false, 'return_transport' => true])
            ->assertSessionHasErrors('return_transport');

        $this->book([$this->makeB2bVehicle($this->company)], ['pickup_requested' => true, 'return_transport' => true])
            ->assertSessionHasNoErrors();

        $order = LeasybackOrder::sole();
        $this->assertTrue(data_get($order->request_payload, 'pickup_requested'));
        $this->assertTrue(data_get($order->request_payload, 'return_transport'));
    }

    public function test_entered_contact_details_must_be_valid(): void
    {
        $this->book([$this->makeB2bVehicle($this->company)], ['location_contact' => ['name' => 'Max', 'phone' => 'abc', 'email' => 'nicht-gültig']])
            ->assertSessionHasErrors(['location_contact.phone', 'location_contact.email']);
    }

    public function test_a_busy_vehicle_stops_the_whole_order(): void
    {
        $free = $this->makeB2bVehicle($this->company);
        $busy = $this->makeB2bVehicle($this->company);
        $this->makeB2bOrder($busy, 'confirmed');

        $this->book([$free, $busy])->assertSessionHasErrors('vehicle_ids');

        $this->assertSame(0, LeasybackOrder::where('service_type', 'gutachten')->count());
        $this->assertSame(0, OrderVehicle::count());
    }

    public function test_submitting_twice_creates_only_one_order(): void
    {
        $vehicles = [$this->makeB2bVehicle($this->company), $this->makeB2bVehicle($this->company)];

        $this->book($vehicles)->assertSessionHasNoErrors();
        $this->book($vehicles)->assertSessionHasErrors('vehicle_ids');

        $this->assertSame(1, LeasybackOrder::where('service_type', 'gutachten')->count());
    }

    public function test_another_companys_vehicle_cannot_be_booked(): void
    {
        $foreign = $this->makeB2bVehicle($this->makeCompany('Andere GmbH'));

        $this->book([$foreign])->assertNotFound();
        $this->assertSame(0, LeasybackOrder::count());
    }

    public function test_a_billing_address_is_saved_only_when_asked(): void
    {
        $this->book([$this->makeB2bVehicle($this->company)])->assertSessionHasNoErrors();
        $this->assertSame(0, CompanyBillingAddress::count());

        $this->book([$this->makeB2bVehicle($this->company)], ['save_billing_address' => true, 'billing_address_default' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue(CompanyBillingAddress::sole()->is_default);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function validData(): array
    {
        return [
            'billing_address' => ['name' => 'Zentrale', 'street' => 'Hauptstr.', 'number' => '1', 'zip_code' => '50667', 'city' => 'Köln', 'country' => 'Deutschland'],
            'vehicle_location' => ['street' => 'Domstr.', 'number' => '3', 'zip_code' => '50667', 'city' => 'Köln'],
            'pickup_requested' => false,
            'return_transport' => false,
            'leasing_company' => 'VW Leasing',
            'preferred_date' => now()->addDays(5)->toDateString(),
            'time_from' => '08:00',
            'time_to' => '12:00',
            'location_contact' => ['name' => 'Anna Standort', 'phone' => '+49 221 1234', 'email' => 'anna@example.test'],
            'notes' => 'Schlüssel beim Empfang',
        ];
    }

    /**
     * @param  list<Vehicle>  $vehicles
     * @param  array<string, mixed>  $overrides
     */
    private function book(array $vehicles, array $overrides = [], bool $inertia = false): TestResponse
    {
        $request = $this->actingAs($this->owner)->from('/dashboard');

        if ($inertia) {
            $request = $request->withHeaders(['X-Inertia' => 'true']);
        }

        $response = $request->post(route('orders.b2b.appraisal.store'), array_replace($this->validData(), $overrides, [
            'vehicle_ids' => array_map(fn (Vehicle $vehicle) => $vehicle->vehicle_id, $vehicles),
        ]));

        // withHeaders() sticks to later requests; a GET with X-Inertia but no
        // asset version is answered 409 by the Inertia middleware.
        $this->flushHeaders();

        return $response;
    }
}
