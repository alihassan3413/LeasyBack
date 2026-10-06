<?php

namespace App\Support\LegacyImport;

use App\Support\LegacyImport\Steps\ArchiveStep;
use App\Support\LegacyImport\Steps\BooksStep;
use App\Support\LegacyImport\Steps\CompanyStep;
use App\Support\LegacyImport\Steps\DocumentStep;
use App\Support\LegacyImport\Steps\ImportStep;
use App\Support\LegacyImport\Steps\MessageStep;
use App\Support\LegacyImport\Steps\OrderStep;
use App\Support\LegacyImport\Steps\StatusHistoryStep;
use App\Support\LegacyImport\Steps\UserStep;
use App\Support\LegacyImport\Steps\VehicleStep;
use Illuminate\Support\Facades\DB;

/**
 * Runs the steps in dependency order.
 *
 * Everything goes through the query builder, so no model event, observer,
 * mailable, notification, broadcast, webhook or queued job is ever triggered.
 * A dry run executes the very same code inside one transaction that is rolled
 * back, so constraints and conflicts surface exactly as in a real run while
 * nothing persists (documents are not downloaded in a dry run).
 */
final class LegacyImporter
{
    /**
     * @return list<ImportStep>
     */
    private function steps(): array
    {
        return [
            new CompanyStep, new BooksStep, new UserStep, new VehicleStep, new OrderStep,
            new StatusHistoryStep, new MessageStep, new DocumentStep, new ArchiveStep,
        ];
    }

    public function run(LegacyExport $export, ImportOptions $options): ImportReport
    {
        $context = new ImportContext($export, $options);

        if ($options->dryRun) {
            DB::beginTransaction();
        }

        try {
            foreach ($this->steps() as $step) {
                if ($options->runs($step->name())) {
                    $step->run($context);
                }
            }
        } finally {
            if ($options->dryRun) {
                DB::rollBack();
            }
        }

        return $context->report;
    }
}
