<?php

namespace App\Console\Commands\LegacyImport;

use App\Support\LegacyImport\ImportReport;
use Illuminate\Console\Command;

abstract class AbstractLegacyCommand extends Command
{
    /**
     * Production runs must name themselves; a dry run changes nothing and is
     * always allowed.
     */
    protected function productionGuardFails(bool $dryRun): bool
    {
        if ($dryRun || ! app()->environment('production') || $this->option('confirm-production')) {
            return false;
        }

        $this->error('Refusing to run against production without --confirm-production (use --dry-run to rehearse).');

        return true;
    }

    protected function reportDirectory(): string
    {
        return (string) ($this->option('report-path') ?: config('legacy_import.report_path') ?: storage_path('app/private/legacy-import'));
    }

    protected function printCounts(ImportReport $report): void
    {
        $rows = [];

        foreach ($report->counts() as $entity => $actions) {
            foreach ($actions as $action => $count) {
                $rows[] = [$entity, $action, $count];
            }
        }

        $this->table(['Entity', 'Action', 'Rows'], $rows);
    }
}
