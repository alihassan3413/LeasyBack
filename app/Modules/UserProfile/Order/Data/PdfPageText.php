<?php

namespace App\Modules\UserProfile\Order\Data;

final readonly class PdfPageText
{
    public function __construct(
        public int $pageNumber,
        public array $lines,
    ) {}

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    public function characterCount(): int
    {
        return mb_strlen($this->text());
    }
}
