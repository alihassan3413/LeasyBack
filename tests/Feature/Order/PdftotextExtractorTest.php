<?php

namespace Tests\Feature\Order;

use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\PdfPageText;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use App\Modules\UserProfile\Order\Services\Extraction\PdftotextExtractor;
use Tests\TestCase;

class PdftotextExtractorTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_it_returns_page_aware_lines(): void
    {
        $this->stub(<<<'SH'
        printf 'Wertmindernde Faktoren\n1   Kotflügel rechts   155,00 €\n\n\f Seite zwei \n'
        SH);

        $pages = (new PdftotextExtractor)->extract($this->input());

        $this->assertCount(2, $pages);
        $this->assertContainsOnlyInstancesOf(PdfPageText::class, $pages);
        $this->assertSame(1, $pages[0]->pageNumber);
        $this->assertSame(['Wertmindernde Faktoren', '1 Kotflügel rechts 155,00 €'], $pages[0]->lines);
        $this->assertSame(2, $pages[1]->pageNumber);
        $this->assertSame(['Seite zwei'], $pages[1]->lines);
    }

    public function test_it_passes_the_layout_and_page_limit_arguments(): void
    {
        $log = $this->path('pdftotext-args');
        $this->stub('printf "%s\n" "$@" > '.escapeshellarg($log).'; printf "Wertmindernde Faktoren\nnoch mehr Text\n"');

        config(['gutachten.pdftotext.max_pages' => 12]);

        (new PdftotextExtractor)->extract($this->input());

        $arguments = file($log, FILE_IGNORE_NEW_LINES);

        $this->assertContains('-layout', $arguments);
        $this->assertContains('-q', $arguments);
        $this->assertSame(['-l', '12'], array_slice($arguments, array_search('-l', $arguments, true), 2));
        $this->assertSame('-', end($arguments));
    }

    public function test_a_document_that_is_not_a_pdf_never_reaches_the_binary(): void
    {
        $marker = $this->path('pdftotext-called');
        unlink($marker);
        $this->stub('touch '.escapeshellarg($marker));

        $this->assertFailure(
            fn () => (new PdftotextExtractor)->extract($this->input('not a pdf at all')),
            AppraisalExtractionException::UNSUPPORTED_DOCUMENT,
        );

        $this->assertFileDoesNotExist($marker);
    }

    public function test_an_oversized_document_is_refused(): void
    {
        $this->stub('printf "text"');
        config(['gutachten.pdftotext.max_bytes' => 8]);

        $this->assertFailure(
            fn () => (new PdftotextExtractor)->extract($this->input()),
            AppraisalExtractionException::UNSUPPORTED_DOCUMENT,
        );
    }

    public function test_a_failing_binary_is_reported_as_an_unsupported_document(): void
    {
        $this->stub('echo "broken pdf" >&2; exit 1');

        $this->assertFailure(
            fn () => (new PdftotextExtractor)->extract($this->input()),
            AppraisalExtractionException::UNSUPPORTED_DOCUMENT,
        );
    }

    public function test_a_hanging_binary_is_stopped_by_the_timeout(): void
    {
        $this->stub('sleep 5');
        config(['gutachten.pdftotext.timeout' => 1]);

        $this->assertFailure(
            fn () => (new PdftotextExtractor)->extract($this->input()),
            AppraisalExtractionException::EXTRACTOR_FAILED,
        );
    }

    public function test_a_pdf_without_a_text_layer_is_refused(): void
    {
        $this->stub('printf "Seite 1\f\f"');
        config(['gutachten.pdftotext.min_characters' => 400]);

        $this->assertFailure(
            fn () => (new PdftotextExtractor)->extract($this->input()),
            AppraisalExtractionException::UNSUPPORTED_DOCUMENT,
        );
    }

    public function test_a_missing_binary_is_reported_instead_of_crashing(): void
    {
        config(['gutachten.pdftotext.binary' => '/nonexistent/bin/pdftotext']);

        $extractor = new PdftotextExtractor;

        $this->assertFalse($extractor->isAvailable());
        $this->assertFailure(fn () => $extractor->extract($this->input()), AppraisalExtractionException::UNSUPPORTED_DOCUMENT);
    }

    public function test_the_buffered_file_is_always_removed(): void
    {
        $this->stub('exit 1');

        $before = $this->temporaryFileCount();

        $this->assertFailure(
            fn () => (new PdftotextExtractor)->extract($this->input()),
            AppraisalExtractionException::UNSUPPORTED_DOCUMENT,
        );

        $this->assertSame($before, $this->temporaryFileCount());
    }

    private function stub(string $script): void
    {
        $path = $this->path('pdftotext-stub');
        file_put_contents($path, "#!/bin/sh\n{$script}\n");
        chmod($path, 0700);

        config(['gutachten.pdftotext.binary' => $path, 'gutachten.pdftotext.min_characters' => 1]);
    }

    private function path(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->paths[] = $path;

        return $path;
    }

    private function input(string $contents = "%PDF-1.7\nfake gutachten bytes"): AppraisalExtractionInput
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
        );
    }

    private function temporaryFileCount(): int
    {
        return count(glob(sys_get_temp_dir().'/gutachten-*') ?: []);
    }

    private function assertFailure(callable $action, string $errorCode): void
    {
        try {
            $action();
            $this->fail('The extractor did not fail.');
        } catch (AppraisalExtractionException $exception) {
            $this->assertSame($errorCode, $exception->errorCode);
        }
    }
}
