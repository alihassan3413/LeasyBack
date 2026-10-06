<?php

namespace App\Console\Commands\LegacyImport;

use App\Support\LegacyImport\LegacyExport;
use App\Support\LegacyImport\LegacyReconciler;
use Throwable;

class LegacyReconcileCommand extends AbstractLegacyCommand
{
    protected $signature = 'legacy:reconcile
        {--source= : Folder with the Base44 CSV export (default: LEGACY_IMPORT_SOURCE_PATH)}
        {--report-path= : Folder for the reconciliation report}';

    protected $description = 'Check the export against legacy_import_map and the V2 tables (read-only)';

    public function handle(LegacyReconciler $reconciler): int
    {
        try {
            $report = $reconciler->run(LegacyExport::fromConfig($this->option('source') ?: null));
            $path = $report->write($this->reportDirectory(), 'reconcile-'.now()->format('Ymd-His'), false);
        } catch (Throwable $e) {
            $this->error('Reconciliation aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $failures = array_filter($report->entries(), fn (array $e) => $e['entity'] === 'check' && $e['action'] === 'fail');

        foreach ($failures as $failure) {
            $this->error("{$failure['legacy_id']}: {$failure['detail']}");
        }

        $this->info("Report: {$path}");

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
