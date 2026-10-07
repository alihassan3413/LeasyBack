<?php

namespace Tests\Feature\B2b;

use App\Enums\B2bRolePreset;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Booking a B2B service is "creating an order" — `orders.create` — whichever
 * service it is and whichever URL the form posts to. A member who may only
 * look (Read-only) must be refused by the server, not just shown no button.
 */
class B2bServiceOrderAuthorizationTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
    }

    /** @return array<string, mixed> */
    private function relocationDetails(): array
    {
        return [
            'pickup_address' => ['street' => 'Invalidenstraße', 'number' => '5', 'zip_code' => '10115', 'city' => 'Berlin', 'country' => 'Deutschland'],
            'destination_address' => ['street' => 'Marienplatz', 'number' => '1', 'zip_code' => '80331', 'city' => 'München', 'country' => 'Deutschland'],
            'preferred_date' => now()->addWeek()->toDateString(),
            'time_from' => '08:00',
            'time_to' => '10:00',
        ];
    }

    /** @return array<string, mixed> */
    private function appraisalDetails(): array
    {
        return [
            'billing_address' => ['name' => 'Zentrale', 'street' => 'Friedrichstraße', 'number' => '12', 'zip_code' => '10117', 'city' => 'Berlin', 'country' => 'Deutschland'],
            'vehicle_location' => ['street' => 'Invalidenstraße', 'number' => '5', 'zip_code' => '10115', 'city' => 'Berlin'],
            'leasing_company' => 'VW Leasing',
            'preferred_date' => now()->addWeek()->toDateString(),
            'time_from' => '08:00',
            'time_to' => '10:00',
        ];
    }

    /**
     * Every bookable service, as the dashboard's forms post it.
     *
     * @return array<string, array{0: callable(self, string): TestResponse}>
     */
    public static function services(): array
    {
        return [
            'Leasingrückgabe' => [fn (self $t, string $vehicleId) => $t->post(route('orders.store', $vehicleId), [
                'requested_collection_date' => now()->addWeek()->toDateString(),
                'requested_collection_time_slot' => '10:00-12:00',
                'collection_address' => ['street' => 'Invalidenstraße', 'zip_code' => '10115', 'city' => 'Berlin'],
            ])],
            'Überführung (portal form)' => [fn (self $t, string $vehicleId) => $t->post(route('orders.b2b.relocation.batch'), ['vehicle_ids' => [$vehicleId], ...$t->relocationDetails()])],
            'Überführung (single-vehicle URL)' => [fn (self $t, string $vehicleId) => $t->post(route('orders.b2b.relocation.store', $vehicleId), $t->relocationDetails())],
            'Gutachten' => [fn (self $t, string $vehicleId) => $t->post(route('orders.b2b.appraisal.store'), ['vehicle_ids' => [$vehicleId], ...$t->appraisalDetails()])],
            'Unfallschaden' => [fn (self $t, string $vehicleId) => $t->post(route('orders.b2b.accident-damage.store'), [
                'vehicle_id' => $vehicleId,
                'billing_address' => $t->appraisalDetails()['billing_address'],
                'vehicle_location' => $t->appraisalDetails()['vehicle_location'],
            ])],
        ];
    }

    /** @dataProvider services */
    public function test_a_read_only_member_cannot_book_the_service(callable $book): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);
        $readOnly = $this->makeMember($company, B2bRolePreset::ReadOnly->permissions()->toArray());

        $this->actingAs($readOnly);
        $book($this, $vehicle->vehicle_id)->assertForbidden();

        $this->assertSame(0, LeasybackOrder::count(), 'no order may exist after a refused booking');
    }

    /** @dataProvider services */
    public function test_a_standard_user_can_book_the_service(callable $book): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);
        $standard = $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray());

        $this->actingAs($standard);
        $book($this, $vehicle->vehicle_id)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertGreaterThanOrEqual(1, LeasybackOrder::count());
    }

    /** @dataProvider services */
    public function test_another_companys_vehicle_cannot_be_booked(callable $book): void
    {
        $foreignVehicle = $this->makeB2bVehicle($this->makeCompany('Fremd GmbH'));
        $owner = $this->makeOwner($this->makeCompany('Eigen GmbH'));

        $this->actingAs($owner);
        $response = $book($this, $foreignVehicle->vehicle_id);

        $this->assertContains($response->status(), [403, 404], 'refused, not booked and not a server error');
        $this->assertSame(0, LeasybackOrder::count());
    }

    // ── Acting on an existing company order ───────────────────────────

    public function test_a_read_only_member_cannot_cancel_or_write_on_a_company_order(): void
    {
        $company = $this->makeCompany();
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company));
        $readOnly = $this->makeMember($company, B2bRolePreset::ReadOnly->permissions()->toArray());

        $this->actingAs($readOnly);

        // The cancellation route answers 404 to every refusal, B2B included (OrderCancellationController).
        $this->post(route('orders.cancel', $order->id), ['reason' => 'Test'])->assertNotFound();
        $this->postJson(route('orders.messages.store', $order->id), ['body' => 'Hallo'])->assertForbidden();
        $this->getJson(route('orders.messages.index', $order->id))->assertOk()->assertJsonPath('can_send', false);

        $this->assertNotSame('cancelled', $order->fresh()->order_status);
    }

    public function test_a_standard_user_can_write_on_a_company_order(): void
    {
        $company = $this->makeCompany();
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company));
        $standard = $this->makeMember($company, B2bRolePreset::StandardUser->permissions()->toArray());

        $this->actingAs($standard)->postJson(route('orders.messages.store', $order->id), ['body' => 'Abholung bitte vormittags'])->assertSuccessful();
    }

    public function test_another_companys_order_is_not_reachable(): void
    {
        $foreignOrder = $this->makeB2bOrder($this->makeB2bVehicle($this->makeCompany('Fremd GmbH')));
        $owner = $this->makeOwner($this->makeCompany('Eigen GmbH'));

        $this->actingAs($owner);

        foreach ([
            $this->get(route('orders.show', $foreignOrder->id)),
            $this->getJson(route('orders.messages.index', $foreignOrder->id)),
            $this->postJson(route('orders.messages.store', $foreignOrder->id), ['body' => 'x']),
            $this->post(route('orders.cancel', $foreignOrder->id), ['reason' => 'x']),
        ] as $response) {
            $this->assertContains($response->status(), [403, 404], 'refused, not served and not a server error');
        }

        $this->assertNotSame('cancelled', $foreignOrder->fresh()->order_status);
    }

    /** A malformed or unknown id is a 404, never a 500. */
    public function test_malformed_and_unknown_order_ids_are_not_found(): void
    {
        $this->actingAs($this->makeOwner($this->makeCompany()));

        $this->get('/orders/not-a-uuid')->assertNotFound();
        $this->get('/orders/'.Str::uuid())->assertNotFound();
        $this->get('/fahrzeuge/not-a-uuid')->assertNotFound();
    }
}
