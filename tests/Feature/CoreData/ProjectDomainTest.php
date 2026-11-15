<?php

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

test('project policy enforces strict user ownership', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $project = Project::factory()->for($owner)->create();

    expect(Gate::forUser($owner)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $project))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $project))->toBeTrue()
        ->and(Gate::forUser($intruder)->allows('view', $project))->toBeFalse()
        ->and(Gate::forUser($intruder)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($intruder)->allows('delete', $project))->toBeFalse();
});

test('store project request validates required name, status enum, and date range', function () {
    $request = new StoreProjectRequest;

    $invalidValidator = Validator::make([
        'name' => '',
        'status' => 'invalid_status',
        'start_date' => '2026-11-20',
        'due_date' => '2026-11-10',
    ], $request->rules());

    $request->replace(['start_date' => '2026-11-20']);
    $dateRangeValidator = Validator::make([
        'name' => 'Valid Name',
        'start_date' => '2026-11-20',
        'due_date' => '2026-11-10',
    ], $request->rules());

    expect($invalidValidator->fails())->toBeTrue()
        ->and($invalidValidator->errors()->has('name'))->toBeTrue()
        ->and($invalidValidator->errors()->has('status'))->toBeTrue()
        ->and($dateRangeValidator->fails())->toBeTrue()
        ->and($dateRangeValidator->errors()->has('due_date'))->toBeTrue();
});

test('project progress is 0% when project has no tasks and excludes cancelled tasks', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    expect($project->calculateProgress())->toBe(0);

    Task::factory()->count(2)->forProject($project)->completed()->create();
    Task::factory()->count(2)->forProject($project)->create(['status' => TaskStatus::Todo]);
    Task::factory()->count(1)->forProject($project)->cancelled()->create();

    expect($project->calculateProgress())->toBe(50);

    $projectWithCounts = Project::query()->withProgressCounts()->findOrFail($project->id);
    expect($projectWithCounts->calculateProgress())->toBe(50);

    $projectWithRelation = Project::query()->with('tasks')->findOrFail($project->id);
    expect($projectWithRelation->calculateProgress())->toBe(50);
});

test('completing a project sets completed_at and does not automatically complete its tasks', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);
    $task = Task::factory()->forProject($project)->create(['status' => TaskStatus::Todo]);

    $project->update(['status' => ProjectStatus::Completed]);

    expect($project->refresh()->completed_at)->not->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::Todo);

    $project->update(['status' => ProjectStatus::Active]);
    expect($project->refresh()->completed_at)->toBeNull();
});

test('deleting a project preserves tasks history', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->forProject($project)->create();

    $project->delete();

    expect($project->fresh()?->trashed())->toBeTrue()
        ->and($task->fresh())->not->toBeNull()
        ->and($task->fresh()?->trashed())->toBeFalse();

    $project->forceDelete();

    expect($task->fresh())->not->toBeNull()
        ->and($task->fresh()?->project_id)->toBeNull();
});
