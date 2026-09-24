<?php

namespace App\Modules\UserProfile\Order\Enums;

enum AppraisalExtractionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Applied = 'applied';
    case Discarded = 'discarded';
    case Failed = 'failed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function activeValues(): array
    {
        return [self::Pending->value, self::Processing->value, self::Ready->value];
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Failed, self::Discarded],
            self::Processing => [self::Ready, self::Failed, self::Pending],
            self::Ready => [self::Applied, self::Discarded],
            self::Failed => [self::Processing, self::Discarded],
            self::Applied, self::Discarded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::activeValues(), true);
    }
}
