<?php

namespace Tests\Unit\Order\Extraction;

use App\Modules\UserProfile\Order\Data\AppraisalProposalLine;
use App\Modules\UserProfile\Order\Data\GutachtenRow;
use App\Modules\UserProfile\Order\Data\PdfPageText;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenAmount;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenRowSplitter;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenSectionScanner;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenTotals;
use Tests\TestCase;

class GutachtenParsingTest extends TestCase
{
    private const DEKRA = <<<'TEXT'
    Summe Minderwerte € 1.239,13 € 235,43 € 1.474,56
    Wertmindernde Faktoren
    Nr Bauteil Schadensbeschreibung Reparaturempfehlung Reparaturkosten Minderwert
    1 Sitzbezug hinten rechts Riss Ersetzen € 699,38 € 699,38
    2 Motorhaube Steinschlag Instandsetzen + € 507,50 € 268,98
    lackieren
    3 Heckklappenverkleidung Deformiert Ersetzen € 186,77 € 186,77
    4 Hintere Deformiert Ersetzen € 84,00 € 84,00
    Dachsäulenverkleidung
    rechts
    Summe (ohne MwSt.): € 1.477,65 € 1.239,13
    Gebrauchsspuren
    TEXT;

    private const TUV = <<<'TEXT'
    abrechnungsrelevante Minderwerte 2.416,13 € 459,07 € 2.875,20 €
    Wertmindernde Faktoren
    1 Tür hinten rechts Tür - Kratzer - auslegen / polieren 30,00 € 30,00 €
    2 Kotflügel rechts Kotflügel rechts - Kratzer - auslegen / 30,00 € 30,00 €
    polieren
    3 Seitenwand links Seitenwand - Delle(n) - sanft instandsetzen 100,00 € 100,00 €
    4 Seitenwand rechts Seitenwand - Delle(n) - sanft instandsetzen 100,00 € 100,00 €
    5 Heckklappe/-tür Heckklappe - Delle(n) - sanft instandsetzen 100,00 € 100,00 €
    6 Schweller links Einstieg - Delle(n) - Smart Repair 120,00 € 120,00 €
    7 Verkleidungen/ C-Säule links Verkleidung innen - 126,13 € 126,13 €
    Abdeckungen gebrochen / gerissen - erneuern
    8 Tür hinten links Tür (Auslegen und polieren) - Delle / 130,00 € 130,00 €
    Lackschaden - sanft instandsetzen
    9 Seitenwand rechts Einstieg - Kratzer - Smart Repair 150,00 € 150,00 €
    10 Sonstiges Inspektion/Wartung - fällig - durchführen 350,00 € 350,00 €
    11 Tür vorn rechts Tür - Kratzer - auslegen / polieren 1.180,90 € 350,00 €
    12 Stossfänger hinten Stossfänger hinten - Kratzer - lackieren 1.207,00 € 360,00 €
    13 Fahrzeugdach Dach - Delle / Lackschaden - lackieren 1.579,20 € 470,00 €
    Gebrauchsspuren
    TEXT;

    private const MISSING_PARTS = <<<'TEXT'
    Wertmindernde Faktoren
    1 Kotflügel rechts Radlauf - Kratzer - erneuern 155,00 € 155,00 €
    2 Heckklappe/-tür Heckklappe - Kratzer - lackieren 430,00 € 190,00 €
    Gebrauchsspuren
    Fehlteile
    Ausrüstung - Fahrzeugschlüssel m. Fernb. (1x) 335,00 € 335,00 €
    Schadenzusammenfassung
    Gesamtsumme (ohne Mwst.) 920,00 € 680,00 €
    TEXT;

    public function test_german_amounts_become_decimal_strings(): void
    {
        $this->assertSame('1239.13', GutachtenAmount::toDecimal('€ 1.239,13'));
        $this->assertSame('30.00', GutachtenAmount::toDecimal('30,00 €'));
        $this->assertSame('1579.20', GutachtenAmount::toDecimal('1.579,20 EUR'));
        $this->assertSame('699.38', GutachtenAmount::toDecimal('699 , 38'));
        $this->assertTrue(GutachtenAmount::isPositive('0.01'));
        $this->assertFalse(GutachtenAmount::isPositive('0.00'));
    }

