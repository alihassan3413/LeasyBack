<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Models\PartnerExternalReference;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Two-way translation between a partner's identifiers and ours.
 *
 * Phase 2 onwards will call this for `external_vehicle_id` and
 * `external_order_id`; nothing in it knows what a vehicle or an order is, so
 * a third entity is a new `reference_type` string and no new code.
 *
 * Every method takes the client explicitly rather than reading PartnerContext
 * itself. A registry that resolved its own scope would be usable from a
 * background job or a console command where no request context exists, and
 * would then silently resolve to whatever was last established — the mapping
 * must be scoped by the caller, visibly.
 */
class PartnerExternalReferenceRegistry
{
    public const TYPE_VEHICLE = 'vehicle';

    public const TYPE_ORDER = 'order';

    /**
     * Our id for a partner's id, or null if the partner has never seen it.
     */
    public function internalId(PartnerIntegrationClient $client, string $type, string $externalId): ?string
    {
        return $this->query($client, $type)
            ->where('external_id', $externalId)
            ->value('internal_id');
    }

    /**
     * The partner's id for one of our records, or null if unmapped.
     */
    public function externalId(PartnerIntegrationClient $client, string $type, string $internalId): ?string
    {
        return $this->query($client, $type)
            ->where('internal_id', $internalId)
            ->value('external_id');
    }

    /**
     * The partner's ids for a page of our records, keyed by our id.
     *
     * The batch counterpart of externalId(). A list endpoint that called the
     * single-row method per item would issue one query per row — the reason
     * this exists at all is that every Partner API list response carries an
     * `external_id` alongside every internal one.
     *
     * @param  list<string>  $internalIds
     * @return array<string, string>
     */
    public function externalIdsFor(PartnerIntegrationClient $client, string $type, array $internalIds): array
    {
        if ($internalIds === []) {
            return [];
        }

        return $this->query($client, $type)
            ->whereIn('internal_id', $internalIds)
            ->pluck('external_id', 'internal_id')
            ->all();
    }

    /**
     * Record a mapping.
     *
     * Uniqueness is enforced by the database in both directions, so a
     * concurrent duplicate loses at the index rather than at a read-then-write
     * race here. The driver exception is translated into a domain one so
     * callers do not have to pattern-match SQLSTATE strings.
     *
     * Re-registering the identical pair is a no-op rather than an error: a
     * partner retrying a create with the same external id must not be
     * punished for a delivery it never saw succeed.
     */
    public function register(
        PartnerIntegrationClient $client,
        string $type,
        string $externalId,
        string $internalId,
    ): PartnerExternalReference {
        $existing = $this->query($client, $type)
            ->where(function ($query) use ($externalId, $internalId) {
                $query->where('external_id', $externalId)->orWhere('internal_id', $internalId);
            })
            ->first();

        if ($existing !== null) {
            if ($existing->external_id === $externalId && $existing->internal_id === $internalId) {
                return $existing;
            }

            throw new RuntimeException($this->conflictMessage($existing, $type, $externalId, $internalId));
        }

        $reference = new PartnerExternalReference([
            'reference_type' => $type,
            'external_id' => $externalId,
            'internal_id' => $internalId,
        ]);

        $reference->partner_integration_client_id = $client->id;

        try {
            $reference->save();
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new RuntimeException(
                    "The external {$type} reference '{$externalId}' is already in use for this partner."
                );
            }

            throw $e;
        }

        return $reference;
    }

    /**
     * @return Builder<PartnerExternalReference>
     */
    private function query(PartnerIntegrationClient $client, string $type): Builder
    {
        return PartnerExternalReference::query()
            ->where('partner_integration_client_id', $client->id)
            ->where('reference_type', $type);
    }

    private function conflictMessage(
        PartnerExternalReference $existing,
        string $type,
        string $externalId,
        string $internalId,
    ): string {
        if ($existing->external_id === $externalId) {
            return "The external {$type} reference '{$externalId}' is already mapped to a different record.";
        }

        return "This {$type} is already mapped to the external reference '{$existing->external_id}'.";
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000/23505 cover MySQL and Postgres; SQLite reports 23000 too.
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
