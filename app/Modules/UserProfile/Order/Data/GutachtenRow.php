<?php

namespace App\Modules\UserProfile\Order\Data;

final readonly class GutachtenRow
{
    public function __construct(
        public int $pageNumber,
        public ?int $rowNumber,
        public string $text,
        public string $sourceText,
        public array $amounts,
        public bool $isMissingPart = false,
    ) {}
}