    public function test_it_reads_every_row_of_a_wrapped_dekra_table(): void
    {
        $lines = $this->lines(self::DEKRA);

        $this->assertCount(4, $lines);
        $this->assertSame(['Sitzbezug hinten rechts', 'Motorhaube', 'Heckklappenverkleidung', 'Hintere Dachsäulenverkleidung rechts'], array_column(array_map(fn (AppraisalProposalLine $line) => $line->toArray(), $lines), 'component'));
        $this->assertSame(['699.38', '507.50', '186.77', '84.00'], array_map(fn (AppraisalProposalLine $line) => $line->originalAmountNet, $lines));
        $this->assertSame([null, '268.98', null, null], array_map(fn (AppraisalProposalLine $line) => $line->chargeableAmountNet, $lines));
    }

    public function test_it_reads_a_tuv_table_with_continuation_rows(): void
    {
        $lines = $this->lines(self::TUV);

        $this->assertCount(13, $lines);
        $this->assertSame('2416.13', (new GutachtenTotals)->detect($this->pages(self::TUV)));

        $wrapped = $lines[6];
        $this->assertSame('C-Säule links Verkleidung innen', $wrapped->component);
        $this->assertSame('gebrochen / gerissen', $wrapped->damageDescription);
        $this->assertSame('erneuern', $wrapped->repairMethod);

        $reduced = $lines[12];
        $this->assertSame('Fahrzeugdach', $reduced->component);
        $this->assertSame('Delle / Lackschaden', $reduced->damageDescription);
        $this->assertSame('lackieren', $reduced->repairMethod);
        $this->assertSame('1579.20', $reduced->originalAmountNet);
        $this->assertSame('470.00', $reduced->chargeableAmountNet);
    }

    public function test_it_reads_missing_parts_as_their_own_positions(): void
    {
        $lines = $this->lines(self::MISSING_PARTS);

        $this->assertCount(3, $lines);

        $missing = $lines[2];
        $this->assertSame('Fahrzeugschlüssel m. Fernb. (1x)', $missing->component);
        $this->assertSame('fehlt', $missing->damageDescription);
        $this->assertSame('ersetzen', $missing->repairMethod);
        $this->assertSame('335.00', $missing->originalAmountNet);
        $this->assertNull($missing->chargeableAmountNet);
    }

    public function test_every_line_keeps_the_damage_number_from_the_table(): void
    {
        $this->assertSame([1, 2, 3, 4], array_map(fn (AppraisalProposalLine $line) => $line->damageNumber, $this->lines(self::DEKRA)));
        $this->assertSame(range(1, 13), array_map(fn (AppraisalProposalLine $line) => $line->damageNumber, $this->lines(self::TUV)));
    }

    public function test_a_missing_part_row_carries_no_damage_number(): void
    {
        $lines = $this->lines(self::MISSING_PARTS);

        $this->assertSame([1, 2, null], array_map(fn (AppraisalProposalLine $line) => $line->damageNumber, $lines));
    }

    public function test_the_damage_number_survives_the_proposal_round_trip(): void
    {
        $line = $this->lines(self::TUV)[6];

        $this->assertSame(7, $line->damageNumber);
        $this->assertSame(7, $line->toArray()['damage_number']);
        $this->assertSame(7, AppraisalProposalLine::fromArray($line->toArray())->damageNumber);
    }

    public function test_a_proposal_stored_before_damage_numbers_still_loads(): void
    {
        $legacy = AppraisalProposalLine::fromArray([
            'component' => 'Stossfänger hinten',
            'original_amount_net' => '120.00',
            'repair_method' => 'Smart Repair',
        ]);

        $this->assertNull($legacy->damageNumber);
        $this->assertSame('Stossfänger hinten', $legacy->component);
    }

    public function test_every_line_keeps_its_page_and_source_text(): void
    {
        $pages = [
            new PdfPageText(4, explode("\n", self::DEKRA)),
            new PdfPageText(5, explode("\n", self::TUV)),
        ];

        $lines = $this->linesFromPages($pages);

        $this->assertSame(4, $lines[0]->pageNumber);
        $this->assertStringContainsString('Sitzbezug hinten rechts', (string) $lines[0]->sourceText);
        $this->assertSame(5, $lines[4]->pageNumber);
        $this->assertNotSame('', (string) $lines[4]->sourceText);
    }

    public function test_confidence_reflects_how_the_row_was_understood(): void
    {
        $structured = $this->lines(self::TUV)[0];
        $keyword = $this->lines(self::DEKRA)[0];
        $missing = $this->lines(self::MISSING_PARTS)[2];

        $this->assertSame(0.9, $structured->confidence);
        $this->assertSame(0.7, $keyword->confidence);
        $this->assertSame(0.8, $missing->confidence);
    }

