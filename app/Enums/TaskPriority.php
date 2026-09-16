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

    /**
     * Sort weight, highest first: immediate_red > red > yellow > green > neutral.
     *
     * The case order in this enum is declaration order, not importance, and
     * `value` is a colour name — neither can be sorted on. This is the one
     * definition of "more important", so every list that ranks tasks agrees.
     */
    public function rank(): int
    {
        return match ($this) {
            self::ImmediateRed => 4,
            self::Red => 3,
            self::Yellow => 2,
            self::Green => 1,
            self::Neutral => 0,
        };
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
