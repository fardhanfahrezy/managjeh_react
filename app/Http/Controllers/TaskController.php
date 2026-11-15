<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Models\Task;
use App\Services\Reminder\ReminderSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function __construct(
        private ReminderSynchronizer $reminderSynchronizer,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $statusFilter = $request->string('status')->toString();
        $priorityFilter = $request->string('priority')->toString();
        $projectIdFilter = $request->integer('project_id');
        $dueDateFilter = $request->string('due_date')->toString();

        $validStatus = TaskStatus::tryFrom($statusFilter);
        $validPriority = TaskPriority::tryFrom($priorityFilter);
        $validDueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateFilter) === 1 ? $dueDateFilter : '';

        $tasks = $user->tasks()
            ->whereNull('parent_id')
            ->with([
                'project:id,name,status',
                'subtasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('created_at'),
            ])
            ->when($validStatus !== null, function (Builder $query) use ($validStatus): void {
                $query->where('status', $validStatus);
            })
            ->when($validPriority !== null, function (Builder $query) use ($validPriority): void {
                $query->where('priority', $validPriority);
            })
            ->when($projectIdFilter > 0, function (Builder $query) use ($projectIdFilter): void {
                $query->where('project_id', $projectIdFilter);
            })
            ->when($validDueDate !== '', function (Builder $query) use ($validDueDate, $user): void {
                $startUtc = CarbonImmutable::parse($validDueDate, $user->timezone)->startOfDay()->utc();
                $endUtc = CarbonImmutable::parse($validDueDate, $user->timezone)->endOfDay()->utc();
                $query->whereBetween('due_at', [$startUtc, $endUtc]);
            })
            ->orderBy('sort_order')
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->latest('created_at')
            ->get()
            ->map(fn (Task $task): array => $this->serializeTask($task, $user->timezone));

        $projects = $user->projects()
            ->select(['id', 'name', 'status'])
            ->orderBy('name')
            ->get();

        return Inertia::render('tasks/index', [
            'tasks' => $tasks,
            'projects' => $projects,
            'filters' => [
                'status' => $validStatus !== null ? $validStatus->value : '',
                'priority' => $validPriority !== null ? $validPriority->value : '',
                'project_id' => $projectIdFilter > 0 ? (string) $projectIdFilter : '',
                'due_date' => $validDueDate,
            ],
        ]);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        /** @var Task $task */
        $task = $request->user()->tasks()->create($request->normalizedValidated());
        $this->reminderSynchronizer->syncForTask($task);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Tugas berhasil ditambahkan.',
        ]);

        return back();
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        $task->update($request->normalizedValidated());
        $this->reminderSynchronizer->syncForTask($task);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Tugas berhasil diperbarui.',
        ]);

        return back();
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        Gate::authorize('delete', $task);

        $task->delete();
        $this->reminderSynchronizer->cancelForTask($task);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Tugas berhasil dihapus.',
        ]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTask(Task $task, string $timezone): array
    {
        return [
            'id' => $task->id,
            'user_id' => $task->user_id,
            'project_id' => $task->project_id,
            'parent_id' => $task->parent_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_at' => $task->due_at?->toISOString(),
            'due_at_local' => $task->dueAtInUserTimezone($timezone)?->format('Y-m-d\TH:i'),
            'completed_at' => $task->completed_at?->toISOString(),
            'sort_order' => $task->sort_order,
            'project' => $task->relationLoaded('project') && $task->project !== null ? [
                'id' => $task->project->id,
                'name' => $task->project->name,
                'status' => $task->project->status->value,
            ] : null,
            'subtasks' => $task->relationLoaded('subtasks')
                ? $task->subtasks->map(fn (Task $sub): array => $this->serializeTask($sub, $timezone))->values()->all()
                : [],
            'created_at' => $task->created_at?->toISOString(),
            'updated_at' => $task->updated_at?->toISOString(),
        ];
    }
}