    public function test_sections_outside_the_damage_table_are_ignored(): void
    {
        $text = <<<'TEXT'
        Wertmindernde Faktoren
        1 Kotflügel rechts Radlauf - Kratzer - erneuern 155,00 € 155,00 €
        Gebrauchsspuren
        2 Reifen vorne links Profiltiefe 3 mm 90,00 € 90,00 €
        Beschädigungsfotos
        3 - Sitzbezug hinten rechts - Riss - Ersetzen 699,38 € 699,38 €
        TEXT;

        $lines = $this->lines($text);

        $this->assertCount(1, $lines);
        $this->assertSame('Radlauf', $lines[0]->component);
    }

    public function test_noise_and_summary_rows_never_become_positions(): void
    {
        $text = <<<'TEXT'
        Wertmindernde Faktoren
        Nr Bauteil Schadensbeschreibung Reparaturempfehlung Reparaturkosten Minderwert
        Protokollnummer 4711 Seite 3 von 9 1.000,00 € 1.000,00 €
        1 Kotflügel rechts Radlauf - Kratzer - erneuern 155,00 € 155,00 €
        Summe (ohne MwSt.): € 155,00 € 155,00
        TEXT;

        $lines = $this->lines($text);

        $this->assertCount(1, $lines);
        $this->assertSame('Radlauf', $lines[0]->component);
    }

    public function test_a_duplicate_row_number_is_kept_once(): void
    {
        $text = <<<'TEXT'
        Wertmindernde Faktoren
        1 Kotflügel rechts Radlauf - Kratzer - erneuern 155,00 € 155,00 €
        1 Kotflügel rechts Radlauf - Kratzer - erneuern 155,00 € 155,00 €
        TEXT;

        $this->assertCount(1, $this->lines($text));
    }

    public function test_rows_without_a_positive_amount_are_dropped(): void
    {
        $row = new GutachtenRow(1, 1, 'Tür - Kratzer - lackieren', 'source', ['0.00', '0.00']);

        $this->assertNull((new GutachtenRowSplitter)->split($row));
    }

    public function test_totals_are_detected_by_priority(): void
    {
        $totals = new GutachtenTotals;

        $this->assertSame('1239.13', $totals->detect($this->pages(self::DEKRA)));
        $this->assertSame('680.00', $totals->detect($this->pages(self::MISSING_PARTS)));
        $this->assertNull($totals->detect([new PdfPageText(1, ['Wertmindernde Faktoren', '1 Tür - Kratzer - lackieren 30,00 € 30,00 €'])]));
    }

    public function test_the_sum_uses_the_chargeable_amount_where_the_gutachten_reduced_it(): void
    {
        $totals = new GutachtenTotals;

        $this->assertSame('1239.13', $totals->sum($this->lines(self::DEKRA)));
        $this->assertSame('680.00', $totals->sum($this->lines(self::MISSING_PARTS)));
    }

    public function test_totals_reconcile_against_the_parsed_lines(): void
    {
        $totals = new GutachtenTotals;
        $lines = $this->lines(self::TUV);
        $sum = $totals->sum($lines);

        $this->assertSame('2416.13', $sum);
        $this->assertSame([], $totals->warnings('2416.13', $sum));
        $this->assertSame([], $totals->warnings('2416.14', $sum));

        $missing = $totals->warnings('3000.00', $sum);
        $this->assertSame('missing_positions', $missing[0]['code']);
        $this->assertStringContainsString('583.87', $missing[0]['message']);

        $exceeding = $totals->warnings('2000.00', $sum);
        $this->assertSame('sum_exceeds_total', $exceeding[0]['code']);

        $this->assertSame('appraisal_total_not_found', $totals->warnings(null, $sum)[0]['code']);
    }

    public function test_long_values_are_truncated_to_the_position_limits(): void
    {
        $component = str_repeat('Seitenwand ', 40);
        $row = new GutachtenRow(2, 1, "{$component} - Kratzer - lackieren", str_repeat('x', 900), ['100.00', '80.00']);

        $line = (new GutachtenRowSplitter)->split($row);

        $this->assertSame(255, mb_strlen($line->component));
        $this->assertSame(500, mb_strlen((string) $line->sourceText));
        $this->assertSame('100.00', $line->originalAmountNet);
        $this->assertSame('80.00', $line->chargeableAmountNet);
    }

    private function lines(string $text): array
    {
        return $this->linesFromPages($this->pages($text));
    }

    private function linesFromPages(array $pages): array
    {
        $splitter = new GutachtenRowSplitter;

        return array_values(array_filter(array_map(
            fn (GutachtenRow $row) => $splitter->split($row),
            (new GutachtenSectionScanner)->scan($pages),
        )));
    }

    private function pages(string $text): array
    {
        return [new PdfPageText(1, explode("\n", $text))];
    }
}
