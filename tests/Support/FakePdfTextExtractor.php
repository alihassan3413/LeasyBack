<?php

namespace Tests\Support;

use App\Modules\UserProfile\Order\Contracts\PdfTextExtractor;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\PdfPageText;
use Throwable;

class FakePdfTextExtractor implements PdfTextExtractor
{
    public array $calls = [];

    public bool $available = true;

    public array $pages = [];

    public ?Throwable $failure = null;

    public function version(): string
    {
        return 'fake-text-extractor-1';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function extract(AppraisalExtractionInput $input): array
    {
        $this->calls[] = $input;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->pages;
    }

    public function withText(string $text, int $pageNumber = 1): static
    {
        $this->pages[] = new PdfPageText($pageNumber, array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line) => $line !== '')));

        return $this;
    }
}
