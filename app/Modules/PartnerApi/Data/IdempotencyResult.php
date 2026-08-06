<?php

namespace App\Modules\PartnerApi\Data;

use App\Modules\PartnerApi\Enums\PartnerIdempotencyState;
use App\Modules\PartnerApi\Models\PartnerIdempotencyKey;

/**
 * The outcome of claiming an Idempotency-Key, plus the row it resolved to.
 */
final class IdempotencyResult
{
    public function __construct(
        public readonly PartnerIdempotencyState $state,
        public readonly ?PartnerIdempotencyKey $record = null,
        public readonly ?string $reason = null,
    ) {}

    public function isFresh(): bool
    {
        return $this->state === PartnerIdempotencyState::Fresh;
    }
}
