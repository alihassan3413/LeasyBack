<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Models\PartnerExternalReference;
use App\Modules\PartnerApi\Services\PartnerExternalReferenceRegistry;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

class PartnerOrderEndpointTest extends TestCase
{
    use BuildsPartnerClients;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'requested_collection_date' => now()->addDays(7)->toDateString(),
            'collection_address' => [
                'street' => 'Musterstraße',
                'number' => '12',
                'zip_code' => '10115',
                'city' => 'Berlin',
                'country' => 'DE',
            ],
            'collection_note' => 'Schlüssel beim Empfang.',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function withKey(string $token, string $key = 'order-1'): array
    {
        return [...$this->bearer($token), 'Idempotency-Key' => $key];
    }

    public function test_a_partner_can_request_collection_of_its_own_vehicle(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload([
                'external_order_id' => 'PO-2026-0042',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.order.status', 'order_requested')
            ->assertJsonPath('data.order.status_label', 'Anfrage gesendet')
            ->assertJsonPath('data.order.is_open', true)
            ->assertJsonPath('data.order.external_id', 'PO-2026-0042')
            ->assertJsonPath('data.order.vehicle.id', $vehicle->vehicle_id)
            // The TÜV SÜD request/response envelope is ours, never the
            // partner's — its absence is part of the contract.
            ->assertJsonMissingPath('data.order.request_payload')
            ->assertJsonMissingPath('data.order.response_body');

        $order = LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->firstOrFail();

        // The B2B collection flow, not the B2C inspection one: no station, no
        // appointment, no external booking call.
        $this->assertSame('leasyback', $order->leasyback_partner);
        $this->assertSame('b2b_collection', $order->request_payload['order_type']);
        $this->assertSame($client->user_id, $order->created_by_user_id);
        $this->assertNull($order->sent_at);

        // The collection details reached the shared logistics row, exactly as
        // a portal submission would have written them.
        $this->assertDatabaseHas('leasyback_order_logistics', [
            'auftragsnummer' => $order->auftragsnummer,
            'pickup_notes' => 'Schlüssel beim Empfang.',
        ]);
        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'order_id' => $order->id,
            'action' => 'REQUEST_ORDER',
        ]);
    }

    public function test_an_order_for_another_companys_vehicle_is_not_found(): void
    {
        $otherCompany = $this->makePartnerCompany('Fremde GmbH');
        $foreign = Vehicle::factory()->forB2b($otherCompany->b2b_id)->create();

        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.orders.store', $foreign->vehicle_id), $this->validPayload())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'vehicle_not_found');

        $this->assertDatabaseCount('leasyback_orders', 0);
    }

    public function test_a_b2c_vehicle_cannot_be_ordered_through_the_partner_api(): void
    {
        $b2cVehicle = Vehicle::factory()->create();

        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.orders.store', $b2cVehicle->vehicle_id), $this->validPayload())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'vehicle_not_found');

        $this->assertDatabaseCount('leasyback_orders', 0);
    }

    public function test_a_vehicle_with_an_open_order_cannot_be_ordered_again(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();

        $this->withHeaders($this->withKey($token, 'order-1'))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload())
            ->assertCreated();

        $this->withHeaders($this->withKey($token, 'order-2'))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_already_open')
            ->assertJsonPath('error.type', 'conflict');

