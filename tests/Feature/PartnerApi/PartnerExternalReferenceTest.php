<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Models\PartnerExternalReference;
use App\Modules\PartnerApi\Services\PartnerExternalReferenceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\TestCase;

/**
 * External-reference mapping.
 *
 * Nothing consumes this yet — phase 2's `external_vehicle_id` and phase 3's
 * `external_order_id` will. The uniqueness guarantees are tested now because
 * they are what makes the mapping usable as a create guard later: if the same
 * external id could map to two vehicles, a retried create could not be
 * recognised as a duplicate.
 */
class PartnerExternalReferenceTest extends TestCase
{
    use BuildsPartnerClients, RefreshDatabase;

    private PartnerExternalReferenceRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(PartnerExternalReferenceRegistry::class);
    }

    public function test_a_mapping_resolves_in_both_directions(): void
    {
        $client = $this->makePartnerClient();

        $this->registry->register($client, PartnerExternalReferenceRegistry::TYPE_VEHICLE, 'ext-1', 'veh-1');

        $this->assertSame('veh-1', $this->registry->internalId($client, 'vehicle', 'ext-1'));
        $this->assertSame('ext-1', $this->registry->externalId($client, 'vehicle', 'veh-1'));
    }

    public function test_an_unknown_reference_resolves_to_null(): void
    {
        $client = $this->makePartnerClient();

        $this->assertNull($this->registry->internalId($client, 'vehicle', 'nope'));
        $this->assertNull($this->registry->externalId($client, 'vehicle', 'nope'));
    }

    public function test_re_registering_the_identical_pair_is_a_no_op(): void
    {
        $client = $this->makePartnerClient();

        $first = $this->registry->register($client, 'vehicle', 'ext-1', 'veh-1');
        $second = $this->registry->register($client, 'vehicle', 'ext-1', 'veh-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PartnerExternalReference::count());
    }

    public function test_one_external_id_cannot_point_at_two_records(): void
    {
        $client = $this->makePartnerClient();

        $this->registry->register($client, 'vehicle', 'ext-1', 'veh-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already mapped to a different record');

        $this->registry->register($client, 'vehicle', 'ext-1', 'veh-2');
    }

    public function test_one_record_cannot_carry_two_external_ids(): void
    {
        $client = $this->makePartnerClient();

        $this->registry->register($client, 'vehicle', 'ext-1', 'veh-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("already mapped to the external reference 'ext-1'");

        $this->registry->register($client, 'vehicle', 'ext-2', 'veh-1');
    }

    public function test_the_database_enforces_uniqueness_independently_of_the_service(): void
    {
        $client = $this->makePartnerClient();

        $this->registry->register($client, 'vehicle', 'ext-1', 'veh-1');

        $this->expectException(QueryException::class);

        // Bypassing the registry entirely — the index is the real guarantee.
        $duplicate = new PartnerExternalReference([
            'reference_type' => 'vehicle',
            'external_id' => 'ext-1',
            'internal_id' => 'veh-9',
        ]);
        $duplicate->partner_integration_client_id = $client->id;
        $duplicate->save();
    }

    public function test_two_partners_may_use_the_same_external_id_independently(): void
    {
        $alpha = $this->makePartnerClient(slug: 'alpha-partner');
        $beta = $this->makePartnerClient(slug: 'beta-partner');

        $this->registry->register($alpha, 'vehicle', 'ext-1', 'veh-1');
        $this->registry->register($beta, 'vehicle', 'ext-1', 'veh-2');

        $this->assertSame('veh-1', $this->registry->internalId($alpha, 'vehicle', 'ext-1'));
        $this->assertSame('veh-2', $this->registry->internalId($beta, 'vehicle', 'ext-1'));
    }

    public function test_one_partner_never_reads_another_partners_mapping(): void
    {
        $alpha = $this->makePartnerClient(slug: 'alpha-partner');
        $beta = $this->makePartnerClient(slug: 'beta-partner');

        $this->registry->register($alpha, 'vehicle', 'alpha-only', 'veh-1');

        $this->assertNull($this->registry->internalId($beta, 'vehicle', 'alpha-only'));
        $this->assertNull($this->registry->externalId($beta, 'vehicle', 'veh-1'));
    }

    public function test_reference_types_do_not_collide_with_each_other(): void
    {
        $client = $this->makePartnerClient();

        $this->registry->register($client, PartnerExternalReferenceRegistry::TYPE_VEHICLE, 'shared-id', 'veh-1');
        $this->registry->register($client, PartnerExternalReferenceRegistry::TYPE_ORDER, 'shared-id', 'ord-1');

        $this->assertSame('veh-1', $this->registry->internalId($client, 'vehicle', 'shared-id'));
        $this->assertSame('ord-1', $this->registry->internalId($client, 'order', 'shared-id'));
    }

    public function test_deleting_a_client_takes_its_mappings_with_it(): void
    {
        $client = $this->makePartnerClient();

        $this->registry->register($client, 'vehicle', 'ext-1', 'veh-1');
        $client->delete();

        $this->assertSame(0, PartnerExternalReference::count());
    }
}
