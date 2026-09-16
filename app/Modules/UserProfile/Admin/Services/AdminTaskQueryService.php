<?php

namespace App\Modules\UserProfile\Admin\Services;

use App\Enums\TaskPriority;
use App\Modules\UserProfile\Order\Services\DetachedOrderTaskResolver;
use App\Modules\UserProfile\Order\Services\OrderTaskPriorityResolver;
use App\Modules\UserProfile\Order\Services\OrderTaskResolver;

/**
 * Every open admin task across the portal, most urgent first.
 *
 * Deliberately has no task logic of its own. OrderTaskHydrator loads the
 * orders, OrderTaskResolver decides the next step and the priority resolver
 * ranks it — this filters to admin-owned work and sorts. A second definition
 * of "what is the next task" is the one thing this must never become: it
 * would drift from the order page silently, and the two surfaces would
 * disagree about the same order.
 *
 * There is no scan cap. The hydrator loads every active order in a fixed
 * number of queries, so `count` is the real number of open tasks rather than
 * a floor — see OrderTaskHydrator for why that is affordable.
 */
class AdminTaskQueryService
{
    public function __construct(
        private readonly OrderTaskHydrator $hydrator,
        private readonly OrderTaskResolver $resolver,
        private readonly OrderTaskPriorityResolver $priority,
        private readonly DetachedOrderTaskResolver $detached,
    ) {}

    /**
     * `$channel` narrows the list to one channel. B2B tasks carry no SLA
     * priority (OrderTaskPriorityResolver keeps them neutral), so in the mixed
     * list any backlog of dated B2C tasks pushed every B2B task past the page;
     * the per-channel counts and filter keep them reachable.
     *
     * @return array{count: int, urgent: int, scanned: int, channel: string|null, channel_counts: array{B2B: int, B2C: int}, data: list<array<string, mixed>>}
     */
    public function openTasks(int $limit = 25, ?string $channel = null): array
    {
        $channel = in_array($channel, ['B2B', 'B2C'], true) ? $channel : null;

        $orders = $this->hydrator->forActiveOrders();
        $tasks = [];

        foreach ($orders as $order) {
            // The same three calls orderDetail() makes, on the same data.
            $resolved = $this->resolver->forOrderDetail($order);
            $resolved['priority'] = $this->priority
                ->forOrderTasks($resolved, ($order['vehicle_belongs'] ?? null) === 'B2B')
                ->value;
            $resolved['detached'] = $this->detached->forOrderDetail($order);

            $order['tasks'] = $resolved;

            foreach ($this->adminTasksFor($order) as $task) {
                $tasks[] = $task;
            }
        }

        usort($tasks, $this->byUrgency(...));

        $channelCounts = [
            'B2B' => count(array_filter($tasks, fn (array $task) => $task['vehicle_belongs'] === 'B2B')),
            'B2C' => count(array_filter($tasks, fn (array $task) => $task['vehicle_belongs'] !== 'B2B')),
        ];

        if ($channel !== null) {
            $tasks = array_values(array_filter(
                $tasks,
                fn (array $task) => ($task['vehicle_belongs'] === 'B2B') === ($channel === 'B2B'),
            ));
        }

        return [
            'channel' => $channel,
            'channel_counts' => $channelCounts,
            'count' => count($tasks),
            // Counted over every task, not the page: urgent tasks sort first,
            // so counting the sliced list would silently stop at `$limit`.
            'urgent' => count(array_filter($tasks, fn (array $task) => $task['rank'] >= TaskPriority::Red->rank())),
            'scanned' => count($orders),
            'data' => array_slice($tasks, 0, $limit),
        ];
    }

    /**
     * The tasks on one order that are waiting on an admin.
     *
     * A task whose actor is the customer or the workshop is not this
     * dashboard's work — it is something being waited *for*. Detached
     * follow-ups are always the admin's and carry their own priority.
     *
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private function adminTasksFor(array $order): array
    {
        $tasks = [];
        $next = $order['tasks']['next'] ?? null;

        if (is_array($next) && ($next['actor'] ?? null) === OrderTaskResolver::ACTOR_ADMIN) {
            // `next` carries no priority of its own; the resolver stamps the
            // order's verdict one level up.
            $tasks[] = $this->row($order, $next, (string) ($order['tasks']['priority'] ?? TaskPriority::Neutral->value), false);
        }

        foreach ($order['tasks']['detached'] ?? [] as $detached) {
            if (! is_array($detached) || ($detached['actor'] ?? null) !== OrderTaskResolver::ACTOR_ADMIN) {
                continue;
            }

            $tasks[] = $this->row($order, $detached, (string) ($detached['priority'] ?? TaskPriority::Neutral->value), true);
        }

        return $tasks;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function row(array $order, array $task, string $priority, bool $isDetached): array
    {
        $resolved = TaskPriority::tryFrom($priority) ?? TaskPriority::Neutral;

        return [
            'order_id' => $order['id'],
            'auftragsnummer' => $order['auftragsnummer'] ?? null,
            'license_plate' => $order['license_plate'] ?? null,
            // Already on the hydrated order (enrichOrders); passed through so
            // a task row says whose it is. The company for a fleet customer,
            // the account address for a private one.
            'customer' => ($order['vehicle_belongs'] ?? null) === 'B2B'
                ? ($order['company_name'] ?? null)
                : ($order['user_email'] ?? null),
            'vehicle_belongs' => $order['vehicle_belongs'] ?? null,
            'order_status' => $order['order_status'] ?? null,
            'key' => $task['key'] ?? null,
            'title' => $task['title'] ?? null,
            'section' => $task['section'] ?? null,
            'priority' => $resolved->value,
            'rank' => $resolved->rank(),
            'priority_date' => $task['priority_date'] ?? null,
            /** A follow-up rather than a step of the process itself. */
            'is_detached' => $isDetached,
        ];
    }

    /**
     * Most urgent first; among equals, the one waiting longest.
     *
     * A null `priority_date` sorts last within its rank: a task no clock has
     * started on has not been waiting, so it should not displace one that has.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function byUrgency(array $a, array $b): int
    {
        return $b['rank'] <=> $a['rank']
            ?: ($a['priority_date'] ?? '9999') <=> ($b['priority_date'] ?? '9999')
            ?: (string) $a['auftragsnummer'] <=> (string) $b['auftragsnummer'];
    }
}
