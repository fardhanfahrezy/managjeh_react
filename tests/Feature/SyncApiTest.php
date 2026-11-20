<?php

use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\SyncTombstone;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

test('sync push processes batch operations in dependency order and resolves UUID foreign keys', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $projectUuid = (string) Str::uuid();
    $parentTaskUuid = (string) Str::uuid();
    $subtaskUuid = (string) Str::uuid();
    $eventUuid = (string) Str::uuid();

    // Intentionally send child/event BEFORE parent/project to verify dependency sorting.
    $response = $this->actingAs($user)->postJson(route('sync.push'), [
        'operations' => [
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'calendar_event',
                'entity_uuid' => $eventUuid,
                'action' => 'upsert',
                'base_version' => 0,
                'changed_fields' => ['title', 'project_uuid', 'task_uuid', 'starts_at', 'ends_at', 'is_all_day'],
                'base_snapshot' => null,
                'payload' => [
                    'title' => 'Diskusi Proyek Offline',
                    'project_uuid' => $projectUuid,
                    'task_uuid' => $parentTaskUuid,
                    'starts_at' => now()->addHours(4)->toISOString(),
                    'ends_at' => now()->addHours(5)->toISOString(),
                    'is_all_day' => false,
                ],
            ],
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'task',
                'entity_uuid' => $subtaskUuid,
                'action' => 'upsert',
                'base_version' => 0,
                'changed_fields' => ['title', 'project_uuid', 'parent_uuid'],
                'base_snapshot' => null,
                'payload' => [
                    'title' => 'Subtugas Offline',
                    'project_uuid' => $projectUuid,
                    'parent_uuid' => $parentTaskUuid,
                    'status' => 'todo',
                    'priority' => 'medium',
                ],
            ],
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'task',
                'entity_uuid' => $parentTaskUuid,
                'action' => 'upsert',
                'base_version' => 0,
                'changed_fields' => ['title', 'project_uuid', 'due_at'],
                'base_snapshot' => null,
                'payload' => [
                    'title' => 'Tugas Utama Offline',
                    'project_uuid' => $projectUuid,
                    'status' => 'todo',
                    'priority' => 'high',
                    'due_at' => now()->addDays(2)->toISOString(),
                ],
            ],
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'project',
                'entity_uuid' => $projectUuid,
                'action' => 'upsert',
                'base_version' => 0,
                'changed_fields' => ['name', 'status'],
                'base_snapshot' => null,
                'payload' => [
                    'name' => 'Proyek Offline First',
                    'status' => 'active',
                ],
            ],
        ],
    ]);

    $response->assertOk();
    $results = $response->json('results');
    expect($results)->toHaveCount(4);
    foreach ($results as $item) {
        expect($item['status'])->toBe('applied');
    }

    $project = Project::query()->where('uuid', $projectUuid)->firstOrFail();
    $parentTask = Task::query()->where('uuid', $parentTaskUuid)->firstOrFail();
    $subtask = Task::query()->where('uuid', $subtaskUuid)->firstOrFail();
    $event = CalendarEvent::query()->where('uuid', $eventUuid)->firstOrFail();

    expect($parentTask->project_id)->toBe($project->id)
        ->and($subtask->parent_id)->toBe($parentTask->id)
        ->and($event->project_id)->toBe($project->id)
        ->and($event->task_id)->toBe($parentTask->id);

    // ReminderSynchronizer should automatically schedule reminders for synced tasks/events.
    expect(Reminder::query()->where('task_id', $parentTask->id)->count())->toBeGreaterThan(0);
    expect(Reminder::query()->where('calendar_event_id', $event->id)->count())->toBeGreaterThan(0);
});

