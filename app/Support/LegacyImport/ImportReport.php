<?php

namespace App\Support\LegacyImport;

use RuntimeException;

/**
 * The reconciliation trail: every imported, unchanged, linked, deduplicated,
 * archived, skipped or flagged record appears here with a reason. Written as
 * CSV next to a JSON summary. Contains legacy ids (and, for the staff and user
 * review lines, e-mail addresses), so it belongs in private storage.
 */
final class ImportReport
{
    /** @var list<array{entity: string, legacy_id: string, action: string, reason: string, detail: string}> */
    private array $entries = [];

    public function add(string $entity, string $legacyId, string $action, string $reason = '', string $detail = ''): void
    {
        $this->entries[] = [
            'entity' => $entity,
            'legacy_id' => $legacyId,
            'action' => $action,
            'reason' => $reason,
            'detail' => $detail,
        ];
    }

    /**
     * @return list<array{entity: string, legacy_id: string, action: string, reason: string, detail: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->entries as $entry) {
            $counts[$entry['entity']][$entry['action']] = ($counts[$entry['entity']][$entry['action']] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    public function has(string $entity, string $action, ?string $reason = null): int
    {
        return count(array_filter(
            $this->entries,
            fn (array $e) => $e['entity'] === $entity && $e['action'] === $action && ($reason === null || $e['reason'] === $reason),
        ));
    }

    /**
     * @return string the report directory
     */
    public function write(string $directory, string $batchId, bool $dryRun): string
    {
        $target = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$batchId.($dryRun ? '-dry-run' : '');

        if (! is_dir($target) && ! mkdir($target, 0700, true) && ! is_dir($target)) {
            throw new RuntimeException("Cannot create report directory {$target}.");
        }

        $handle = fopen($target.DIRECTORY_SEPARATOR.'reconciliation.csv', 'wb');
        fputcsv($handle, ['entity', 'legacy_id', 'action', 'reason', 'detail'], ',', '"', '');

        foreach ($this->entries as $entry) {
            fputcsv($handle, array_map($this->safeCell(...), $entry), ',', '"', '');
        }

        fclose($handle);

        file_put_contents(
            $target.DIRECTORY_SEPARATOR.'summary.json',
            json_encode(['batch' => $batchId, 'dry_run' => $dryRun, 'counts' => $this->counts()], JSON_PRETTY_PRINT),
        );

        return $target;
    }

    /** Keeps a spreadsheet from running a cell that starts like a formula. */
    private function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
