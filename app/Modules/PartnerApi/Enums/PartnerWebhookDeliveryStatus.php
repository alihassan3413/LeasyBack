<?php

namespace App\Modules\PartnerApi\Enums;

/**
 * Where one event's delivery to one subscription currently stands.
 *
 * `failed` and `exhausted` are deliberately different states. `failed` means
 * the last attempt did not succeed and another is scheduled; `exhausted` means
 * the retry budget is spent and nothing further will happen without a manual
 * replay. A partner polling `/deliveries` needs to be able to tell "still
 * trying" from "gave up", and a single `failed` cannot say both.
 */
enum PartnerWebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Exhausted = 'exhausted';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isFinished(): bool
    {
        return $this === self::Succeeded || $this === self::Exhausted;
    }

    /** Everything a partner would reasonably call a failure. */
    public function isFailure(): bool
    {
        return $this === self::Failed || $this === self::Exhausted;
    }
}
