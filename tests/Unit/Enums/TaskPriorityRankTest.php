<?php

namespace Tests\Unit\Enums;

use App\Enums\TaskPriority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one definition of "more important" — every task list sorts on it.
 */
class TaskPriorityRankTest extends TestCase
{
    /**
     * @return array<string, array{0: TaskPriority, 1: int}>
     */
    public static function rankProvider(): array
    {
        return [
            'immediate red' => [TaskPriority::ImmediateRed, 4],
            'red' => [TaskPriority::Red, 3],
            'yellow' => [TaskPriority::Yellow, 2],
            'green' => [TaskPriority::Green, 1],
            'neutral' => [TaskPriority::Neutral, 0],
        ];
    }

    #[DataProvider('rankProvider')]
    public function test_each_priority_has_its_documented_rank(TaskPriority $priority, int $expected): void
    {
        $this->assertSame($expected, $priority->rank());
    }

    public function test_the_order_is_immediate_red_down_to_neutral(): void
    {
        $sorted = TaskPriority::cases();
        usort($sorted, fn (TaskPriority $a, TaskPriority $b) => $b->rank() <=> $a->rank());

        $this->assertSame(
            [
                TaskPriority::ImmediateRed,
                TaskPriority::Red,
                TaskPriority::Yellow,
                TaskPriority::Green,
                TaskPriority::Neutral,
            ],
            $sorted,
        );
    }

    /** Every case must be ranked — a new one cannot silently sort as zero. */
    public function test_every_case_is_ranked_distinctly(): void
    {
        $ranks = array_map(fn (TaskPriority $priority) => $priority->rank(), TaskPriority::cases());

        $this->assertCount(count(TaskPriority::cases()), array_unique($ranks));
    }

    /** The two overdue states must outrank everything that is not overdue. */
    public function test_overdue_priorities_outrank_the_rest(): void
    {
        $overdue = array_filter(TaskPriority::cases(), fn (TaskPriority $p) => $p->isOverdue());
        $rest = array_filter(TaskPriority::cases(), fn (TaskPriority $p) => ! $p->isOverdue());

        $lowestOverdue = min(array_map(fn (TaskPriority $p) => $p->rank(), $overdue));
        $highestRest = max(array_map(fn (TaskPriority $p) => $p->rank(), $rest));

        $this->assertGreaterThan($highestRest, $lowestOverdue);
    }
}