test('sync push is idempotent for duplicate operation_id submissions', function () {
    $user = User::factory()->create();
    $operationId = (string) Str::uuid();
    $projectUuid = (string) Str::uuid();

    $payload = [
        'operations' => [
            [
                'operation_id' => $operationId,
                'entity_type' => 'project',
                'entity_uuid' => $projectUuid,
                'action' => 'upsert',
                'base_version' => 0,
                'changed_fields' => ['name'],
                'base_snapshot' => null,
                'payload' => ['name' => 'Idempotent Project', 'status' => 'active'],
            ],
        ],
    ];

    $first = $this->actingAs($user)->postJson(route('sync.push'), $payload)->assertOk();
    $second = $this->actingAs($user)->postJson(route('sync.push'), $payload)->assertOk();

    expect(Project::query()->where('uuid', $projectUuid)->count())->toBe(1)
        ->and($second->json('results.0.record.server_id'))
        ->toBe($first->json('results.0.record.server_id'));
});

test('sync push performs field-level auto-merge on non-colliding edits and detects conflicts on colliding fields', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Judul Awal',
        'description' => 'Deskripsi Awal',
        'priority' => 'medium',
        'status' => 'todo',
    ]);

    expect($task->version)->toBe(1);

    // Another device updates ONLY description on server -> version becomes 2.
    $task->update(['description' => 'Deskripsi Dari Perangkat B']);
    $task->refresh();
    expect($task->version)->toBe(2);

    // Device A (stale base_version=1) updates ONLY priority -> should AUTO-MERGE safely!
    $mergeResponse = $this->actingAs($user)->postJson(route('sync.push'), [
        'operations' => [
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'task',
                'entity_uuid' => $task->uuid,
                'action' => 'upsert',
                'base_version' => 1,
                'changed_fields' => ['priority'],
                'base_snapshot' => [
                    'title' => 'Judul Awal',
                    'description' => 'Deskripsi Awal',
                    'priority' => 'medium',
                    'status' => 'todo',
                ],
                'payload' => [
                    'title' => 'Judul Awal',
                    'description' => 'Deskripsi Awal',
                    'priority' => 'urgent',
                    'status' => 'todo',
                ],
            ],
        ],
    ])->assertOk();

    expect($mergeResponse->json('results.0.status'))->toBe('merged');
    $task->refresh();
    expect($task->description)->toBe('Deskripsi Dari Perangkat B')
        ->and($task->priority->value)->toBe('urgent')
        ->and($task->version)->toBe(3);

    // Now Device C (stale base_version=1) modifies description too -> real collision -> CONFLICT!
    $conflictResponse = $this->actingAs($user)->postJson(route('sync.push'), [
        'operations' => [
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'task',
                'entity_uuid' => $task->uuid,
                'action' => 'upsert',
                'base_version' => 1,
                'changed_fields' => ['description'],
                'base_snapshot' => ['description' => 'Deskripsi Awal'],
                'payload' => ['description' => 'Deskripsi Bentrok Perangkat C'],
            ],
        ],
    ])->assertOk();

    expect($conflictResponse->json('results.0.status'))->toBe('conflict')
        ->and($conflictResponse->json('results.0.conflict_reason'))->toBe('version_mismatch')
        ->and($conflictResponse->json('results.0.conflicting_fields'))->toContain('description');

    $task->refresh();
    expect($task->description)->toBe('Deskripsi Dari Perangkat B');

    // User resolves conflict choosing "Pertahankan Milik Saya" (force_overwrite=true).
    $resolveResponse = $this->actingAs($user)->postJson(route('sync.push'), [
        'operations' => [
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'task',
                'entity_uuid' => $task->uuid,
                'action' => 'upsert',
                'base_version' => 3,
                'changed_fields' => ['description'],
                'base_snapshot' => null,
                'payload' => ['description' => 'Deskripsi Bentrok Perangkat C'],
                'force_overwrite' => true,
            ],
        ],
    ])->assertOk();

    expect($resolveResponse->json('results.0.status'))->toBe('applied');
    expect($task->fresh()->description)->toBe('Deskripsi Bentrok Perangkat C');
});

