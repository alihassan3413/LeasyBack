<?php

namespace App\Modules\UserProfile\Order\Data;

final readonly class ExtractedImage
{
    public function __construct(
        public ?string $path,
        public ?int $pageNumber,
        public int $index,
        public int $width,
        public int $height,
        public string $mimeType,
    ) {}

    public function extension(): string
    {
        return $this->mimeType === 'image/png' ? 'png' : 'jpg';
    }
}
