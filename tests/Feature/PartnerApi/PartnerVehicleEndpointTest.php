<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Models\PartnerExternalReference;
use App\Modules\PartnerApi\Services\PartnerExternalReferenceRegistry;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

class PartnerVehicleEndpointTest extends TestCase
{
    use BuildsPartnerClients;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'license_plate' => 'B-PA 1001',
            'vin' => 'WVWZZZ1JZXW000001',
            'make' => 'Volkswagen',
            'model' => 'Passat',
            'first_registration_date' => '2021-03-14',
            'leasing_end_date' => '2026-03-13',
            'leasinggeber' => 'Example Leasing GmbH',
            'mileage' => 84000,
            'contract_number' => 'LV-2021-0042',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function withKey(string $token, string $key = 'create-1'): array
    {
        return [...$this->bearer($token), 'Idempotency-Key' => $key];
    }

    public function test_a_partner_can_create_a_vehicle_in_its_own_company(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        $response = $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload([
                'external_vehicle_id' => 'FLEET-00042',
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.vehicle.license_plate', 'B-PA 1001')
            ->assertJsonPath('data.vehicle.external_id', 'FLEET-00042')
            ->assertJsonPath('data.vehicle.mileage', 84000)
            // No order yet, so no status — and the key is present rather than
            // absent, so a partner can rely on its shape from day one.
            ->assertJsonPath('data.vehicle.status', null)
            ->assertJsonStructure(['data' => ['vehicle' => ['id']], 'request_id']);

        $vehicle = Vehicle::where('license_plate', 'B-PA 1001')->firstOrFail();

        $this->assertSame('B2B', $vehicle->vehicle_belongs);
        $this->assertSame($client->b2b_id, $vehicle->b2b_id);
        $this->assertNull($vehicle->b2c_user_id);
        // Attributed to the integration account, exactly as a member's own
        // creation is attributed to them.
        $this->assertSame($client->user_id, $vehicle->created_by_user_id);
        $this->assertDatabaseHas('vehicle_audit_log', [
            'vehicle_id' => $vehicle->vehicle_id,
            'action' => 'INSERT',
        ]);
    }

    public function test_a_partner_created_vehicle_appears_in_the_company_b2b_dashboard(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload())
            ->assertCreated();

        // The portal's own listing service, called the way the B2B dashboard
        // controller calls it. If a partner write ever stopped going through
        // VehicleService, this is the assertion that would fail.
        $dashboard = app(VehicleService::class)
            ->paginateVehiclesWithOrders($client->b2b_id, 'B2B');

        $this->assertSame(1, $dashboard['meta']['total']);
        $this->assertSame('B-PA 1001', $dashboard['data'][0]['license_plate']);
    }

    public function test_ownership_fields_are_refused_outright(): void
    {
        $otherCompany = $this->makePartnerCompany('Fremde GmbH');
        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload([
                'b2b_id' => $otherCompany->b2b_id,
            ]))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'ownership_input_not_allowed')
            ->assertJsonPath('error.details.rejected_parameters', ['b2b_id']);

