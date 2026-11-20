<?php

namespace App\Mcp\Tools;

use App\Enums\ProjectStatus;
use App\Mcp\Contracts\McpToolInterface;
use App\Mcp\McpToolResult;
use App\Models\Project;
use App\Models\User;

class GetMyProjectsTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_my_projects';
    }

    public function description(): string
    {
        return 'Mengambil daftar proyek milik pengguna beserta persentase progres dan jumlah tugasnya.';
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
                    'enum' => ['active', 'completed', 'archived', 'all'],
                    'description' => 'Filter status proyek (active, completed, archived, atau all). Default active.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Jumlah maksimal proyek yang dikembalikan (1–30, default 20).',
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
        $rawStatus = isset($arguments['status']) && is_string($arguments['status'])
            ? trim(strtolower($arguments['status']))
            : ProjectStatus::Active->value;

        $limit = isset($arguments['limit']) && is_numeric($arguments['limit'])
            ? max(1, min(30, (int) $arguments['limit']))
            : 20;

        $query = $user->projects()->withProgressCounts();

        if ($rawStatus !== 'all') {
            $statusEnum = ProjectStatus::tryFrom($rawStatus) ?? ProjectStatus::Active;
            $query->where('status', $statusEnum->value);
        }

        $projects = $query
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('due_date')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $items = $projects->map(function (Project $project): array {
            $completedCount = (int) ($project->completed_tasks_count ?? 0);
            $totalCount = (int) ($project->non_cancelled_tasks_count ?? 0);

            return [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status->value,
                'start_date' => $project->start_date?->format('Y-m-d'),
                'due_date' => $project->due_date?->format('Y-m-d'),
                'progress_percent' => $project->calculateProgress(),
                'completed_tasks_count' => $completedCount,
                'total_tasks_count' => $totalCount,
                'remaining_tasks_count' => max(0, $totalCount - $completedCount),
            ];
        })->values()->all();

        return McpToolResult::ok([
            'count' => count($items),
            'projects' => $items,
        ]);
    }
}
