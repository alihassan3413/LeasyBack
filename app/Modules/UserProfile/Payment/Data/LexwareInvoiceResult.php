<?php

namespace App\Modules\UserProfile\Payment\Data;

final readonly class LexwareInvoiceResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public string $id,
        public ?int $version = null,
        public ?string $resourceUri = null,
        public ?string $voucherStatus = null,
        public ?string $voucherNumber = null,
        public array $body = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body): self
    {
        return new self(
            id: (string) ($body['id'] ?? ''),
            version: isset($body['version']) ? (int) $body['version'] : null,
            resourceUri: isset($body['resourceUri']) ? (string) $body['resourceUri'] : null,
            voucherStatus: isset($body['voucherStatus']) ? (string) $body['voucherStatus'] : null,
            voucherNumber: isset($body['voucherNumber']) ? (string) $body['voucherNumber'] : null,
            body: $body,
        );
    }

    public function isDraft(): bool
    {
        return $this->voucherStatus === 'draft';
    }

    public function isFinalized(): bool
    {
        return $this->voucherStatus !== null && $this->voucherStatus !== 'draft';
    }
}
