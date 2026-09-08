<?php

namespace App\Support;

use App\Enums\TaskPriority;
use DateTimeInterface;
use InvalidArgumentException;

final class TaskPriorityRule
{
    private const SECONDS_PER_HOUR = 3600;

    private const SECONDS_PER_DAY = 86400;

    private function __construct(
        private readonly ?int $yellowAfterSeconds,
        private readonly ?int $redAfterSeconds,
        private readonly bool $isImmediate,
        private readonly bool $isAppointmentRelative = false,
    ) {}

    public static function none(): self
    {
        return new self(null, null, false);
    }

    public static function immediate(): self
    {
        return new self(null, null, true);
    }

    public static function afterHours(int $yellowAfterHours, int $redAfterHours): self
    {
        return self::timed(
            $yellowAfterHours * self::SECONDS_PER_HOUR,
            $redAfterHours * self::SECONDS_PER_HOUR,
        );
    }

    public static function afterDays(int $yellowAfterDays, int $redAfterDays): self
    {
        return self::timed(
            $yellowAfterDays * self::SECONDS_PER_DAY,
            $redAfterDays * self::SECONDS_PER_DAY,
        );
    }

    public static function afterAppointmentHours(int $yellowAfterHours, int $redAfterHours): self
    {
        return self::timed(
            $yellowAfterHours * self::SECONDS_PER_HOUR,
            $redAfterHours * self::SECONDS_PER_HOUR,
            true,
        );
    }

    private static function timed(int $yellowAfterSeconds, int $redAfterSeconds, bool $isAppointmentRelative = false): self
    {
        if ($yellowAfterSeconds <= 0 || $redAfterSeconds <= $yellowAfterSeconds) {
            throw new InvalidArgumentException('A timed task priority rule needs 0 < yellow < red.');
        }

        return new self($yellowAfterSeconds, $redAfterSeconds, false, $isAppointmentRelative);
    }

    public function isTimed(): bool
    {
        return $this->yellowAfterSeconds !== null;
    }

    public function isImmediate(): bool
    {
        return $this->isImmediate;
    }

    public function isAppointmentRelative(): bool
    {
        return $this->isAppointmentRelative;
    }

    public function evaluate(?DateTimeInterface $reference, DateTimeInterface $now): TaskPriority
    {
        if ($this->isImmediate) {
            return TaskPriority::ImmediateRed;
        }

        if ($this->yellowAfterSeconds === null || $this->redAfterSeconds === null || $reference === null) {
            return TaskPriority::Neutral;
        }

        $secondsPastReference = $now->getTimestamp() - $reference->getTimestamp();

        if ($this->isAppointmentRelative && $secondsPastReference < 0) {
            return TaskPriority::Green;
        }

        $elapsedSeconds = max(0, $secondsPastReference);

        return match (true) {
            $elapsedSeconds >= $this->redAfterSeconds => TaskPriority::Red,
            $elapsedSeconds >= $this->yellowAfterSeconds => TaskPriority::Yellow,
            default => TaskPriority::Green,
        };
    }
}
