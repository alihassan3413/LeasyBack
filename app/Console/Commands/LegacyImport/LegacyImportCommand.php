<?php

namespace App\Console\Commands\LegacyImport;

use App\Support\LegacyImport\ImportOptions;
use App\Support\LegacyImport\LegacyExport;
use App\Support\LegacyImport\LegacyImporter;
use Illuminate\Support\Str;
use Throwable;

class LegacyImportCommand extends AbstractLegacyCommand
{
    protected $signature = 'legacy:import
        {--source= : Folder with the Base44 CSV export (default: LEGACY_IMPORT_SOURCE_PATH)}
        {--report-path= : Folder for the reconciliation report}
        {--step=* : Only these steps (companies, users, vehicles, orders, history, messages, documents, archive)}
        {--dry-run : Run everything inside a rolled-back transaction; nothing is written or downloaded}
        {--confirm-production : Required to run for real when APP_ENV=production}';

    protected $description = 'Import the Base44 export into LeasyBack V2 (idempotent; no mail, notifications, queues or webhooks)';

    public function handle(LegacyImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->productionGuardFails($dryRun)) {
            return self::FAILURE;
        }

        $steps = $this->option('step') ?: ImportOptions::STEPS;

        if ($unknown = array_diff($steps, ImportOptions::STEPS)) {
            $this->error('Unknown step(s): '.implode(', ', $unknown));

            return self::FAILURE;
        }

        try {
            $export = LegacyExport::fromConfig($this->option('source') ?: null);

            if ($export->isInsideRepository()) {
                $this->warn('The export folder is inside the repository. Keep customer data out of the project tree and out of Git.');
            }

            $options = new ImportOptions($dryRun, (string) Str::uuid(), array_values($steps));
            $report = $importer->run($export, $options);
            $path = $report->write($this->reportDirectory(), $options->batchId, $dryRun);
        } catch (Throwable $e) {
            $this->error('Import aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printCounts($report);
        $this->info(($dryRun ? 'Dry run (nothing persisted). ' : 'Imported. ')."Batch {$options->batchId}. Report: {$path}");

        return self::SUCCESS;
    }
}
