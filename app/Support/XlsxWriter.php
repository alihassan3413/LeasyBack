<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * A minimal, dependency-free `.xlsx` writer.
 *
 * An xlsx file is a zip of a handful of XML parts, and the export this app
 * needs is a flat grid of strings and numbers — no formulas, no merged cells,
 * no charts. Writing those parts directly keeps a spreadsheet library out of
 * composer.json for a feature that would use a fraction of one.
 *
 * Strings are written inline (`t="inlineStr"`) rather than through a shared
 * string table: it costs a few bytes per repeated value and removes a whole
 * part plus its index bookkeeping.
 *
 * Not a general-purpose writer. If a future export needs formulas, images or
 * more than the three cell styles below, reach for a real library instead of
 * growing this one.
 */
final class XlsxWriter
{
    /** Cell style indexes, matching the `cellXfs` order in styles(). */
    private const STYLE_DEFAULT = 0;

    private const STYLE_HEADING = 1;

    private const STYLE_DECIMAL = 2;

    /** @var list<array{name: string, headings: list<string>, rows: list<list<mixed>>}> */
    private array $sheets = [];

    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows  Numeric values are written as numbers, everything else as text.
     */
    public function addSheet(string $name, array $headings, array $rows): self
    {
        $this->sheets[] = [
            'name' => $this->sanitiseSheetName($name),
            'headings' => $headings,
            'rows' => $rows,
        ];

        return $this;
    }

    /**
     * The finished workbook as a binary string, ready to hand to a download
     * response. Built through a temp file because ZipArchive has no in-memory
     * mode; the file is removed before returning either way.
     */
    public function toString(): string
    {
        if ($this->sheets === []) {
            $this->addSheet('Export', [], []);
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Unable to open the export archive for writing.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $this->styles());

            foreach ($this->sheets as $index => $sheet) {
                $zip->addFromString(
                    sprintf('xl/worksheets/sheet%d.xml', $index + 1),
                    $this->worksheet($sheet['headings'], $sheet['rows']),
                );
            }

            $zip->close();

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException('Unable to read the generated export.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    private function contentTypes(): string
    {
        $overrides = '';

        foreach (array_keys($this->sheets) as $index) {
            $overrides .= sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $index + 1,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        $entries = '';

        foreach ($this->sheets as $index => $sheet) {
            $entries .= sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                $this->escape($sheet['name']),
                $index + 1,
                $index + 1,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$entries.'</sheets>'
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        $relationships = '';

        foreach (array_keys($this->sheets) as $index) {
            $relationships .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index + 1,
                $index + 1,
            );
        }

        $stylesId = count($this->sheets) + 1;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
                $stylesId,
            )
            .'</Relationships>';
    }

    /**
     * Three cell formats, in the order the STYLE_* constants index them:
     * default text, bold heading, and a two-decimal number.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     */
    private function worksheet(array $headings, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        $columnCount = max(count($headings), ...array_map('count', $rows ?: [[]]));

        if ($columnCount > 0) {
            $xml .= sprintf('<cols><col min="1" max="%d" width="22" customWidth="1"/></cols>', $columnCount);
        }

        $xml .= '<sheetData>';

        $rowNumber = 1;

        if ($headings !== []) {
            $xml .= $this->row($rowNumber, $headings, true);
            $rowNumber++;
        }

        foreach ($rows as $row) {
            $xml .= $this->row($rowNumber, $row, false);
            $rowNumber++;
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * @param  list<mixed>  $values
     */
    private function row(int $rowNumber, array $values, bool $isHeading): string
    {
        $cells = '';
        $columnIndex = 0;

        foreach ($values as $value) {
            $reference = $this->columnName($columnIndex).$rowNumber;
            $columnIndex++;

            if ($value === null || $value === '') {
                continue;
            }

            // Only real int/float values become numeric cells. A numeric-looking
            // *string* stays text on purpose — an order number or a VIN must not
            // be reformatted, right-aligned or stripped of leading zeros.
            if (! $isHeading && (is_int($value) || is_float($value))) {
                $cells .= sprintf(
                    '<c r="%s" s="%d"><v>%s</v></c>',
                    $reference,
                    self::STYLE_DECIMAL,
                    $this->escape(is_float($value) ? sprintf('%.10G', $value) : (string) $value),
                );

                continue;
            }

            $cells .= sprintf(
                '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                $reference,
                $isHeading ? self::STYLE_HEADING : self::STYLE_DEFAULT,
                $this->escape((string) $value),
            );
        }

        return sprintf('<row r="%d">%s</row>', $rowNumber, $cells);
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private function columnName(int $index): string
    {
        $name = '';

        do {
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    /**
     * Excel rejects a sheet name longer than 31 characters or containing any
     * of `[]:*?/\`, and refuses to open the whole file rather than skipping
     * the sheet — so this is a correctness guard, not cosmetics.
     */
    private function sanitiseSheetName(string $name): string
    {
        $clean = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name));

        return mb_substr($clean === '' ? 'Sheet' : $clean, 0, 31);
    }

    private function escape(string $value): string
    {
        $printable = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($printable, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
