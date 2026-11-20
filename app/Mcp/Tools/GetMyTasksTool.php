<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mcp\Contracts\McpToolInterface;
use App\Mcp\McpToolResult;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

class GetMyTasksTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_my_tasks';
    }

    public function description(): string
    {
        return 'Mengambil daftar tugas milik pengguna yang sedang login. Mendukung filter opsional berdasarkan status, prioritas, project_id, dan batas jumlah (maksimal 30).';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::cases()),
                    'description' => 'Filter berdasarkan status tugas (todo, in_progress, completed, cancelled). Kosongkan untuk menampilkan tugas aktif (todo & in_progress).',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => array_map(fn (TaskPriority $p): string => $p->value, TaskPriority::cases()),
                    'description' => 'Filter berdasarkan prioritas tugas (urgent, high, medium, low).',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Filter tugas pada proyek tertentu milik pengguna.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Jumlah maksimal tugas yang dikembalikan (1–30, default 20).',
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
        $nowUtc = CarbonImmutable::now('UTC');

        $status = isset($arguments['status']) && is_string($arguments['status'])
            ? TaskStatus::tryFrom(trim($arguments['status']))
            : null;

        $priority = isset($arguments['priority']) && is_string($arguments['priority'])
            ? TaskPriority::tryFrom(trim($arguments['priority']))
            : null;

        $projectId = isset($arguments['project_id']) && is_numeric($arguments['project_id'])
            ? (int) $arguments['project_id']
            : null;

        $limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
            ? max(1, min(30, (int) $arguments['limit']))
            : 20;

        $query = $user->tasks()
            ->with(['project:id,name'])
            ->withCount('subtasks');

        if ($status !== null) {
            $query->where('status', $status->value);
        } else {
            $query->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value]);
        }

        if ($priority !== null) {
            $query->where('priority', $priority->value);
        }

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $tasks = $query
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('due_at')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->limit($limit)
            ->get();

        $items = $tasks->map(function (Task $task) use ($timezone, $nowUtc): array {
            $dueAtUtc = $task->due_at !== null ? CarbonImmutable::instance($task->due_at)->utc() : null;
            $isActive = in_array($task->status, [TaskStatus::Todo, TaskStatus::InProgress], true);

            return [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'project_id' => $task->project_id,
                'project_name' => $task->project?->name,
                'parent_id' => $task->parent_id,
                'subtasks_count' => (int) ($task->getAttribute('subtasks_count') ?? 0),
                'due_at_local' => $dueAtUtc?->setTimezone($timezone)->format('Y-m-d H:i'),
                'is_overdue' => $isActive && $dueAtUtc !== null && $dueAtUtc->lt($nowUtc),
            ];
        })->values()->all();

        return McpToolResult::ok([
            'timezone' => $timezone,
            'count' => count($items),
            'tasks' => $items,
        ]);
    }
}
