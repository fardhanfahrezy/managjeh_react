<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Mcp\Contracts\McpToolInterface;
use App\Mcp\McpToolResult;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class GetUpcomingTasksTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_upcoming_tasks';
    }

    public function description(): string
    {
        return 'Mengambil tugas aktif (todo/in_progress) yang sudah melewati tenggat (overdue) atau memiliki deadline dalam beberapa hari ke depan (1–30 hari, default 7 hari).';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => [
                    'type' => 'integer',
                    'description' => 'Rentang hari ke depan untuk melihat deadline (1–30, default 7).',
                ],
            ],
        ];
    }

    public function isMutation(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(User $user, array $arguments): McpToolResult
    {
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $days = isset($arguments['days']) && is_numeric($arguments['days'])
            ? max(1, min(30, (int) $arguments['days']))
            : 7;

        $nowLocal = CarbonImmutable::now($timezone);
        $nowUtc = $nowLocal->utc();
        $horizonEndUtc = $nowLocal->addDays($days)->endOfDay()->utc();

        $tasks = $user->tasks()
            ->with(['project:id,name'])
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $horizonEndUtc)
            ->orderBy('due_at')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->limit(30)
            ->get();

        $items = $tasks->map(function (Task $task) use ($timezone, $nowUtc): array {
            /** @var CarbonInterface $rawDueAt */
            $rawDueAt = $task->due_at;
            $dueAtUtc = CarbonImmutable::instance($rawDueAt)->utc();

            return [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'project_id' => $task->project_id,
                'project_name' => $task->project?->name,
                'parent_id' => $task->parent_id,
                'due_at_local' => $dueAtUtc->setTimezone($timezone)->format('Y-m-d H:i'),
                'is_overdue' => $dueAtUtc->lt($nowUtc),
            ];
        })->values()->all();

        return McpToolResult::ok([
            'timezone' => $timezone,
            'days' => $days,
            'local_date' => $nowLocal->format('Y-m-d'),
            'count' => count($items),
            'overdue_count' => count(array_filter($items, fn (array $item): bool => $item['is_overdue'])),
            'tasks' => $items,
        ]);
    }
}
