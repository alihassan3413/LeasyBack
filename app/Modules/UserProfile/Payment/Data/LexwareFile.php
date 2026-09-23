<?php

namespace App\Modules\UserProfile\Payment\Data;

final readonly class LexwareFile
{
    public function __construct(
        public string $contents,
        public string $mimeType = 'application/pdf',
        public ?string $filename = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->contents === '';
    }
}
