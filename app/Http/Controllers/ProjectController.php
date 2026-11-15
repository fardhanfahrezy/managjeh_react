<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $statusFilter = $request->string('status')->toString();
        $validStatus = ProjectStatus::tryFrom($statusFilter);

        $projects = $user->projects()
            ->withProgressCounts()
            ->when($validStatus !== null, function (Builder $query) use ($validStatus): void {
                $query->where('status', $validStatus);
            })
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest('created_at')
            ->get()
            ->map(fn (Project $project): array => $this->serializeProject($project));

        return Inertia::render('projects/index', [
            'projects' => $projects,
            'filters' => [
                'status' => $validStatus !== null ? $validStatus->value : '',
            ],
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $request->user()->projects()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Project berhasil dibuat.',
        ]);

        return to_route('projects.index');
    }

    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $user = $request->user();

        $project->loadCount([
            'tasks as completed_tasks_count' => fn (Builder $query) => $query->where('status', TaskStatus::Completed),
            'tasks as non_cancelled_tasks_count' => fn (Builder $query) => $query->where('status', '!=', TaskStatus::Cancelled),
        ]);

        $project->load([
            'tasks' => fn ($query) => $query
                ->whereNull('parent_id')
                ->with(['subtasks' => fn ($sub) => $sub->orderBy('sort_order')->orderBy('created_at')])
                ->orderBy('sort_order')
                ->orderByRaw('due_at IS NULL')
                ->orderBy('due_at')
                ->latest('created_at'),
        ]);

        $serializedProject = $this->serializeProject($project);
        $serializedProject['tasks'] = $project->tasks
            ->map(fn (Task $task): array => $this->serializeTask($task, $user->timezone))
            ->values()
            ->all();

        $allProjects = $user->projects()
            ->select(['id', 'name', 'status'])
            ->orderBy('name')
            ->get();

        return Inertia::render('projects/show', [
            'project' => $serializedProject,
            'projects' => $allProjects,
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Project berhasil diperbarui.',
        ]);

        return back();
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Project berhasil dihapus.',
        ]);

        return to_route('projects.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'user_id' => $project->user_id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status->value,
            'start_date' => $project->start_date?->format('Y-m-d'),
            'due_date' => $project->due_date?->format('Y-m-d'),
            'completed_at' => $project->completed_at?->toISOString(),
            'progress' => $project->calculateProgress(),
            'completed_tasks_count' => (int) ($project->completed_tasks_count ?? 0),
            'non_cancelled_tasks_count' => (int) ($project->non_cancelled_tasks_count ?? 0),
            'created_at' => $project->created_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
        ];
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
            'subtasks' => $task->relationLoaded('subtasks')
                ? $task->subtasks->map(fn (Task $sub): array => $this->serializeTask($sub, $timezone))->values()->all()
                : [],
            'created_at' => $task->created_at?->toISOString(),
            'updated_at' => $task->updated_at?->toISOString(),
        ];
    }
}
