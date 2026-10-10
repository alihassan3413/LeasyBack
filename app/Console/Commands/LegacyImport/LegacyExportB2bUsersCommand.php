<?php

namespace App\Console\Commands\LegacyImport;

use App\Support\LegacyImport\LegacyB2bUserReport;
use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Writes the Base44 company users report (see LegacyB2bUserReport) for the
 * client: XLSX by default, or CSV for Excel (semicolon, UTF-8 with BOM).
 *
 * Read-only, so safe in production: it changes no user, company or role. The
 * file holds names and e-mail addresses, so it is written to the private
 * storage folder; the console shows only counts and the path.
 */
class LegacyExportB2bUsersCommand extends Command
{
    protected $signature = 'legacy:export-b2b-users
        {--format=xlsx : xlsx or csv}
        {--output= : File to write (default: storage/app/private/legacy-reports/base44-b2b-benutzer-<timestamp>.<format>)}';

    protected $description = 'Export the users imported from Base44 with their company and role into a German report (read-only)';

    private const COLUMN_WIDTHS = [36, 38, 16, 20, 34, 24, 13, 34, 46, 70];

    public function handle(LegacyB2bUserReport $report): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['xlsx', 'csv'], true)) {
            $this->error('--format must be xlsx or csv.');

            return self::FAILURE;
        }

        $rows = $report->rows();
        $path = (string) ($this->option('output') ?: storage_path('app/private/legacy-reports/base44-b2b-benutzer-'.now()->format('Y-m-d_His').'.'.$format));

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0750, true) && ! is_dir(dirname($path))) {
            $this->error('Cannot create '.dirname($path).'.');

            return self::FAILURE;
        }

        $format === 'xlsx' ? $this->writeXlsx($path, $rows) : $this->writeCsv($path, $rows);

        $flagged = count(array_filter($rows, LegacyB2bUserReport::needsReview(...)));

        $this->table(['', ''], [
            ['Zeilen (Benutzer je Unternehmen)', count($rows)],
            ['Unternehmen', count(array_unique(array_filter(array_column($rows, 'B2B-ID'))))],
            ['Zeilen mit Prüfhinweis', $flagged],
        ]);
        $this->info("Report: {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeXlsx(string $path, array $rows): void
    {
        $options = new XlsxOptions;

        foreach (self::COLUMN_WIDTHS as $index => $width) {
            $options->setColumnWidth($width, $index + 1);
        }

        $writer = new XlsxWriter($options);
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Base44 B2B-Benutzer');
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        $sheet->setAutoFilter(new AutoFilter(0, 1, count(LegacyB2bUserReport::HEADINGS) - 1, max(1, count($rows) + 1)));

        $writer->addRow(Row::fromValues(LegacyB2bUserReport::HEADINGS, (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('0B4F49')));

        $flagged = (new Style)->setBackgroundColor('FDEBD3')->setShouldWrapText();
        $plain = (new Style)->setShouldWrapText();

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_values($row), LegacyB2bUserReport::needsReview($row) ? $flagged : $plain));
        }

        $writer->close();
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $options = new CsvOptions;
        $options->FIELD_DELIMITER = ';';

        $writer = new CsvWriter($options);
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(LegacyB2bUserReport::HEADINGS));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_values($row)));
        }

        $writer->close();
    }
}
