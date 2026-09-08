<?php

namespace Tests\Unit\Support;

use App\Enums\TaskPriority;
use App\Support\PortalTimestamp;
use App\Support\TaskPriorityRule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The timing half of the Admin task priority system: given the persisted
 * business timestamp a task is dated from, which colour is it.
 */
class TaskPriorityRuleTest extends TestCase
{
    private CarbonImmutable $startedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startedAt = CarbonImmutable::parse('2026-09-01 08:00:00', PortalTimestamp::TIME_ZONE);
    }

    public function test_the_lower_green_range_is_green(): void
    {
        $rule = TaskPriorityRule::afterHours(24, 48);

        $this->assertSame(TaskPriority::Green, $this->at($rule, 0));
        $this->assertSame(TaskPriority::Green, $this->at($rule, 3600));
        $this->assertSame(TaskPriority::Green, $this->at($rule, 24 * 3600 - 1));
    }

    public function test_the_green_yellow_boundary_belongs_to_yellow(): void
    {
        $rule = TaskPriorityRule::afterHours(24, 48);

        $this->assertSame(TaskPriority::Green, $this->at($rule, 24 * 3600 - 1));
        $this->assertSame(TaskPriority::Yellow, $this->at($rule, 24 * 3600));
    }

    public function test_the_yellow_range_is_yellow(): void
    {
        $rule = TaskPriorityRule::afterHours(24, 48);

        $this->assertSame(TaskPriority::Yellow, $this->at($rule, 30 * 3600));
        $this->assertSame(TaskPriority::Yellow, $this->at($rule, 48 * 3600 - 1));
    }

    public function test_the_yellow_red_boundary_belongs_to_red(): void
    {
        $rule = TaskPriorityRule::afterHours(24, 48);

        $this->assertSame(TaskPriority::Yellow, $this->at($rule, 48 * 3600 - 1));
        $this->assertSame(TaskPriority::Red, $this->at($rule, 48 * 3600));
    }

    public function test_the_red_range_stays_red_however_long_it_runs(): void
    {
        $rule = TaskPriorityRule::afterHours(24, 48);

        $this->assertSame(TaskPriority::Red, $this->at($rule, 49 * 3600));
        $this->assertSame(TaskPriority::Red, $this->at($rule, 365 * 24 * 3600));
    }

    public function test_a_day_rule_uses_the_same_boundaries(): void
    {
        $rule = TaskPriorityRule::afterDays(2, 3);

        $this->assertSame(TaskPriority::Green, $this->at($rule, 2 * 86400 - 1));
        $this->assertSame(TaskPriority::Yellow, $this->at($rule, 2 * 86400));
        $this->assertSame(TaskPriority::Yellow, $this->at($rule, 3 * 86400 - 1));
        $this->assertSame(TaskPriority::Red, $this->at($rule, 3 * 86400));
    }

    public function test_an_immediate_rule_has_no_green_or_yellow_stage(): void
    {
        $rule = TaskPriorityRule::immediate();

        $this->assertSame(TaskPriority::ImmediateRed, $this->at($rule, 0));
        $this->assertSame(TaskPriority::ImmediateRed, $this->at($rule, 100 * 86400));
        $this->assertSame(TaskPriority::ImmediateRed, $rule->evaluate(null, $this->startedAt));
    }

    public function test_an_appointment_relative_rule_is_green_before_its_appointment(): void
    {
        $rule = TaskPriorityRule::afterAppointmentHours(24, 48);

        $this->assertTrue($rule->isAppointmentRelative());
        $this->assertSame(TaskPriority::Green, $this->at($rule, -10 * 86400));
        $this->assertSame(TaskPriority::Green, $this->at($rule, -1));
        $this->assertSame(TaskPriority::Green, $this->at($rule, 0));
    }

    public function test_an_appointment_relative_rule_keeps_the_same_boundaries_after_its_appointment(): void
    {
        $rule = TaskPriorityRule::afterAppointmentHours(24, 48);

        $this->assertSame(TaskPriority::Green, $this->at($rule, 24 * 3600 - 1));
        $this->assertSame(TaskPriority::Yellow, $this->at($rule, 24 * 3600));
        $this->assertSame(TaskPriority::Yellow, $this->at($rule, 48 * 3600 - 1));
        $this->assertSame(TaskPriority::Red, $this->at($rule, 48 * 3600));
    }

    public function test_an_elapsed_rule_is_not_appointment_relative(): void
    {
        $this->assertFalse(TaskPriorityRule::afterHours(24, 48)->isAppointmentRelative());
        $this->assertFalse(TaskPriorityRule::afterDays(2, 3)->isAppointmentRelative());
        $this->assertFalse(TaskPriorityRule::immediate()->isAppointmentRelative());
    }

    public function test_a_business_date_is_read_as_midnight_in_the_portal_zone(): void
    {
        $instant = PortalTimestamp::instant('2026-09-01');

        $this->assertSame(PortalTimestamp::TIME_ZONE, $instant?->timezoneName);
        $this->assertSame(
            CarbonImmutable::parse('2026-09-01 00:00:00', PortalTimestamp::TIME_ZONE)->getTimestamp(),
            $instant?->getTimestamp(),
        );
    }

    public function test_a_task_without_a_rule_is_neutral(): void
    {
        $rule = TaskPriorityRule::none();

        $this->assertSame(TaskPriority::Neutral, $this->at($rule, 0));
        $this->assertSame(TaskPriority::Neutral, $this->at($rule, 100 * 86400));
    }

    public function test_a_timed_rule_without_a_business_timestamp_is_neutral(): void
    {
        $this->assertSame(
            TaskPriority::Neutral,
            TaskPriorityRule::afterHours(24, 48)->evaluate(null, $this->startedAt),
        );
    }

    public function test_a_timestamp_in_the_future_is_green_rather_than_negative(): void
    {
        $this->assertSame(TaskPriority::Green, $this->at(TaskPriorityRule::afterHours(24, 48), -86400));
    }

    /**
     * The same instant written in three zones is one instant, so it must not
     * produce three different colours — and a Berlin day that is only 23 real
     * hours long (the March DST switch) must not shorten the window either.
     */
    public function test_evaluation_is_timezone_safe(): void
    {
        $rule = TaskPriorityRule::afterHours(24, 48);
        $now = CarbonImmutable::parse('2026-09-03 08:00:00', PortalTimestamp::TIME_ZONE);

        $berlin = CarbonImmutable::parse('2026-09-01 08:00:00', PortalTimestamp::TIME_ZONE);
        $utc = CarbonImmutable::parse('2026-09-01 06:00:00', 'UTC');
        $karachi = CarbonImmutable::parse('2026-09-01 11:00:00', 'Asia/Karachi');

        $this->assertSame(TaskPriority::Red, $rule->evaluate($berlin, $now));
        $this->assertSame($rule->evaluate($berlin, $now), $rule->evaluate($utc, $now));
        $this->assertSame($rule->evaluate($berlin, $now), $rule->evaluate($karachi, $now));

        $this->assertSame(
            PortalTimestamp::instant('2026-09-01T06:00:00+00:00')?->getTimestamp(),
            PortalTimestamp::instant('2026-09-01 06:00:00')?->getTimestamp(),
        );

        $this->assertSame(PortalTimestamp::TIME_ZONE, PortalTimestamp::instant('2026-09-01 06:00:00')?->timezoneName);
    }

    public function test_a_day_rule_measures_elapsed_time_across_the_dst_switch(): void
    {
        $rule = TaskPriorityRule::afterDays(2, 3);
        $beforeSwitch = CarbonImmutable::parse('2026-03-28 12:00:00', PortalTimestamp::TIME_ZONE);

        $this->assertSame(
            TaskPriority::Green,
            $rule->evaluate($beforeSwitch, CarbonImmutable::parse('2026-03-30 12:00:00', PortalTimestamp::TIME_ZONE)),
        );

        $this->assertSame(
            TaskPriority::Yellow,
            $rule->evaluate($beforeSwitch, CarbonImmutable::parse('2026-03-30 13:00:00', PortalTimestamp::TIME_ZONE)),
        );
    }

    public function test_a_rule_whose_bands_cannot_be_ordered_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TaskPriorityRule::afterHours(48, 24);
    }

    private function at(TaskPriorityRule $rule, int $elapsedSeconds): TaskPriority
    {
        return $rule->evaluate($this->startedAt, $this->startedAt->addSeconds($elapsedSeconds));
    }
}
