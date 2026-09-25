<?php

namespace App\Modules\UserProfile\Order\Services\Extraction;

use App\Modules\UserProfile\Order\Contracts\AppraisalDocumentParser;
use App\Modules\UserProfile\Order\Contracts\PdfTextExtractor;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionProposal;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionResult;
use App\Modules\UserProfile\Order\Data\AppraisalProposalLine;
use App\Modules\UserProfile\Order\Data\GutachtenRow;
use App\Modules\UserProfile\Order\Data\PdfPageText;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionSource;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use DateTimeImmutable;

class PdfGutachtenParser implements AppraisalDocumentParser
{
    private const VERSION = 'pdf-gutachten-1';

    private const PDF_MAGIC = '%PDF-';

    public function __construct(
        private readonly PdfTextExtractor $textExtractor,
        private readonly GutachtenSectionScanner $scanner,
        private readonly GutachtenRowSplitter $splitter,
        private readonly GutachtenTotals $totals,
    ) {}

    public function version(): string
    {
        return sprintf('%s/%s/%s', self::VERSION, $this->textExtractor->version(), $this->rulesFingerprint());
    }

    public function supports(AppraisalExtractionInput $input): bool
    {
        return $this->textExtractor->isAvailable()
            && str_starts_with($input->contents, self::PDF_MAGIC)
            && $input->byteSize() <= (int) config('gutachten.pdftotext.max_bytes');
    }

    public function parse(AppraisalExtractionInput $input): AppraisalExtractionResult
    {
        $pages = $this->textExtractor->extract($input);
        $lines = $this->lines($pages);

        if ($lines === []) {
            throw AppraisalExtractionException::unsupportedDocument('No damage positions were found in this Gutachten.');
        }

        $total = $this->totals->detect($pages);

        return new AppraisalExtractionResult(
            source: AppraisalExtractionSource::Parser,
            extractorVersion: $this->version(),
            proposal: new AppraisalExtractionProposal(
                lines: $lines,
                appraisalNumber: $this->header($pages, 'appraisal_number'),
                appraisalDate: $this->date($pages),
                vin: $this->vin($pages),
                currency: $this->currency($pages),
                totalNet: $total,
            ),
            warnings: $this->totals->warnings($total, $this->totals->sum($lines)),
        );
    }

    private function lines(array $pages): array
    {
        return array_values(array_filter(array_map(
            fn (GutachtenRow $row) => $this->splitter->split($row),
            $this->scanner->scan($pages),
        ), fn (?AppraisalProposalLine $line) => $line !== null));
    }

    private function header(array $pages, string $key): ?string
    {
        foreach ($this->headerLines($pages) as $line) {
            foreach ((array) config("gutachten.header.{$key}") as $pattern) {
                if (preg_match((string) $pattern, $line, $matches) === 1) {
                    return trim($matches[1]);
                }
            }
        }

        return null;
    }

    private function date(array $pages): ?string
    {
        $raw = $this->header($pages, 'appraisal_date');

        if ($raw === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $raw);

        return $date === false || $date->format('d.m.Y') !== $raw ? null : $date->format('Y-m-d');
    }

    private function vin(array $pages): ?string
    {
        $vin = $this->header($pages, 'vin');

        return $vin === null ? null : strtoupper($vin);
    }

    private function currency(array $pages): ?string
    {
        $text = implode("\n", $this->headerLines($pages));

        foreach ((array) config('gutachten.header.currency') as $currency => $pattern) {
            if (preg_match((string) $pattern, $text) === 1) {
                return (string) $currency;
            }
        }

        return null;
    }

    private function headerLines(array $pages): array
    {
        $limit = (int) config('gutachten.header.max_pages');
        $lines = [];

        foreach ($pages as $page) {
            if ($page instanceof PdfPageText && $page->pageNumber <= $limit) {
                $lines = [...$lines, ...$page->lines];
            }
        }

        return $lines;
    }

    private function rulesFingerprint(): string
    {
        $rules = config('gutachten');
        unset($rules['pdftotext'], $rules['images']);

        return substr(hash('sha256', (string) json_encode($rules)), 0, 8);
    }
}
