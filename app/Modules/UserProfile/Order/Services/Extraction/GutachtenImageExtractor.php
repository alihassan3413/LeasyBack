<?php

namespace App\Modules\UserProfile\Order\Services\Extraction;

use App\Modules\UserProfile\Order\Data\ExtractedImage;
use App\Modules\UserProfile\Order\Exceptions\AppraisalExtractionException;
use Closure;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class GutachtenImageExtractor
{
    private const PDF_MAGIC = '%PDF-';

    private const MIME_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public function isAvailable(): bool
    {
        return $this->binaryPath() !== null;
    }

    public function inspect(string $pdfContents, ?array $allowedPages = null): array
    {
        $binary = $this->binaryPath();

        if ($binary === null || ! str_starts_with($pdfContents, self::PDF_MAGIC)) {
            return [];
        }

        $directory = $this->temporaryDirectory();
        $file = $directory.DIRECTORY_SEPARATOR.'source.pdf';

        try {
            if (file_put_contents($file, $pdfContents) === false) {
                return [];
            }

            $images = [];

            foreach ($this->metadata($binary, $file) as $index => $size) {
                if (! $this->isRelevant($size['width'], $size['height'])) {
                    continue;
                }

                if ($allowedPages !== null && ! in_array($size['page'], $allowedPages, true)) {
                    continue;
                }

                $images[] = new ExtractedImage(
                    path: null,
                    pageNumber: $size['page'],
                    index: $index,
                    width: $size['width'],
                    height: $size['height'],
                    mimeType: 'image/jpeg',
                );
            }

            return $images;
        } catch (AppraisalExtractionException) {
            return [];
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function extract(string $pdfContents, Closure $onImage, ?array $allowedPages = null): int
    {
        $binary = $this->binaryPath();

        if ($binary === null) {
            throw AppraisalExtractionException::unsupportedDocument('pdfimages is not available on this host.');
        }

        if (! str_starts_with($pdfContents, self::PDF_MAGIC)) {
            throw AppraisalExtractionException::unsupportedDocument('The document is not a PDF file.');
        }

        $directory = $this->temporaryDirectory();
        $file = $directory.DIRECTORY_SEPARATOR.'source.pdf';

        try {
            if (file_put_contents($file, $pdfContents) === false) {
                throw AppraisalExtractionException::extractorFailed('The Gutachten could not be buffered for image extraction.');
            }

            $metadata = $this->metadata($binary, $file);

            if ($metadata === []) {
                return 0;
            }

            $this->run([$binary, '-j', '-p', ...$this->pageArguments(), $file, $directory.DIRECTORY_SEPARATOR.'image']);

            return $this->collect($directory, $metadata, $onImage, $allowedPages);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function collect(string $directory, array $metadata, Closure $onImage, ?array $allowedPages): int
    {
        $accepted = 0;
        $limit = (int) config('gutachten.images.max_images');
        $extensions = (array) config('gutachten.images.extensions');

        foreach ($this->files($directory) as $file) {
            if ($accepted >= $limit) {
                break;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (! in_array($extension, $extensions, true) || preg_match('/-(\d{3})-(\d{3,})\.\w+$/', $file, $matches) !== 1) {
                continue;
            }

            $page = (int) $matches[1];
            $index = (int) $matches[2];
            $size = $metadata[$index] ?? null;

            if ($size === null || ! $this->isRelevant($size['width'], $size['height'])) {
                continue;
            }

            if ($allowedPages !== null && ! in_array($page > 0 ? $page : ($size['page'] ?? 0), $allowedPages, true)) {
                continue;
            }

            $onImage(new ExtractedImage(
                path: $file,
                pageNumber: $page > 0 ? $page : ($size['page'] ?? null),
                index: $index,
                width: $size['width'],
                height: $size['height'],
                mimeType: self::MIME_TYPES[$extension],
            ));

            $accepted++;
        }

        return $accepted;
    }

    private function metadata(string $binary, string $file): array
    {
        $output = $this->run([$binary, '-list', ...$this->pageArguments(), $file]);
        $rows = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+\S+\s+(\d+)\s+(\d+)\s+/', $line, $matches) === 1) {
                $rows[(int) $matches[2]] = [
                    'page' => (int) $matches[1],
                    'width' => (int) $matches[3],
                    'height' => (int) $matches[4],
                ];
            }
        }

        return $rows;
    }

    private function isRelevant(int $width, int $height): bool
    {
        $aspect = $width / max($height, 1);

        return $width >= (int) config('gutachten.images.min_width')
            && $height >= (int) config('gutachten.images.min_height')
            && $width * $height >= (int) config('gutachten.images.min_pixels')
            && $aspect >= (float) config('gutachten.images.min_aspect')
            && $aspect <= (float) config('gutachten.images.max_aspect');
    }

    private function run(array $command): string
    {
        $process = new Process($command, null, ['LC_ALL' => 'C.UTF-8'], null, (float) config('gutachten.images.timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw AppraisalExtractionException::extractorFailed('pdfimages timed out while reading the Gutachten.', $exception);
        } catch (Throwable $exception) {
            throw AppraisalExtractionException::extractorFailed('pdfimages could not be executed.', $exception);
        }

        if (! $process->isSuccessful()) {
            throw AppraisalExtractionException::unsupportedDocument('pdfimages could not read this PDF.');
        }

        return $process->getOutput();
    }

    private function pageArguments(): array
    {
        return ['-f', '1', '-l', (string) (int) config('gutachten.images.max_pages')];
    }

    private function files(string $directory): array
    {
        $files = glob($directory.DIRECTORY_SEPARATOR.'image-*') ?: [];

        sort($files);

        return $files;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gutachten-images-'.Str::uuid();

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw AppraisalExtractionException::extractorFailed('A temporary directory for image extraction could not be created.');
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    private function binaryPath(): ?string
    {
        $configured = (string) config('gutachten.images.binary');

        if (str_contains($configured, DIRECTORY_SEPARATOR)) {
            return is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured);
    }
}