test('sync enforces user ownership and rejects cross-user UUID manipulation', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $ownersProject = Project::factory()->for($owner)->create(['name' => 'Rahasia Owner']);

    $response = $this->actingAs($attacker)->postJson(route('sync.push'), [
        'operations' => [
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'project',
                'entity_uuid' => $ownersProject->uuid,
                'action' => 'upsert',
                'base_version' => 1,
                'changed_fields' => ['name'],
                'base_snapshot' => null,
                'payload' => ['name' => 'Hijacked', 'user_id' => $owner->id],
            ],
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'task',
                'entity_uuid' => (string) Str::uuid(),
                'action' => 'upsert',
                'base_version' => 0,
                'changed_fields' => ['title', 'project_uuid'],
                'base_snapshot' => null,
                'payload' => [
                    'title' => 'Cross-user FK task',
                    'project_uuid' => $ownersProject->uuid,
                    'status' => 'todo',
                    'priority' => 'low',
                ],
            ],
        ],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('rejected')
        ->and($response->json('results.1.status'))->toBe('rejected')
        ->and($ownersProject->fresh()->name)->toBe('Rahasia Owner');
});

test('sync push gracefully detaches purged project_uuid instead of blocking offline task sync', function () {
    $user = User::factory()->create();
    $taskUuid = (string) Str::uuid();
    $purgedProjectUuid = (string) Str::uuid();

    $response = $this->actingAs($user)->postJson(route('sync.push'), [
        'operations' => [
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'task',
                'entity_uuid' => $taskUuid,
                'action' => 'upsert',
                'base_version' => 0,
                'changed_fields' => ['title', 'project_uuid'],
                'base_snapshot' => null,
                'payload' => [
                    'title' => 'Task With Purged Project',
                    'project_uuid' => $purgedProjectUuid,
                    'status' => 'todo',
                    'priority' => 'medium',
                ],
            ],
        ],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('applied')
        ->and($response->json('results.0.record.project_id'))->toBeNull();
    expect(Task::query()->where('uuid', $taskUuid)->exists())->toBeTrue();
});

test('prune retention records tombstones before forceDelete and prevents zombie resurrection on sync', function () {
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['title' => 'Old Deleted Task']);
    $taskUuid = $task->uuid;

    $task->delete();
    Task::withTrashed()->whereKey($task->id)->update([
        'deleted_at' => now()->subDays(45),
    ]);

    $this->artisan('app:prune-retention-data')->assertSuccessful();

    expect(Task::withTrashed()->where('uuid', $taskUuid)->exists())->toBeFalse()
        ->and(SyncTombstone::query()->where('entity_uuid', $taskUuid)->exists())->toBeTrue();

    // Pull returns tombstone to long-offline clients.
    $pull = $this->actingAs($user)->getJson(route('sync.pull', [
        'since' => now()->subDays(60)->toISOString(),
    ]))->assertOk();

    $tombstoneUuids = collect($pull->json('tombstones'))->pluck('entity_uuid')->all();
    expect($tombstoneUuids)->toContain($taskUuid);

    // Offline client attempting to push edit to tombstoned record gets conflict (not zombie insert!).
    $push = $this->actingAs($user)->postJson(route('sync.push'), [
        'operations' => [
            [
                'operation_id' => (string) Str::uuid(),
                'entity_type' => 'task',
                'entity_uuid' => $taskUuid,
                'action' => 'upsert',
                'base_version' => 1,
                'changed_fields' => ['title'],
                'base_snapshot' => ['title' => 'Old Deleted Task'],
                'payload' => ['title' => 'Zombie Resurrection Attempt'],
            ],
        ],
    ])->assertOk();

    expect($push->json('results.0.status'))->toBe('conflict')
        ->and($push->json('results.0.conflict_reason'))->toBe('deleted_on_server')
        ->and(Task::withTrashed()->where('uuid', $taskUuid)->exists())->toBeFalse();
});

test('sync status returns lease metadata without exposing sensitive credentials', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Makassar',
        'whatsapp_number' => '6281234567890',
        'whatsapp_enabled' => true,
        'whatsapp_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson(route('sync.status'))->assertOk();

    $response->assertJson([
        'user_id' => $user->id,
        'timezone' => 'Asia/Makassar',
        'whatsapp_enabled' => true,
        'whatsapp_verified' => true,
        'lease_ttl_days' => 7,
    ]);

    expect($response->json('whatsapp_number_masked'))->toEndWith('7890')
        ->and($response->json('password'))->toBeNull();
});