        $this->assertSame(1, LeasybackOrder::count());
    }

    public function test_a_second_order_on_the_same_day_conflicts_rather_than_erroring(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();

        $this->withHeaders($this->withKey($token, 'order-1'))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload())
            ->assertCreated();

        // Closing the first order clears the open-order restriction, but
        // `auftragsnummer` is plate + date and unique application-wide, so a
        // same-day re-order still collides in the shared generator. That is
        // pre-existing and equally true in the portal; what must not happen is
        // a partner receiving an opaque 500 for it.
        LeasybackOrder::query()->update(['order_status' => 'completed']);

        $this->withHeaders($this->withKey($token, 'order-2'))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_reference_conflict');

        $this->assertSame(1, LeasybackOrder::count());
    }

    public function test_a_closed_order_frees_the_vehicle_for_a_new_one_on_a_later_day(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();

        $this->withHeaders($this->withKey($token, 'order-1'))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload())
            ->assertCreated();

        LeasybackOrder::query()->update(['order_status' => 'completed']);

        $this->travel(1)->day();

        $this->withHeaders($this->withKey($token, 'order-2'))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload())
            ->assertCreated();

        $this->assertSame(2, LeasybackOrder::count());
    }

    public function test_the_b2c_inspection_fields_are_refused_on_this_flow(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload([
                'station_id' => '9d2c1f70-6a1a-4c2e-9f0b-1a2b3c4d5e6f',
                'termin' => '2026-09-01T10:00:00+02:00',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['station_id', 'termin']]]]);

        $this->assertDatabaseCount('leasyback_orders', 0);
    }

    public function test_an_external_order_id_is_unique_within_one_integration(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $first = Vehicle::factory()->forB2b($client->b2b_id)->create();
        $second = Vehicle::factory()->forB2b($client->b2b_id)->create();

        $this->withHeaders($this->withKey($token, 'order-1'))
            ->postJson(route('partner.v1.vehicles.orders.store', $first->vehicle_id), $this->validPayload([
                'external_order_id' => 'PO-1',
            ]))
            ->assertCreated();

        $this->withHeaders($this->withKey($token, 'order-2'))
            ->postJson(route('partner.v1.vehicles.orders.store', $second->vehicle_id), $this->validPayload([
                'external_order_id' => 'PO-1',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'external_reference_conflict');

        // The refused create rolled back with its mapping.
        $this->assertSame(1, LeasybackOrder::count());
        $this->assertSame(1, PartnerExternalReference::where('reference_type', 'order')->count());
    }

    public function test_retrying_an_order_with_the_same_idempotency_key_creates_exactly_one_order(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();
        $payload = $this->validPayload();

        $first = $this->withHeaders($this->withKey($token, 'retry-me'))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $payload)
            ->assertCreated();

        $second = $this->withHeaders($this->withKey($token, 'retry-me'))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $payload)
            ->assertCreated();

        $second->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.order.id'), $second->json('data.order.id'));
        $this->assertSame(1, LeasybackOrder::count());
    }

    public function test_orders_are_listed_and_retrievable_only_within_the_tokens_company(): void
    {
        $otherCompany = $this->makePartnerCompany('Fremde GmbH');
        $foreignVehicle = Vehicle::factory()->forB2b($otherCompany->b2b_id)->create();
        $foreignOrder = LeasybackOrder::factory()->create(['vehicle_id' => $foreignVehicle->vehicle_id]);

        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();
        $mine = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.id', $mine->id)
            ->assertJsonPath('data.pagination.total', 1);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.show', $mine->id))
            ->assertOk()
            ->assertJsonPath('data.order.reference', $mine->auftragsnummer);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.show', $foreignOrder->id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'order_not_found');
    }

    public function test_a_vehicles_own_orders_can_be_listed(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();
        $other = Vehicle::factory()->forB2b($client->b2b_id)->create();

        LeasybackOrder::factory()->count(2)->create(['vehicle_id' => $vehicle->vehicle_id]);
        LeasybackOrder::factory()->create(['vehicle_id' => $other->vehicle_id]);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.vehicles.orders.index', $vehicle->vehicle_id))
            ->assertOk()
            ->assertJsonCount(2, 'data.orders');

        $foreign = Vehicle::factory()->forB2b($this->makePartnerCompany('Fremde GmbH')->b2b_id)->create();

        // Not an empty list — "no orders" and "not your vehicle" are different
        // answers and a partner reconciling needs to tell them apart.
        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.vehicles.orders.index', $foreign->vehicle_id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'vehicle_not_found');
    }

    public function test_orders_can_be_filtered_by_status_and_external_id(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();
        $second = Vehicle::factory()->forB2b($client->b2b_id)->create();

        $open = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_requested',
        ]);
        LeasybackOrder::factory()->create([
            'vehicle_id' => $second->vehicle_id,
            'order_status' => 'completed',
        ]);

        app(PartnerExternalReferenceRegistry::class)->register(
            $client,
            PartnerExternalReferenceRegistry::TYPE_ORDER,
            'PO-7',
            $open->id,
        );

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.index', ['status' => 'open']))
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.id', $open->id)
            ->assertJsonPath('data.orders.0.external_id', 'PO-7');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.index', ['status' => 'completed']))
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.is_open', false);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.index', ['external_order_id' => 'PO-7']))
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.id', $open->id);
    }

    public function test_there_is_no_endpoint_for_setting_an_order_status(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_requested',
        ]);

        // The status graph is TransitionOrderStatus's alone. A partner write
        // path into it would be a second, weaker copy of it.
        $this->withHeaders([...$this->bearer($token), 'Idempotency-Key' => 'nope'])
            ->patchJson(route('partner.v1.orders.show', $order->id), ['status' => 'completed'])
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'method_not_allowed');

        $this->assertSame('order_requested', $order->fresh()->order_status);
    }

    public function test_order_scopes_and_company_permissions_are_both_required(): void
    {
        [$client, $readToken] = $this->makeAuthenticatedPartner(
            abilities: [PartnerAbility::ReadOrders->value],
        );
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create();

        $this->withHeaders($this->withKey($readToken))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope')
            ->assertJsonPath('error.details.required_ability', 'orders.write');

        $fullToken = $this->issueToken($client)->plainTextToken;
        $this->revokeCompanyPermission($client, 'orders.create');

        $this->withHeaders($this->withKey($fullToken, 'order-2'))
            ->postJson(route('partner.v1.vehicles.orders.store', $vehicle->vehicle_id), $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_company_permission');

        $this->assertDatabaseCount('leasyback_orders', 0);
    }
}
