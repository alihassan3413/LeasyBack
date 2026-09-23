<?php

namespace Tests\Feature\B2b;

use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\B2B\Services\B2bStatisticsService;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\B2bOrderNoteService;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * b2b.txt §16: "Internal notes ... must never appear in customer APIs, emails
 * or exports", and §20's "Internal notes never appear to customers".
 *
 * Both internal-text stores are covered: the §16 note type (phase 16) and the
 * older per-appointment `internal_note` column (phase 3). Each plants a
 * distinct sentinel and asserts its absence across the whole serialized
 * payload rather than checking a single key, so a leak through a nested
 * structure still fails the test.
 */
class B2bNoteIsolationTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private const NOTE_SENTINEL = 'GEHEIM-NOTIZ-SENTINEL';

    private const LOGISTICS_SENTINEL = 'GEHEIM-LOGISTIK-SENTINEL';

    public function test_an_internal_note_never_reaches_the_customer_payload(): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);
        $order = $this->makeB2bOrder($vehicle, 'inspected');
        $notes = app(B2bOrderNoteService::class);

        $notes->create($order, $vehicle, $this->makeAdmin(), [
            'body' => self::NOTE_SENTINEL,
            'visibility' => 'internal',
        ]);
        $notes->create($order, $vehicle, $this->makeAdmin(), [
            'body' => 'Sichtbarer Hinweis',
            'visibility' => 'customer',
        ]);

        $payload = json_encode(app(VehicleService::class)->listVehiclesWithOrders($company->b2b_id, 'B2B'));

        $this->assertStringNotContainsString(self::NOTE_SENTINEL, $payload);
        $this->assertStringContainsString('Sichtbarer Hinweis', $payload);
    }

    public function test_the_customer_note_payload_omits_the_visibility_discriminator(): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);
        $order = $this->makeB2bOrder($vehicle, 'inspected');

        app(B2bOrderNoteService::class)->create($order, $vehicle, $this->makeAdmin(), [
            'body' => 'Sichtbarer Hinweis',
            'visibility' => 'customer',
        ]);

        $payload = app(VehicleService::class)->listVehiclesWithOrders($company->b2b_id, 'B2B');
        $note = $payload[0]['orders'][0]['notes'][0];

        $this->assertSame(['id', 'body', 'author_name', 'created_at'], array_keys($note));
    }

    public function test_the_admin_payload_carries_both_note_types(): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);
        $order = $this->makeB2bOrder($vehicle, 'inspected');
        $notes = app(B2bOrderNoteService::class);

        $notes->create($order, $vehicle, $this->makeAdmin(), [
            'body' => self::NOTE_SENTINEL,
            'visibility' => 'internal',
        ]);

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertStringContainsString(self::NOTE_SENTINEL, json_encode($detail));
        $this->assertSame(['internal'], array_column($detail['notes'], 'visibility'));
    }

    public function test_an_internal_note_never_reaches_the_excel_export(): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);
        $order = $this->makeB2bOrder($vehicle, 'completed');

        app(B2bOrderNoteService::class)->create($order, $vehicle, $this->makeAdmin(), [
            'body' => self::NOTE_SENTINEL,
            'visibility' => 'internal',
        ]);

        $owner = $this->makeOwner($company);
        $membership = app(B2bContext::class)->activeMembership($owner);

        $exported = json_encode(app(B2bStatisticsService::class)->exportRows($membership, $owner->id));

        $this->assertStringNotContainsString(self::NOTE_SENTINEL, $exported);
    }

    /**
     * Phase 3's `internal_note` on the logistics row is the other internal
     * text store, and predates the §16 note type.
     */
    public function test_the_logistics_internal_note_never_reaches_the_customer_payload(): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);
        $order = $this->makeB2bOrder($vehicle, 'confirmed');

        app(OrderCollectionService::class)->updateByAdmin($order, $this->shimVehicle($order), $this->makeAdmin(), [
            'confirmed_collection_date' => now()->addWeek()->format('Y-m-d'),
            'internal_note' => self::LOGISTICS_SENTINEL,
        ]);

        $payload = json_encode(app(VehicleService::class)->listVehiclesWithOrders($company->b2b_id, 'B2B'));

        $this->assertStringNotContainsString(self::LOGISTICS_SENTINEL, $payload);
    }

    public function test_the_logistics_internal_note_is_visible_to_admin(): void
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);
        $order = $this->makeB2bOrder($vehicle, 'confirmed');

        app(OrderCollectionService::class)->updateByAdmin($order, $this->shimVehicle($order), $this->makeAdmin(), [
            'confirmed_collection_date' => now()->addWeek()->format('Y-m-d'),
            'internal_note' => self::LOGISTICS_SENTINEL,
        ]);

        $detail = app(AdminQueryService::class)->orderDetail($order->id);

        $this->assertStringContainsString(self::LOGISTICS_SENTINEL, json_encode($detail));
    }

    public function test_notes_cannot_be_attached_to_a_b2c_order(): void
    {
        $b2cOrder = LeasybackOrder::factory()
            ->create(['order_status' => 'confirmed']);

        $note = app(B2bOrderNoteService::class)->create(
            $b2cOrder,
            $b2cOrder->vehicle,
            $this->makeAdmin(),
            ['body' => self::NOTE_SENTINEL, 'visibility' => 'internal'],
        );

        $this->assertNull($note);
        $this->assertDatabaseCount('b2b_order_notes', 0);
    }

    public function test_the_admin_note_route_404s_on_a_b2c_order(): void
    {
        $b2cOrder = LeasybackOrder::factory()
            ->create(['order_status' => 'confirmed']);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.orders.notes.store', $b2cOrder->id), [
                'body' => 'x',
                'visibility' => 'internal',
            ])
            ->assertNotFound();
    }

    public function test_a_note_without_an_explicit_visibility_is_rejected(): void
    {
        $company = $this->makeCompany();
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'inspected');

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.orders.notes.store', $order->id), ['body' => 'Ohne Sichtbarkeit'])
            ->assertSessionHasErrors('visibility');

        $this->assertDatabaseCount('b2b_order_notes', 0);
    }
}
