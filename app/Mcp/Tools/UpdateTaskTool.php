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
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateTaskTool implements McpMutationToolInterface
{
    public function __construct(
        private ReminderSynchronizer $reminderSynchronizer,
    ) {}

    public function name(): string
    {
        return 'update_task';
    }

    public function description(): string
    {
        return 'Mengusulkan pembaruan pada tugas milik pengguna (judul, deskripsi, status, prioritas, tenggat, atau proyek). Perubahan tidak langsung disimpan sebelum disetujui pengguna.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'ID tugas milik pengguna yang ingin diperbarui (wajib).',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Judul baru tugas (maksimal 200 karakter).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Deskripsi baru tugas.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::cases()),
                    'description' => 'Status baru tugas (todo, in_progress, completed, cancelled).',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => array_map(fn (TaskPriority $p): string => $p->value, TaskPriority::cases()),
                    'description' => 'Prioritas baru tugas (urgent, high, medium, low).',
                ],
                'due_at' => [
                    'type' => 'string',
                    'description' => 'Tenggat waktu lokal baru format YYYY-MM-DD HH:MM atau ISO 8601 (kirim string kosong untuk menghapus tenggat).',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID proyek baru milik pengguna.',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'ID tugas induk baru milik pengguna (maksimal 1 tingkat).',
                ],
            ],
            'required' => ['task_id'],
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
        $resolved = $this->resolveAndValidate($user, $arguments);

        if ($resolved['error'] !== null || $resolved['task'] === null) {
            return [
                'valid' => false,
                'payload' => [],
                'conflict_preview' => [],
                'summary' => '',
                'error' => $resolved['error'] ?? 'Tugas tidak ditemukan.',
            ];
        }

        $task = $resolved['task'];
        $payload = $resolved['payload'];

        return [
            'valid' => true,
            'payload' => $payload,
            'conflict_preview' => [],
            'summary' => sprintf('Perbarui tugas: %s', $payload['title'] ?? $task->title),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(User $user, array $arguments): McpToolResult
    {
        $resolved = $this->resolveAndValidate($user, $arguments);

        if ($resolved['error'] !== null || $resolved['task'] === null) {
            return McpToolResult::failure($resolved['error'] ?? 'Tugas tidak ditemukan.');
        }

        $task = $resolved['task'];
        Gate::forUser($user)->authorize('update', $task);

        $payload = $resolved['payload'];
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $updates = $payload;
        unset($updates['task_id'], $updates['current_title']);

        if (array_key_exists('due_at', $updates)) {
            $updates['due_at'] = is_string($updates['due_at']) && $updates['due_at'] !== ''
                ? CarbonImmutable::parse($updates['due_at'], $timezone)->utc()
                : null;
        }

        $task->update($updates);
        $task->refresh();
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
            summary: sprintf('Tugas "%s" berhasil diperbarui.', $task->title),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{task: Task|null, payload: array<string, mixed>, error: string|null}
     */
    private function resolveAndValidate(User $user, array $arguments): array
    {
        $taskId = isset($arguments['task_id']) && is_numeric($arguments['task_id'])
            ? (int) $arguments['task_id']
            : 0;

        if ($taskId <= 0) {
            return ['task' => null, 'payload' => [], 'error' => 'Parameter task_id wajib diisi dengan angka valid.'];
        }

        $task = Task::find($taskId);

        if ($task === null || ! Gate::forUser($user)->allows('update', $task)) {
            return ['task' => null, 'payload' => [], 'error' => 'Tugas tidak ditemukan atau bukan milik Anda.'];
        }

        // Normalize empty string due_at to null so validation accepts clearing due_at.
        $input = $arguments;
        if (array_key_exists('due_at', $input) && is_string($input['due_at']) && trim($input['due_at']) === '') {
            $input['due_at'] = null;
        }

        $validator = Validator::make($input, [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TaskPriority::class)],
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('projects', 'id')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at'),
            ],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::notIn([$task->id]),
                Rule::exists('tasks', 'id')
                    ->where('user_id', $user->id)
                    ->whereNull('parent_id')
                    ->whereNull('deleted_at'),
                function (string $attribute, mixed $value, Closure $fail) use ($task): void {
                    if ($value !== null && $task->subtasks()->exists()) {
                        $fail('Tugas yang sudah memiliki subtugas tidak dapat dijadikan subtugas.');
                    }
                },
            ],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($validator->fails()) {
            $firstError = (string) collect($validator->errors()->all())->first();

            return ['task' => null, 'payload' => [], 'error' => $firstError !== '' ? $firstError : 'Data pembaruan tugas tidak valid.'];
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        if ($validated === []) {
            return ['task' => null, 'payload' => [], 'error' => 'Minimal satu perubahan field tugas harus disertakan.'];
        }

        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $payload = [
            'task_id' => $task->id,
            'current_title' => $task->title,
        ];

        foreach (['title', 'description', 'status', 'priority', 'project_id', 'parent_id'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = is_string($validated[$field]) && $field === 'title'
                    ? trim($validated[$field])
                    : $validated[$field];
            }
        }

        if (array_key_exists('due_at', $validated)) {
            $payload['due_at'] = is_string($validated['due_at']) && $validated['due_at'] !== ''
                ? CarbonImmutable::parse($validated['due_at'], $timezone)->setTimezone($timezone)->format('Y-m-d H:i')
                : null;
        }

        return ['task' => $task, 'payload' => $payload, 'error' => null];
    }
}
