<?php

namespace App\Support\LegacyImport\Steps;

use App\Support\LegacyImport\ImportContext;
use Illuminate\Support\Str;

abstract class AbstractStep implements ImportStep
{
    /**
     * True when this legacy record was handled by an earlier run. A record that
     * changed in the source since then is reported, not re-applied: V2 may have
     * been edited since, and an import must never overwrite that.
     */
    protected function seenBefore(ImportContext $context, string $entity, string $legacyId, string $hash): bool
    {
        $row = $context->map->find($entity, $legacyId);

        if ($row === null) {
            return false;
        }

        // A record skipped earlier may have become importable (its parent
        // arrived since); it is evaluated again rather than stuck.
        if ($row->status === 'skipped') {
            $context->map->forget($entity, $legacyId);

            return false;
        }

        if ($row->source_hash !== null && $row->source_hash !== $hash) {
            $context->report->add($entity, $legacyId, 'changed_at_source', 'source_changed_since_import', 'not applied');
        } else {
            $context->report->add($entity, $legacyId, 'unchanged', 'already_in_map');
        }

        return true;
    }

    protected function uuid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Drop null and empty-string entries.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function filled(array $values): array
    {
        return array_filter($values, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