        $this->withHeaders($this->withKey($token, 'create-2'))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload([
                'created_by_user_id' => 1,
            ]))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'ownership_input_not_allowed');

        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_vehicle_belongs_is_rejected_rather_than_silently_ignored(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload([
                'vehicle_belongs' => 'B2C',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['vehicle_belongs']]]]);

        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_a_duplicate_license_plate_is_refused_without_naming_the_existing_vehicle(): void
    {
        $otherCompany = $this->makePartnerCompany('Andere GmbH');
        Vehicle::factory()->forB2b($otherCompany->b2b_id)->create(['license_plate' => 'B-PA 1001']);

        [, $token] = $this->makeAuthenticatedPartner();

        $response = $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['license_plate']]]]);

        // The plate is unique application-wide, so the collision may be with a
        // vehicle this token cannot see. Nothing about it may leak.
        $this->assertStringNotContainsString($otherCompany->b2b_id, $response->getContent());
    }

    public function test_a_duplicate_vin_is_accepted_because_the_vin_is_not_unique(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        Vehicle::factory()->forB2b($client->b2b_id)->create(['vin' => 'WVWZZZ1JZXW000001']);

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload())
            ->assertCreated();

        $this->assertSame(2, Vehicle::where('vin', 'WVWZZZ1JZXW000001')->count());
    }

    public function test_an_external_vehicle_id_is_unique_within_one_integration(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->withKey($token, 'create-1'))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload([
                'external_vehicle_id' => 'FLEET-1',
            ]))
            ->assertCreated();

        $this->withHeaders($this->withKey($token, 'create-2'))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload([
                'license_plate' => 'B-PA 1002',
                'external_vehicle_id' => 'FLEET-1',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'external_reference_conflict');

        // The rejected create left nothing behind — the mapping is written in
        // the same transaction as the vehicle.
        $this->assertDatabaseMissing('vehicles', ['license_plate' => 'B-PA 1002']);
        $this->assertSame(1, PartnerExternalReference::count());
    }

    public function test_two_integrations_may_use_the_same_external_vehicle_id(): void
    {
        [, $tokenA] = $this->makeAuthenticatedPartner(slug: 'partner-a');
        [, $tokenB] = $this->makeAuthenticatedPartner(slug: 'partner-b');

        $this->withHeaders($this->withKey($tokenA))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload([
                'external_vehicle_id' => 'FLEET-1',
            ]))
            ->assertCreated();

        $this->withHeaders($this->withKey($tokenB))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload([
                'license_plate' => 'B-PA 2002',
                'external_vehicle_id' => 'FLEET-1',
            ]))
            ->assertCreated();

        $this->assertSame(2, PartnerExternalReference::count());
    }

    public function test_retrying_a_create_with_the_same_idempotency_key_creates_exactly_one_vehicle(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $first = $this->withHeaders($this->withKey($token, 'retry-me'))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload())
            ->assertCreated();

        $second = $this->withHeaders($this->withKey($token, 'retry-me'))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload())
            ->assertCreated();

        $second->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(
            $first->json('data.vehicle.id'),
            $second->json('data.vehicle.id'),
        );
        $this->assertSame(1, Vehicle::count());
    }

    public function test_reusing_an_idempotency_key_for_a_different_payload_conflicts(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->withKey($token, 'same-key'))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload())
            ->assertCreated();

        $this->withHeaders($this->withKey($token, 'same-key'))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload([
                'license_plate' => 'B-PA 9999',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_conflict');

        $this->assertSame(1, Vehicle::count());
    }

    public function test_a_create_without_an_idempotency_key_is_refused(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->bearer($token))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload())
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'idempotency_key_required');

        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_another_companys_vehicle_is_not_found_rather_than_forbidden(): void
    {
        $otherCompany = $this->makePartnerCompany('Fremde GmbH');
        $foreign = Vehicle::factory()->forB2b($otherCompany->b2b_id)->create();

        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.vehicles.show', $foreign->vehicle_id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'vehicle_not_found');

        $this->withHeaders($this->bearer($token))
            ->patchJson(route('partner.v1.vehicles.update', $foreign->vehicle_id), ['mileage' => 1])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'vehicle_not_found');
    }

    public function test_a_b2c_vehicle_is_invisible_to_the_partner_api(): void
    {
        $b2cVehicle = Vehicle::factory()->create();

        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.vehicles.show', $b2cVehicle->vehicle_id))
            ->assertNotFound();
    }

    public function test_the_list_returns_only_the_tokens_company_fleet(): void
    {
        $otherCompany = $this->makePartnerCompany('Fremde GmbH');
        Vehicle::factory()->forB2b($otherCompany->b2b_id)->count(3)->create();
        Vehicle::factory()->count(2)->create(); // B2C

        [$client, $token] = $this->makeAuthenticatedPartner();
        Vehicle::factory()->forB2b($client->b2b_id)->count(2)->create();

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.vehicles.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data.vehicles')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.current_page', 1);
    }

    public function test_the_list_paginates_and_can_be_filtered_by_external_id(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicles = Vehicle::factory()->forB2b($client->b2b_id)->count(5)->create();

        app(PartnerExternalReferenceRegistry::class)->register(
            $client,
            PartnerExternalReferenceRegistry::TYPE_VEHICLE,
            'FLEET-7',
            $vehicles[2]->vehicle_id,
        );

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.vehicles.index', ['per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data.vehicles')
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonPath('data.pagination.total', 5);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.vehicles.index', ['external_vehicle_id' => 'FLEET-7']))
            ->assertOk()
            ->assertJsonCount(1, 'data.vehicles')
            ->assertJsonPath('data.vehicles.0.id', $vehicles[2]->vehicle_id);

        // An id this partner never mapped is an empty page, not the whole fleet.
        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.vehicles.index', ['external_vehicle_id' => 'NOPE']))
            ->assertOk()
            ->assertJsonCount(0, 'data.vehicles');
    }

    public function test_a_vehicle_can_be_updated_but_not_renamed_or_reassigned(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $vehicle = Vehicle::factory()->forB2b($client->b2b_id)->create(['mileage' => 1000]);

        $this->withHeaders($this->bearer($token))
            ->patchJson(route('partner.v1.vehicles.update', $vehicle->vehicle_id), [
                'mileage' => 91000,
                'driver_name' => 'A. Beispiel',
                'external_vehicle_id' => 'FLEET-9',
            ])
            ->assertOk()
            ->assertJsonPath('data.vehicle.mileage', 91000)
            ->assertJsonPath('data.vehicle.driver_name', 'A. Beispiel')
            ->assertJsonPath('data.vehicle.external_id', 'FLEET-9');

        $this->withHeaders($this->bearer($token))
            ->patchJson(route('partner.v1.vehicles.update', $vehicle->vehicle_id), [
                'license_plate' => 'B-NEW 1',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['license_plate']]]]);

        $this->assertSame(91000, $vehicle->fresh()->mileage);
    }

    public function test_a_read_only_token_cannot_write_and_a_write_only_token_cannot_read(): void
    {
        [$client, $readToken] = $this->makeAuthenticatedPartner(
            abilities: [PartnerAbility::ReadVehicles->value],
        );

        $this->withHeaders($this->withKey($readToken))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope')
            ->assertJsonPath('error.details.required_ability', 'vehicles.write');

        // The refused scope must not have consumed the Idempotency-Key either.
        $this->assertDatabaseCount('partner_idempotency_keys', 0);

        $writeOnly = $this->issueToken($client, [PartnerAbility::WriteVehicles->value])->plainTextToken;

        $this->withHeaders($this->bearer($writeOnly))
            ->getJson(route('partner.v1.vehicles.index'))
            ->assertForbidden()
            ->assertJsonPath('error.details.required_ability', 'vehicles.read');
    }

    public function test_a_token_whose_integration_account_lost_a_company_permission_is_refused(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();

        // The token still carries vehicles.write; the company permission behind
        // it has been withdrawn. Both gates must pass, so this is refused.
        $this->revokeCompanyPermission($client, 'vehicles.create');

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.vehicles.store'), $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_company_permission')
            ->assertJsonPath('error.details.required_permission', 'vehicles.create');

        $this->assertDatabaseCount('vehicles', 0);
    }
}
