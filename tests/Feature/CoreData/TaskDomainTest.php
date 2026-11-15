<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

test('task policy enforces strict user ownership', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $task = Task::factory()->for($owner)->create();

    expect(Gate::forUser($owner)->allows('view', $task))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $task))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $task))->toBeTrue()
        ->and(Gate::forUser($intruder)->allows('view', $task))->toBeFalse()
        ->and(Gate::forUser($intruder)->allows('update', $task))->toBeFalse()
        ->and(Gate::forUser($intruder)->allows('delete', $task))->toBeFalse();
});

test('task can be created without project or with a project owned by the same user', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $standaloneTask = Task::factory()->for($user)->create(['project_id' => null]);
    $projectTask = Task::factory()->forProject($project)->create();

    expect($standaloneTask->project_id)->toBeNull()
        ->and($projectTask->project_id)->toBe($project->id);
});

test('cross-user task-project relationship is rejected', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $otherProject = Project::factory()->for($userB)->create();

    expect(fn () => Task::factory()->for($userA)->create([
        'project_id' => $otherProject->id,
    ]))->toThrow(ValidationException::class);
});

test('task status and active state follow business rules and synchronize completed_at', function () {
    $task = Task::factory()->create([
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::High,
    ]);

    expect($task->isActive())->toBeTrue()
        ->and($task->completed_at)->toBeNull();

    $task->update(['status' => TaskStatus::InProgress]);
    expect($task->refresh()->isActive())->toBeTrue()
        ->and($task->completed_at)->toBeNull();

    $task->update(['status' => TaskStatus::Completed]);
    expect($task->refresh()->isActive())->toBeFalse()
        ->and($task->completed_at)->not->toBeNull();

    $task->update(['status' => TaskStatus::Cancelled]);
    expect($task->refresh()->isActive())->toBeFalse()
        ->and($task->completed_at)->toBeNull();
});

test('subtask hierarchy enforces maximum one level of nesting and same-user ownership per D-09', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $rootTask = Task::factory()->for($user)->create();
    $subtask = Task::factory()->subtaskOf($rootTask)->create();

    expect($subtask->parent_id)->toBe($rootTask->id)
        ->and($rootTask->subtasks)->toHaveCount(1);

    // 2nd level nesting is rejected
    expect(fn () => Task::factory()->for($user)->create([
        'parent_id' => $subtask->id,
    ]))->toThrow(ValidationException::class);

    // Turning a parent with subtasks into a subtask is rejected
    $anotherRoot = Task::factory()->for($user)->create();
    expect(fn () => $rootTask->update([
        'parent_id' => $anotherRoot->id,
    ]))->toThrow(ValidationException::class);

    // Cross-user parent task is rejected
    $otherUserTask = Task::factory()->for($otherUser)->create();
    expect(fn () => Task::factory()->for($user)->create([
        'parent_id' => $otherUserTask->id,
    ]))->toThrow(ValidationException::class);
});

test('deadline is normalized to UTC and preserved when user timezone changes', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $request = StoreTaskRequest::create('/tasks', 'POST', [
        'title' => 'Submit Database Assignment',
        'priority' => TaskPriority::Urgent->value,
        'status' => TaskStatus::Todo->value,
        'due_at' => '2026-11-20 09:00:00',
    ]);
    $request->setUserResolver(fn () => $user);
    $request->setContainer(app());
    $request->validateResolved();

    $task = $user->tasks()->create($request->normalizedValidated());

    // 2026-11-20 09:00:00 Asia/Jakarta (UTC+7) === 2026-11-20 02:00:00 UTC
    expect($task->due_at)->not->toBeNull()
        ->and($task->due_at?->timezoneName)->toBe('UTC')
        ->and($task->due_at?->format('Y-m-d H:i:s'))->toBe('2026-11-20 02:00:00')
        ->and($task->dueAtInUserTimezone()?->format('Y-m-d H:i:s'))->toBe('2026-11-20 09:00:00');

    // Changing user timezone to Asia/Makassar (UTC+8) does not change UTC timestamp
    $user->update(['timezone' => 'Asia/Makassar']);
    $task->refresh()->load('user');

    expect($task->due_at?->format('Y-m-d H:i:s'))->toBe('2026-11-20 02:00:00')
        ->and($task->dueAtInUserTimezone()?->format('Y-m-d H:i:s'))->toBe('2026-11-20 10:00:00');
});

test('store task request validates status, priority, and cross-user references', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->for($otherUser)->create();

    $request = new StoreTaskRequest;
    $request->setUserResolver(fn () => $user);

    $validator = Validator::make([
        'title' => '',
        'status' => 'not-a-status',
        'priority' => 'not-a-priority',
        'project_id' => $otherProject->id,
    ], $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('title', 'status', 'priority', 'project_id');
});
