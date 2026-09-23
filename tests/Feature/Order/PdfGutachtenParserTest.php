<?php

namespace Tests\Feature\Order;

use App\Modules\UserProfile\Order\Contracts\AppraisalDocumentParser;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\AppraisalProposalLine;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionSource;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenRowSplitter;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenSectionScanner;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenTotals;
use App\Modules\UserProfile\Order\Services\Extraction\PdfGutachtenParser;
use Tests\Support\FakePdfTextExtractor;
use Tests\TestCase;

class PdfGutachtenParserTest extends TestCase
{
    private const COVER = <<<'TEXT'
    DEKRA Automobil GmbH
    Gutachtennummer: GA-2026-0042
    Gutachtendatum: 01.09.2026
    FIN: WVWZZZ1KZAW000001
    Kilometerstand: 48.250 km
    TEXT;

    private const TABLE = <<<'TEXT'
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
    Gebrauchsspuren
    TEXT;

    private FakePdfTextExtractor $textExtractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->textExtractor = new FakePdfTextExtractor;
    }

    public function test_it_builds_a_proposal_from_the_gutachten_text(): void
    {
        $result = $this->parse();

        $this->assertSame(AppraisalExtractionSource::Parser, $result->source);
        $this->assertStringStartsWith('pdf-gutachten-1/fake-text-extractor-1/', $result->extractorVersion);

        $lines = $result->proposal->lines;
        $this->assertCount(4, $lines);
        $this->assertContainsOnlyInstancesOf(AppraisalProposalLine::class, $lines);
        $this->assertSame('Sitzbezug hinten rechts', $lines[0]->component);
        $this->assertSame('Riss', $lines[0]->damageDescription);
        $this->assertSame('Ersetzen', $lines[0]->repairMethod);
        $this->assertSame('699.38', $lines[0]->originalAmountNet);
        $this->assertNull($lines[0]->chargeableAmountNet);
        $this->assertSame('507.50', $lines[1]->originalAmountNet);
        $this->assertSame('268.98', $lines[1]->chargeableAmountNet);
    }

    public function test_every_line_keeps_provenance_and_confidence(): void
    {
        $lines = $this->parse()->proposal->lines;

        foreach ($lines as $line) {
            $this->assertSame(2, $line->pageNumber);
            $this->assertNotSame('', (string) $line->sourceText);
            $this->assertGreaterThan(0.0, (float) $line->confidence);
        }

        $this->assertStringContainsString('Sitzbezug hinten rechts', (string) $lines[0]->sourceText);
    }

    public function test_it_reads_the_metadata_from_the_cover_page(): void
    {
        $proposal = $this->parse()->proposal;

        $this->assertSame('GA-2026-0042', $proposal->appraisalNumber);
        $this->assertSame('2026-09-01', $proposal->appraisalDate);
        $this->assertSame('WVWZZZ1KZAW000001', $proposal->vin);
        $this->assertSame('EUR', $proposal->currency);
        $this->assertSame('1239.13', $proposal->totalNet);
    }

    public function test_metadata_is_left_empty_when_the_gutachten_does_not_state_it(): void
    {
        $this->textExtractor->withText(self::TABLE);

        $proposal = $this->parser()->parse($this->input())->proposal;

        $this->assertNull($proposal->appraisalNumber);
        $this->assertNull($proposal->appraisalDate);
        $this->assertNull($proposal->vin);
        $this->assertSame('EUR', $proposal->currency);
    }

    public function test_an_unparsable_date_is_dropped_rather_than_guessed(): void
    {
        $this->textExtractor->withText("Gutachtendatum: 31.02.2026\nFIN: wvwzzz1kzaw000001")->withText(self::TABLE, 2);

        $proposal = $this->parser()->parse($this->input())->proposal;

        $this->assertNull($proposal->appraisalDate);
        $this->assertSame('WVWZZZ1KZAW000001', $proposal->vin);
    }

    public function test_a_gutachten_whose_positions_add_up_carries_no_total_warning(): void
    {
        $this->assertSame([], $this->parse()->warnings);
    }

    public function test_total_warnings_are_carried_into_the_result(): void
    {
        $this->textExtractor->withText(self::COVER)->withText(<<<'TEXT'
        Summe Minderwerte € 1.239,13 € 235,43 € 1.474,56
        Wertmindernde Faktoren
        1 Sitzbezug hinten rechts Riss Ersetzen € 699,38 € 699,38
        Gebrauchsspuren
        TEXT, 2);

        $result = $this->parser()->parse($this->input());

        $this->assertSame(['missing_positions'], array_column($result->warnings, 'code'));
        $this->assertStringContainsString('539.75', $result->warnings[0]['message']);
        $this->assertStringContainsString('1239.13', $result->warnings[0]['message']);
    }

    public function test_a_gutachten_without_a_total_warns_instead_of_failing(): void
    {
        $this->textExtractor->withText("Wertmindernde Faktoren\n1 Kotflügel rechts Radlauf - Kratzer - erneuern 155,00 € 155,00 €");

        $result = $this->parser()->parse($this->input());

        $this->assertCount(1, $result->proposal->lines);
        $this->assertNull($result->proposal->totalNet);
        $this->assertSame(['appraisal_total_not_found'], array_column($result->warnings, 'code'));
    }

    public function test_a_reconciled_gutachten_produces_no_warnings(): void
    {
        $this->textExtractor->withText(<<<'TEXT'
        Wertmindernde Faktoren
        1 Kotflügel rechts Radlauf - Kratzer - erneuern 155,00 € 155,00 €
        2 Heckklappe/-tür Heckklappe - Kratzer - lackieren 430,00 € 190,00 €
        Schadenzusammenfassung
        Gesamtsumme (ohne Mwst.) 585,00 € 345,00 €
        TEXT);

        $result = $this->parser()->parse($this->input());

        $this->assertSame('345.00', $result->proposal->totalNet);
        $this->assertSame('345.00', (new GutachtenTotals)->sum($result->proposal->lines));
        $this->assertSame([], $result->warnings);
    }

    public function test_it_supports_a_pdf_when_the_text_extractor_is_available(): void
    {
        $this->assertTrue($this->parser()->supports($this->input()));
    }

    public function test_it_does_not_support_a_document_that_is_not_a_pdf(): void
    {
        $this->assertFalse($this->parser()->supports($this->input('Rechnung als Text')));
    }

    public function test_it_does_not_support_a_pdf_beyond_the_size_limit(): void
    {
        config(['gutachten.pdftotext.max_bytes' => 4]);

        $this->assertFalse($this->parser()->supports($this->input()));
    }

    public function test_it_does_not_support_anything_without_a_text_extractor(): void
    {
        $this->textExtractor->available = false;

        $this->assertFalse($this->parser()->supports($this->input()));
    }

    public function test_an_extractor_failure_is_passed_through(): void
    {
        $this->textExtractor->failure = AppraisalExtractionException::unsupportedDocument('The PDF carries no usable text layer.');

        $this->assertFailure(AppraisalExtractionException::UNSUPPORTED_DOCUMENT);
    }

    public function test_a_gutachten_without_damage_positions_is_unsupported(): void
    {
        $this->textExtractor->withText("Besichtigungsbedingungen\nDas Fahrzeug wurde im Freien besichtigt.");

        $this->assertFailure(AppraisalExtractionException::UNSUPPORTED_DOCUMENT);
    }

    public function test_the_parser_is_the_bound_document_parser(): void
    {
        $this->assertInstanceOf(PdfGutachtenParser::class, app(AppraisalDocumentParser::class));
    }

    private function parse(): object
    {
        $this->textExtractor->withText(self::COVER)->withText(self::TABLE, 2);

        return $this->parser()->parse($this->input());
    }

    private function parser(): PdfGutachtenParser
    {
        return new PdfGutachtenParser($this->textExtractor, new GutachtenSectionScanner, new GutachtenRowSplitter, new GutachtenTotals);
    }

    private function input(string $contents = "%PDF-1.7\nerstgutachten"): AppraisalExtractionInput
    {
        return new AppraisalExtractionInput(
            extractionId: 'extraction-1',
            orderId: 'order-1',
            auftragsnummer: 'AUF-00000001',
            vehicleId: 'vehicle-1',
            documentId: 'document-1',
            fileName: 'erstgutachten.pdf',
            contents: $contents,
            sha256: hash('sha256', $contents),
            vin: 'WVWZZZ1KZAW000001',
        );
    }

    private function assertFailure(string $errorCode): void
    {
        try {
            $this->parser()->parse($this->input());
            $this->fail('The parser did not fail.');
        } catch (AppraisalExtractionException $exception) {
            $this->assertSame($errorCode, $exception->errorCode);
        }
    }
}
