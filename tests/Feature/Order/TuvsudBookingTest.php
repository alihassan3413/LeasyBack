<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Releasing a requested B2C order books it with TÜV SÜD
 * (OrderService::approveOrder). The order may only advance when the booking
 * was accepted, and the TÜV SÜD credentials must never be persisted into the
 * customer-visible `request_payload`.
 *
 * TÜV SÜD is always faked here: phpunit.xml points `services.tuvsud.*` at a
 * reserved `.test` host with dummy credentials, and TestCase blocks every
 * request that is not faked.
 */
class TuvsudBookingTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private const URL = 'https://tuvsud.test/api/rest/auftraege/beauftragung';

    private const USERNAME = 'tuv-user-secret';

    private const TOKEN = 'tuv-token-secret-0123456789';

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        config([
            'services.tuvsud.url' => self::URL,
            'services.tuvsud.username' => self::USERNAME,
            'services.tuvsud.token' => self::TOKEN,
        ]);

        $this->admin = $this->makeAdmin();
        $this->owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
    }

    // ------------------------------------------------------------- success

    public function test_a_successful_booking_places_the_order_without_storing_credentials(): void
    {
        Http::fake([self::URL => Http::response(['auftragsnummer' => 'TUV-4711'], 201)]);
        $order = $this->requestedOrder();

        $this->approveViaAdmin($order)->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('order_placed', $order->order_status);
        $this->assertNotNull($order->sent_at);
        $this->assertSame(201, $order->response_status);
        $this->assertSame(['auftragsnummer' => 'TUV-4711'], $order->response_body);

        // The outgoing request carried the credentials…
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request['authentifizierung'] === ['benutzername' => self::USERNAME, 'token' => self::TOKEN]
            && $request['besichtigungsort']['name'] === 'TÜV SÜD Köln');

        // …the stored payload does not, and keeps what the app reads.
        $this->assertArrayNotHasKey('authentifizierung', $order->request_payload);
        $this->assertSame('TÜV SÜD Köln', $order->request_payload['besichtigungsort']['name']);
        $this->assertSame('Bitte Schlüssel mitbringen', $order->request_payload['auftrag']['bemerkung']);
        $this->assertCredentialsAbsentFromDatabase($order);

        $this->assertDatabaseHas('leasyback_order_audit_log', ['order_id' => $order->id, 'action' => 'APPROVE_ORDER']);
    }

    public function test_the_api_approval_keeps_its_success_response_and_stores_no_credentials(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
        $order = $this->requestedOrder();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
            ->postJson("/api/order/tuvsud/order/approve/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order_status', 'order_placed')
            ->assertJsonPath('tuvsud_status', 200);

        $this->assertCredentialsAbsentFromDatabase($order->fresh());
    }

    // ------------------------------------------------------------- failure

    public function test_an_http_failure_leaves_the_order_unchanged(): void
    {
        Http::fake([self::URL => Http::response('Service Unavailable', 503)]);
        $order = $this->requestedOrder();

        $this->approveViaAdmin($order)->assertSessionHasErrors('status');

        $this->assertOrderUnchanged($order);
        $this->assertFailureAudited($order, 503, null);
    }

    public function test_an_unsuccessful_booking_response_leaves_the_order_unchanged(): void
    {
        Http::fake([self::URL => Http::response(['fehler' => 'Termin nicht verfügbar'], 422)]);
        $order = $this->requestedOrder();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
            ->postJson("/api/order/tuvsud/order/approve/{$order->id}")
            ->assertStatus(502)
            ->assertJsonPath('error', 'TÜV SÜD hat die Buchung nicht angenommen (HTTP 422). Der Auftrag wurde nicht gebucht und bleibt angefragt.');

        $this->assertOrderUnchanged($order);
        $this->assertFailureAudited($order, 422, null);
    }

    public function test_a_connection_error_leaves_the_order_unchanged(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $order = $this->requestedOrder();

        $this->approveViaAdmin($order)->assertSessionHasErrors('status');

        $this->assertOrderUnchanged($order);
        $this->assertFailureAudited($order, null, ConnectionException::class);
    }

    public function test_an_unexpected_booking_exception_leaves_the_order_unchanged(): void
    {
        Http::fake(fn () => throw new RuntimeException('unexpected client failure'));
        $order = $this->requestedOrder();

        $this->approveViaAdmin($order)->assertSessionHasErrors('status');

        $this->assertOrderUnchanged($order);
        $this->assertFailureAudited($order, null, RuntimeException::class);
    }

    // ---------------------------------------------------- retry & duplicates

    public function test_a_failed_booking_can_be_retried_and_a_placed_order_is_never_booked_twice(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push('Service Unavailable', 503)
            ->push(['ok' => true], 200)]);
        $order = $this->requestedOrder();

        $this->approveViaAdmin($order)->assertSessionHasErrors('status');
        $this->assertSame('order_requested', $order->fresh()->order_status);

        $this->approveViaAdmin($order)->assertSessionHasNoErrors();
        $this->assertSame('order_placed', $order->fresh()->order_status);

        // A repeated click or a stale tab: refused before any request is sent.
        $this->approveViaAdmin($order)->assertSessionHasErrors('status');

        Http::assertSentCount(2);
        $this->assertSame(1, DB::table('leasyback_order_status_updates')->where('auftragsnummer', $order->auftragsnummer)->count());
    }

    public function test_a_booking_already_in_progress_is_not_sent_again(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
        $order = $this->requestedOrder();

        $lock = Cache::lock('tuvsud-booking:'.$order->id, 60);
        $this->assertTrue($lock->get());

        $this->approveViaAdmin($order)->assertSessionHasErrors('status');

        Http::assertNothingSent();
        $this->assertSame('order_requested', $order->fresh()->order_status);

        $lock->release();
    }

    // ------------------------------------------------ credentials exposure

    public function test_credentials_are_never_exposed_to_the_customer(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
        $order = $this->requestedOrder();
        $this->approveViaAdmin($order)->assertSessionHasNoErrors();

        $webPage = $this->actingAs($this->owner)->get(route('orders.show', $order->id))->assertOk();
        $webProps = json_encode($webPage->viewData('page')['props']);

        $this->app['auth']->forgetGuards();
        $api = $this->withHeaders(['Authorization' => 'Bearer '.$this->owner->createToken('t')->plainTextToken])
            ->getJson('/api/vehicle/list/report/status')
            ->assertOk();

        foreach ([$webProps, $api->getContent()] as $payload) {
            $this->assertStringNotContainsString(self::TOKEN, $payload);
            $this->assertStringNotContainsString(self::USERNAME, $payload);
            $this->assertStringNotContainsString('authentifizierung', $payload);
            // The booking data the customer's pages need is still there.
            $this->assertStringContainsString('besichtigungsort', $payload);
        }
    }

    public function test_admin_still_sees_the_booking_data(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
        $order = $this->requestedOrder();
        $this->approveViaAdmin($order)->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order->id))->assertOk();

        $vehicle = app(AdminQueryService::class)->vehicleDetail($order->vehicle_id);
        $history = collect($vehicle['order_history'])->firstWhere('id', $order->id);

        $this->assertSame('TÜV SÜD Köln', $history['request_payload']->besichtigungsort->name);
        $this->assertFalse(property_exists($history['request_payload'], 'authentifizierung'));
    }

    public function test_the_migration_removes_stored_credentials_and_keeps_the_booking_data(): void
    {
        $leaked = $this->requestedOrder();
        $clean = $this->requestedOrder();
        DB::table('leasyback_orders')->where('id', $leaked->id)->update(['request_payload' => json_encode([
            ...$leaked->request_payload,
            'authentifizierung' => ['benutzername' => self::USERNAME, 'token' => self::TOKEN],
        ])]);
        $cleanBefore = DB::table('leasyback_orders')->where('id', $clean->id)->value('request_payload');
        $updatedBefore = DB::table('leasyback_orders')->where('id', $leaked->id)->value('updated_at');

        $migration = require database_path('migrations/2026_09_16_155511_remove_tuvsud_credentials_from_order_request_payloads.php');
        $migration->up();
        $migration->up(); // idempotent

        $payload = json_decode(DB::table('leasyback_orders')->where('id', $leaked->id)->value('request_payload'), true);
        $this->assertArrayNotHasKey('authentifizierung', $payload);
        $this->assertSame('TÜV SÜD Köln', $payload['besichtigungsort']['name']);
        $this->assertSame('Bitte Schlüssel mitbringen', $payload['auftrag']['bemerkung']);
        $this->assertCredentialsAbsentFromDatabase($leaked);
        $this->assertSame($updatedBefore, DB::table('leasyback_orders')->where('id', $leaked->id)->value('updated_at'));
        $this->assertSame($cleanBefore, DB::table('leasyback_orders')->where('id', $clean->id)->value('request_payload'));
    }

    public function test_a_legacy_row_with_stored_credentials_is_cleaned_when_it_is_approved(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
        $order = $this->requestedOrder();
        DB::table('leasyback_orders')->where('id', $order->id)->update(['request_payload' => json_encode([
            ...$order->request_payload,
            'authentifizierung' => ['benutzername' => 'old-user', 'token' => 'old-token'],
        ])]);

        $this->approveViaAdmin($order)->assertSessionHasNoErrors();

        // Sent with the configured credentials, not the stale stored ones.
        Http::assertSent(fn (Request $request) => $request['authentifizierung']['token'] === self::TOKEN);
        $this->assertStringNotContainsString('old-token', (string) DB::table('leasyback_orders')->where('id', $order->id)->value('request_payload'));
    }

    // ---------------------------------------------------------------- B2B

    public function test_a_b2b_collection_approval_never_calls_tuvsud(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->makeCompany()));

        $this->approveViaAdmin($order)->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $this->assertSame('order_placed', $order->fresh()->order_status);
    }

    // ------------------------------------------------------------- helpers

    private function requestedOrder(): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => $this->owner->id,
        ]);

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'leasyback_partner' => 'tuvsud',
            'order_status' => 'order_requested',
            'request_payload' => [
                'auftrag' => ['auftragsnummer' => 'X', 'kennzeichen' => $vehicle->license_plate, 'bemerkung' => 'Bitte Schlüssel mitbringen'],
                'besichtigungsort' => ['termin' => '2026-10-01T09:00:00', 'name' => 'TÜV SÜD Köln', 'strasse' => 'Hauptstr. 1', 'plz' => '50667', 'ort' => 'Köln'],
            ],
        ]);
    }

    private function approveViaAdmin(LeasybackOrder $order): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.approve', $order->id));
    }

    private function assertOrderUnchanged(LeasybackOrder $order): void
    {
        $fresh = $order->fresh();

        $this->assertSame('order_requested', $fresh->order_status);
        $this->assertNull($fresh->sent_at);
        $this->assertNull($fresh->response_status);
        $this->assertNull($fresh->response_body);
        $this->assertSame($order->request_payload, $fresh->request_payload);
        $this->assertDatabaseMissing('leasyback_order_status_updates', ['auftragsnummer' => $order->auftragsnummer]);
        $this->assertDatabaseMissing('leasyback_order_audit_log', ['order_id' => $order->id, 'action' => 'APPROVE_ORDER']);
        Notification::assertNothingSent();
        Mail::assertNothingQueued();
    }

    private function assertFailureAudited(LeasybackOrder $order, ?int $httpStatus, ?string $exception): void
    {
        $row = DB::table('leasyback_order_audit_log')->where('order_id', $order->id)->where('action', 'TUVSUD_BOOKING_FAILED')->sole();
        $values = json_decode($row->new_values, true);

        $this->assertSame($httpStatus, $values['http_status']);
        $this->assertSame($exception, $values['exception']);
        $this->assertStringNotContainsString(self::TOKEN, (string) $row->new_values.(string) $row->old_values);
    }

    private function assertCredentialsAbsentFromDatabase(LeasybackOrder $order): void
    {
        $stored = (string) DB::table('leasyback_orders')->where('id', $order->id)->value('request_payload');

        $this->assertStringNotContainsString(self::TOKEN, $stored);
        $this->assertStringNotContainsString(self::USERNAME, $stored);
        $this->assertStringNotContainsString('authentifizierung', $stored);
    }
}
