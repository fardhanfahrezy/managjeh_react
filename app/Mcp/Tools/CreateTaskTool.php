<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mcp\Contracts\McpMutationToolInterface;
use App\Mcp\McpToolResult;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminder\ReminderSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateTaskTool implements McpMutationToolInterface
{
    public function __construct(
        private ReminderSynchronizer $reminderSynchronizer,
    ) {}

    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Mengusulkan pembuatan tugas baru untuk pengguna. Perubahan tidak langsung disimpan sebelum disetujui pengguna.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Judul tugas (wajib, maksimal 200 karakter).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Deskripsi atau catatan detail tugas (opsional).',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => array_map(fn (TaskPriority $p): string => $p->value, TaskPriority::cases()),
                    'description' => 'Prioritas tugas: urgent, high, medium (default), atau low.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::cases()),
                    'description' => 'Status awal tugas (default todo).',
                ],
                'due_at' => [
                    'type' => 'string',
                    'description' => 'Tenggat waktu lokal pengguna format YYYY-MM-DD HH:MM atau ISO 8601 (opsional).',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID proyek milik pengguna jika tugas terkait proyek tertentu (opsional).',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'ID tugas induk milik pengguna jika ini adalah subtugas (maksimal 1 tingkat, opsional).',
                ],
            ],
            'required' => ['title'],
        ];
    }

    public function isMutation(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     valid: bool,
     *     payload: array<string, mixed>,
     *     conflict_preview: array<int, array<string, mixed>>,
     *     summary: string,
     *     error: string|null,
     * }
     */
    public function preview(User $user, array $arguments): array
    {
        if (! Gate::forUser($user)->allows('create', Task::class)) {
            return [
                'valid' => false,
                'payload' => [],
                'conflict_preview' => [],
                'summary' => '',
                'error' => 'Pengguna tidak memiliki izin membuat tugas.',
            ];
        }

        $validated = $this->validate($user, $arguments);

        if ($validated['error'] !== null) {
            return [
                'valid' => false,
                'payload' => [],
                'conflict_preview' => [],
                'summary' => '',
                'error' => $validated['error'],
            ];
        }

        $payload = $validated['payload'];
        $title = (string) $payload['title'];

        return [
            'valid' => true,
            'payload' => $payload,
            'conflict_preview' => [],
            'summary' => sprintf('Buat tugas: %s', $title),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(User $user, array $arguments): McpToolResult
    {
        Gate::forUser($user)->authorize('create', Task::class);

        $validated = $this->validate($user, $arguments);

        if ($validated['error'] !== null) {
            return McpToolResult::failure($validated['error']);
        }

        $payload = $validated['payload'];
        $timezone = $user->timezone ?: 'Asia/Jakarta';

        $attributes = [
            'title' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'status' => $payload['status'] ?? TaskStatus::Todo->value,
            'priority' => $payload['priority'] ?? TaskPriority::Medium->value,
            'project_id' => $payload['project_id'] ?? null,
            'parent_id' => $payload['parent_id'] ?? null,
            'due_at' => isset($payload['due_at']) && is_string($payload['due_at']) && $payload['due_at'] !== ''
                ? CarbonImmutable::parse($payload['due_at'], $timezone)->utc()
                : null,
        ];

        $task = $user->tasks()->create($attributes);
        $this->reminderSynchronizer->syncForTask($task);

        return McpToolResult::ok(
            data: [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'due_at_local' => $task->due_at !== null
                    ? CarbonImmutable::instance($task->due_at)->utc()->setTimezone($timezone)->format('Y-m-d H:i')
                    : null,
            ],
            targetType: 'task',
            targetId: $task->id,
            summary: sprintf('Tugas "%s" berhasil dibuat.', $task->title),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{payload: array<string, mixed>, error: string|null}
     */
    private function validate(User $user, array $arguments): array
    {
        $validator = Validator::make($arguments, [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'required', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TaskPriority::class)],
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at'),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')
                    ->where('user_id', $user->id)
                    ->whereNull('parent_id')
                    ->whereNull('deleted_at'),
            ],
            'due_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            $firstError = (string) collect($validator->errors()->all())->first();

            return ['payload' => [], 'error' => $firstError !== '' ? $firstError : 'Argumen tugas tidak valid.'];
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        $timezone = $user->timezone ?: 'Asia/Jakarta';

        $payload = [
            'title' => trim((string) $validated['title']),
            'priority' => (string) ($validated['priority'] ?? TaskPriority::Medium->value),
            'status' => (string) ($validated['status'] ?? TaskStatus::Todo->value),
        ];

        if (array_key_exists('description', $validated) && $validated['description'] !== null) {
            $payload['description'] = (string) $validated['description'];
        }

        if (isset($validated['project_id'])) {
            $payload['project_id'] = (int) $validated['project_id'];
        }

        if (isset($validated['parent_id'])) {
            $payload['parent_id'] = (int) $validated['parent_id'];
        }

        if (isset($validated['due_at']) && is_string($validated['due_at']) && trim($validated['due_at']) !== '') {
            $payload['due_at'] = CarbonImmutable::parse($validated['due_at'], $timezone)
                ->setTimezone($timezone)
                ->format('Y-m-d H:i');
        }

        return ['payload' => $payload, 'error' => null];
    }
}
