<?php

namespace App\Console\Commands\LegacyImport;

use App\Support\LegacyImport\LegacyRollback;
use Throwable;

class LegacyRollbackCommand extends AbstractLegacyCommand
{
    protected $signature = 'legacy:rollback
        {batch : Batch id printed by legacy:import}
        {--report-path= : Folder for the rollback report}
        {--dry-run : Rehearse inside a rolled-back transaction}
        {--confirm-production : Required to run for real when APP_ENV=production}';

    protected $description = 'Undo one legacy:import batch using its map rows only';

    public function handle(LegacyRollback $rollback): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->productionGuardFails($dryRun)) {
            return self::FAILURE;
        }

        try {
            $batch = (string) $this->argument('batch');
            $report = $rollback->run($batch, $dryRun);
            $path = $report->write($this->reportDirectory(), $batch.'-rollback', $dryRun);
        } catch (Throwable $e) {
            $this->error('Rollback aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printCounts($report);
        $this->info(($dryRun ? 'Dry run (nothing persisted). ' : 'Rolled back. ')."Report: {$path}");

        return self::SUCCESS;
    }
}
