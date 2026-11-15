<?php

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('authenticated user can view projects index and filter by status', function () {
    $user = User::factory()->create();
    $active = Project::factory()->for($user)->create(['name' => 'Skripsi', 'status' => ProjectStatus::Active]);
    Project::factory()->for($user)->create(['name' => 'Arsip Lama', 'status' => ProjectStatus::Archived]);

    $this->actingAs($user)
        ->get(route('projects.index', ['status' => 'active']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->has('projects', 1)
            ->where('projects.0.id', $active->id)
            ->where('projects.0.name', 'Skripsi')
            ->where('filters.status', 'active')
        );
});

test('authenticated user can create, update, view, and soft delete a project', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('projects.store'), [
            'name' => 'Aplikasi Kampus',
            'description' => 'Project semester 6',
            'status' => 'active',
            'start_date' => '2026-11-01',
            'due_date' => '2026-12-01',
        ])
        ->assertRedirect(route('projects.index'));

    $project = Project::query()->where('user_id', $user->id)->where('name', 'Aplikasi Kampus')->firstOrFail();

    $task = Task::factory()->for($user)->for($project)->create(['title' => 'Buat ERD']);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.id', $project->id)
            ->has('project.tasks', 1)
            ->where('project.tasks.0.id', $task->id)
        );

    $this->actingAs($user)
        ->patch(route('projects.update', $project), [
            'name' => 'Aplikasi Kampus v2',
            'status' => 'completed',
        ])
        ->assertRedirect();

    expect($project->fresh()->name)->toBe('Aplikasi Kampus v2')
        ->and($project->fresh()->status)->toBe(ProjectStatus::Completed);

    $this->actingAs($user)
        ->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'));

    expect($project->fresh()->trashed())->toBeTrue()
        ->and($task->fresh()->trashed())->toBeFalse();
});

test('authenticated user can view, filter, create, update, and soft delete tasks with timezone normalization', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('tasks.store'), [
            'title' => 'Submit Laporan',
            'project_id' => $project->id,
            'priority' => TaskPriority::High->value,
            'status' => TaskStatus::Todo->value,
            'due_at' => '2026-11-20T14:00',
        ])
        ->assertRedirect();

    $task = Task::query()->where('user_id', $user->id)->where('title', 'Submit Laporan')->firstOrFail();

    // 14:00 Asia/Jakarta (UTC+7) === 07:00 UTC
    expect($task->due_at?->toIso8601String())->toBe('2026-11-20T07:00:00+00:00');

    $this->actingAs($user)
        ->get(route('tasks.index', ['priority' => 'high', 'project_id' => $project->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/index')
            ->has('tasks', 1)
            ->where('tasks.0.id', $task->id)
            ->where('tasks.0.due_at_local', '2026-11-20T14:00')
        );

    $this->actingAs($user)
        ->patch(route('tasks.update', $task), [
            'status' => TaskStatus::Completed->value,
        ])
        ->assertRedirect();

    expect($task->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($task->fresh()->completed_at)->not->toBeNull();

    $this->actingAs($user)
        ->delete(route('tasks.destroy', $task))
        ->assertRedirect();

    expect($task->fresh()->trashed())->toBeTrue();
});

test('user cannot view, update, or delete another user project or task', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $project = Project::factory()->for($owner)->create();
    $task = Task::factory()->for($owner)->for($project)->create();

    $this->actingAs($intruder)
        ->get(route('projects.show', $project))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->patch(route('projects.update', $project), ['name' => 'Hacked'])
        ->assertForbidden();

    $this->actingAs($intruder)
        ->delete(route('projects.destroy', $project))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->patch(route('tasks.update', $task), ['title' => 'Hacked'])
        ->assertForbidden();

    $this->actingAs($intruder)
        ->delete(route('tasks.destroy', $task))
        ->assertForbidden();
});

test('user can view timezones and update whatsapp and quiet hours preferences', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'whatsapp_number' => '+6281111111111',
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->has('timezones')
        );

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'Asia/Makassar',
            'whatsapp_number' => '+6282222222222',
            'whatsapp_enabled' => true,
            'reminder_quiet_start' => '23:30',
            'reminder_quiet_end' => '05:30',
        ])
        ->assertRedirect(route('profile.edit'));

    $fresh = $user->fresh();
    expect($fresh->timezone)->toBe('Asia/Makassar')
        ->and($fresh->whatsapp_number)->toBe('+6282222222222')
        ->and($fresh->whatsapp_verified_at)->toBeNull()
        ->and($fresh->whatsapp_enabled)->toBeTrue()
        ->and(substr((string) $fresh->reminder_quiet_start, 0, 5))->toBe('23:30')
        ->and(substr((string) $fresh->reminder_quiet_end, 0, 5))->toBe('05:30');
});
