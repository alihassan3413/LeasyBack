<?php

namespace App\Support;

use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads a flat `.xlsx` or `.csv` grid into headings plus numbered rows.
 *
 * The counterpart to App\Support\XlsxWriter, which only writes. Reading an
 * xlsx is materially harder than writing one — the shared-string table, date
 * serial numbers and cell-format resolution all have to be handled — so this
 * delegates to openspout rather than growing the writer into a reader. The
 * dependency was approved for phase 15 specifically for that reason.
 *
 * Deliberately narrow: the first non-empty row is the heading row, only the
 * first sheet is read, and nothing here knows what a vehicle is. Mapping
 * headings to domain fields belongs to the caller.
 */
final class SpreadsheetReader
{
    /**
     * Windows Excel writes CSV in the system ANSI codepage, which for German
     * installations is Windows-1252. Detected rather than assumed.
     */
    private const FALLBACK_ENCODING = 'windows-1252';

    /**
     * Candidate CSV delimiters, most specific first. German Excel writes `;`.
     */
    private const DELIMITERS = [';', ',', "\t", '|'];

    /**
     * @return array{headings: list<string>, rows: list<array{number: int, values: list<mixed>}>}
     */
    public static function read(string $path, string $extension, int $maxRows): array
    {
        $reader = match (strtolower($extension)) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader(self::csvOptions($path)),
            default => throw new RuntimeException('Nicht unterstütztes Dateiformat.'),
        };

        $reader->open($path);

        try {
            return self::collect($reader, $maxRows);
        } finally {
            $reader->close();
        }
    }

    /**
     * @return array{headings: list<string>, rows: list<array{number: int, values: list<mixed>}>}
     */
    private static function collect(CsvReader|XlsxReader $reader, int $maxRows): array
    {
        $headings = [];
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                $values = $row->toArray();

                if ($headings === []) {
                    if (self::isBlank($values)) {
                        continue;
                    }

                    $headings = self::normaliseHeadings($values);

                    continue;
                }

                if (self::isBlank($values)) {
                    continue;
                }

                $rows[] = ['number' => (int) $rowNumber, 'values' => array_values($values)];

                if (count($rows) >= $maxRows) {
                    break 2;
                }
            }

            // Only the first sheet is read. A workbook whose fleet list sits on
            // sheet 2 is a support question, not a silent partial import.
            break;
        }

        return ['headings' => $headings, 'rows' => $rows];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function normaliseHeadings(array $values): array
    {
        $headings = [];

        foreach (array_values($values) as $index => $value) {
            $heading = is_string($value) ? $value : (string) self::scalar($value);

            if ($index === 0) {
                $heading = preg_replace('/^\x{FEFF}/u', '', $heading) ?? $heading;
            }

            $headings[] = trim($heading);
        }

        return $headings;
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim(self::scalar($value)) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Sniff the delimiter and encoding from the first line.
     *
     * openspout takes both as fixed options — it does not detect either — and
     * getting them wrong on a German Excel export is the difference between
     * one column of garbage and a clean import.
     */
    private static function csvOptions(string $path): CsvOptions
    {
        $options = new CsvOptions;

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return $options;
        }

        $firstLine = fgets($handle, 65536);

        // Encoding is sniffed from a larger sample than the delimiter, on
        // purpose. A heading row is very often pure ASCII — which is valid
        // UTF-8 — so testing only the first line would clear a Windows-1252
        // file whose umlauts all live in the data rows below it.
        $sample = $firstLine === false ? '' : $firstLine.(string) fread($handle, 65536);
        fclose($handle);

        if ($firstLine === false) {
            return $options;
        }

        $options->FIELD_DELIMITER = self::sniffDelimiter($firstLine);

        if (! mb_check_encoding($sample, 'UTF-8')) {
            $options->ENCODING = self::FALLBACK_ENCODING;
        }

        return $options;
    }

    /**
     * Counts candidate delimiters outside quoted sections, so a comma inside
     * `"Musterstraße 1, Halle B"` does not outvote the real `;` separator.
     */
    private static function sniffDelimiter(string $line): string
    {
        $counts = array_fill_keys(self::DELIMITERS, 0);
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $character = $line[$i];

            if ($character === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && array_key_exists($character, $counts)) {
                $counts[$character]++;
            }
        }

        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? (string) $best : ',';
    }
}
