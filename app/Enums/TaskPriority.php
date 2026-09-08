<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Neutral = 'neutral';
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';
    case ImmediateRed = 'immediate_red';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isApplicable(): bool
    {
        return $this !== self::Neutral;
    }

    public function isOverdue(): bool
    {
        return $this === self::Red || $this === self::ImmediateRed;
    }
}
