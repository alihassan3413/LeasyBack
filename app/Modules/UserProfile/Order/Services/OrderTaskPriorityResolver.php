<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\TaskPriority;
use App\Support\PortalTimestamp;
use DateTimeInterface;

class OrderTaskPriorityResolver
{
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
        if ($isB2b || $task === null) {
            return TaskPriority::Neutral;
        }

        return $this->rules->for((string) ($task['key'] ?? ''))->evaluate(
            PortalTimestamp::instant($task['priority_date'] ?? null),
            $now ?? PortalTimestamp::now(),
        );
    }
}
