<?php

namespace App\Support\LegacyImport;

use App\Models\LegacyImportMap;

/**
 * Reads and writes legacy_import_map for one run. Rows resolve to a V2 id when
 * they were imported, linked to an existing row, or folded into a survivor.
 */
final class IdMap
{
    public const RESOLVABLE = ['imported', 'linked', 'deduplicated'];

    /** @var array<string, LegacyImportMap|null> */
    private array $cache = [];

    /** @var array<string, string> */
    private array $firstBatch = [];

    public function __construct(public readonly string $batchId) {}

    public function find(string $entity, string $legacyId): ?LegacyImportMap
    {
        $key = $entity.'|'.$legacyId;

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = LegacyImportMap::query()
                ->where('entity', $entity)
                ->where('legacy_id', $legacyId)
                ->first();
        }

        return $this->cache[$key];
    }

    /**
     * Drops a row so the record can be evaluated again. If it ends up skipped
     * again it keeps the batch it was first seen in, so rolling that batch back
     * leaves no map rows behind.
     */
    public function forget(string $entity, string $legacyId): void
    {
        $key = $entity.'|'.$legacyId;
        $query = LegacyImportMap::query()->where('entity', $entity)->where('legacy_id', $legacyId);
        $batch = $query->value('batch_id');

        if ($batch !== null) {
            $this->firstBatch[$key] = $batch;
        }

        $query->delete();

        unset($this->cache[$key]);
    }

    /** The V2 id a legacy record resolved to, or null when it has none. */
    public function target(string $entity, string $legacyId): ?string
    {
        $row = $this->find($entity, $legacyId);

        return $row !== null && in_array($row->status, self::RESOLVABLE, true) ? $row->target_id : null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        string $entity,
        string $legacyId,
        string $status,
        ?string $targetTable = null,
        ?string $targetId = null,
        ?array $payload = null,
        ?string $hash = null,
    ): LegacyImportMap {
        $row = LegacyImportMap::query()->create([
            'entity' => $entity,
            'legacy_id' => $legacyId,
            'status' => $status,
            'target_table' => $targetTable,
            'target_id' => $targetId,
            'source_hash' => $hash,
            'payload' => $payload,
            'batch_id' => ($status === 'skipped' ? ($this->firstBatch[$entity.'|'.$legacyId] ?? null) : null) ?? $this->batchId,
        ]);

        return $this->cache[$entity.'|'.$legacyId] = $row;
    }
}
