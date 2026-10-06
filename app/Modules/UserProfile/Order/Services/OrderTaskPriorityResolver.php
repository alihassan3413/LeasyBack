<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\TaskPriority;
use App\Support\PortalTimestamp;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class OrderTaskPriorityResolver
{
    /**
     * The two timed Admin tasks of an Überführung (traffic-light spec,
     * 30 September 2026). Keyed by task, like every other rule here, so the
     * dashboard list and the order page time them identically.
     */
    public const RELOCATION_APPOINTMENT_TASK = 'relocation_schedule_appointment';

    public const RELOCATION_PROTOCOL_TASK = 'relocation_add_transfer_protocol';

    /**
     * The two timed Admin tasks of a Gutachten. Same two phases as the
     * Überführung: elapsed hours since the order, then calendar days from the
     * appointment date.
     */
    public const APPRAISAL_APPOINTMENT_TASK = 'appraisal_schedule_appointment';

    public const APPRAISAL_REPORT_TASK = 'appraisal_add_report';

    /**
     * The Unfallschaden's Admin tasks. Traffic-light spec: "For now, all open
     * Admin tasks stay green. No time-based escalation."
     */
    public const ACCIDENT_DAMAGE_TASKS = ['accident_schedule_next_step', 'accident_complete'];

    /** Phase 2 counts local calendar days in this zone, weekends and holidays included. */
    private const RELOCATION_TIMEZONE = 'Europe/Berlin';

    private const HOUR = 3600;

    public function __construct(private readonly OrderTaskPriorityRules $rules) {}

    /**
     * @param  array<string, mixed>  $tasks  One OrderTaskResolver::forOrderDetail() result.
     */
    public function forOrderTasks(array $tasks, bool $isB2b, ?DateTimeInterface $now = null): TaskPriority
    {
        if ($tasks['is_closed'] ?? false) {
            return TaskPriority::Neutral;
        }

        $next = $tasks['next'] ?? null;

        return $this->forTask(is_array($next) ? $next : null, $isB2b, $now);
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    public function forTask(?array $task, bool $isB2b, ?DateTimeInterface $now = null): TaskPriority
    {
        if ($task === null) {
            return TaskPriority::Neutral;
        }

        // The Überführung timers apply although it is a B2B order; every
        // other B2B task stays untimed exactly as before.
        $key = (string) ($task['key'] ?? '');

        if ($key === self::RELOCATION_APPOINTMENT_TASK || $key === self::APPRAISAL_APPOINTMENT_TASK) {
            return self::elapsedHoursPriority($task['priority_date'] ?? null, $now ?? PortalTimestamp::now());
        }

        if (in_array($key, self::ACCIDENT_DAMAGE_TASKS, true)) {
            return TaskPriority::Green;
        }

        if ($key === self::RELOCATION_PROTOCOL_TASK || $key === self::APPRAISAL_REPORT_TASK) {
            return self::calendarDayPriority($task['priority_date'] ?? null, $now ?? PortalTimestamp::now());
        }

        if ($isB2b) {
            return TaskPriority::Neutral;
        }

        return $this->rules->for($key)->evaluate(
            PortalTimestamp::instant($task['priority_date'] ?? null),
            $now ?? PortalTimestamp::now(),
        );
    }

    /**
     * Phase 1 — elapsed time since the order was received:
     * green under 24 h, yellow from 24 h, red from 48 h.
     *
     * Real elapsed seconds between two instants, so a daylight-saving change
     * neither adds nor removes an hour.
     */
    public static function elapsedHoursPriority(?string $startedAt, DateTimeInterface $now): TaskPriority
    {
        if ($startedAt === null || trim($startedAt) === '') {
            return TaskPriority::Green;
        }

        $elapsed = $now->getTimestamp() - (new DateTimeImmutable($startedAt))->getTimestamp();

        return match (true) {
            $elapsed < 24 * self::HOUR => TaskPriority::Green,
            $elapsed < 48 * self::HOUR => TaskPriority::Yellow,
            default => TaskPriority::Red,
        };
    }

    /**
     * Phase 2 — local calendar days from the appointment date (Europe/Berlin):
     * green before and on the appointment day, yellow the day after, red from
     * the third day at 00:00. The time slot does not move the clock.
     *
     * Both dates are reduced to plain calendar dates and compared as such, so
     * a daylight-saving change cannot shift a midnight boundary.
     */
    public static function calendarDayPriority(?string $appointmentDate, DateTimeInterface $now): TaskPriority
    {
        if ($appointmentDate === null || trim($appointmentDate) === '') {
            return TaskPriority::Green;
        }

        $utc = new DateTimeZone('UTC');
        $today = DateTimeImmutable::createFromInterface($now)
            ->setTimezone(new DateTimeZone(self::RELOCATION_TIMEZONE))
            ->format('Y-m-d');

        $appointment = new DateTimeImmutable(substr($appointmentDate, 0, 10).' 00:00:00', $utc);
        $current = new DateTimeImmutable($today.' 00:00:00', $utc);
        $daysAfter = (int) $appointment->diff($current)->format('%r%a');

        return match (true) {
            $daysAfter <= 0 => TaskPriority::Green,
            $daysAfter === 1 => TaskPriority::Yellow,
            default => TaskPriority::Red,
        };
    }
}