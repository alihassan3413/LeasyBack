<?php

namespace App\Modules\UserProfile\Order\Services\Extraction;

use App\Modules\UserProfile\Order\Contracts\PdfTextExtractor;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\PdfPageText;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class PdftotextExtractor implements PdfTextExtractor
{
    private const PDF_MAGIC = '%PDF-';

    public function version(): string
    {
        return 'pdftotext-layout-1';
    }

    public function isAvailable(): bool
    {
        return $this->binaryPath() !== null;
    }

    public function extract(AppraisalExtractionInput $input): array
    {
        $binary = $this->binaryPath();

        if ($binary === null) {
            throw AppraisalExtractionException::unsupportedDocument('pdftotext is not available on this host.');
        }

        if (! str_starts_with($input->contents, self::PDF_MAGIC)) {
            throw AppraisalExtractionException::unsupportedDocument('The document is not a PDF file.');
        }

        $maxBytes = (int) config('gutachten.pdftotext.max_bytes');

        if ($input->byteSize() > $maxBytes) {
            throw AppraisalExtractionException::unsupportedDocument("The PDF exceeds the extraction limit of {$maxBytes} bytes.");
        }

        $file = $this->writeTemporaryFile($input->contents);

        try {
            $output = $this->run($binary, $file);
        } finally {
            @unlink($file);
        }

        $pages = $this->pages($output);

        if ($this->characterCount($pages) < (int) config('gutachten.pdftotext.min_characters')) {
            throw AppraisalExtractionException::unsupportedDocument('The PDF carries no usable text layer.');
        }

        return $pages;
    }

    private function run(string $binary, string $file): string
    {
        $process = new Process([
            $binary,
            '-layout',
            '-enc',
            'UTF-8',
            '-q',
            '-f',
            '1',
            '-l',
            (string) (int) config('gutachten.pdftotext.max_pages'),
            $file,
            '-',
        ], null, ['LC_ALL' => 'C.UTF-8'], null, (float) config('gutachten.pdftotext.timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw AppraisalExtractionException::extractorFailed('pdftotext timed out while reading the Gutachten.', $exception);
        } catch (Throwable $exception) {
            throw AppraisalExtractionException::extractorFailed('pdftotext could not be executed.', $exception);
        }

        if (! $process->isSuccessful()) {
            throw AppraisalExtractionException::unsupportedDocument('pdftotext could not read this PDF.');
        }

        return $process->getOutput();
    }

    private function writeTemporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'gutachten-');

        if ($file === false || file_put_contents($file, $contents) === false) {
            throw AppraisalExtractionException::extractorFailed('The Gutachten could not be buffered for extraction.');
        }

        return $file;
    }

    private function pages(string $output): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $output);

        return array_values(array_map(
            fn (int $index, string $page) => new PdfPageText($index + 1, $this->lines($page)),
            array_keys(explode("\f", $normalized)),
            explode("\f", $normalized),
        ));
    }

    private function lines(string $page): array
    {
        return array_values(array_filter(
            array_map(fn (string $line) => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? ''), explode("\n", $page)),
            fn (string $line) => $line !== '',
        ));
    }

    private function characterCount(array $pages): int
    {
        return array_sum(array_map(fn (PdfPageText $page) => $page->characterCount(), $pages));
    }

    private function binaryPath(): ?string
    {
        $configured = (string) config('gutachten.pdftotext.binary');

        if (str_contains($configured, DIRECTORY_SEPARATOR)) {
            return is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured);
    }
}
