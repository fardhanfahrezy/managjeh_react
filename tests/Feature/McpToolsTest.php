<?php

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mcp\Contracts\McpToolInterface;
use App\Mcp\McpToolExecutor;
use App\Mcp\McpToolRegistry;
use App\Mcp\McpToolResult;
use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

test('registry registers all 10 MVP 2 tools, formats provider schemas, and blocks user_id parameters', function () {
    $registry = app(McpToolRegistry::class);

    expect(array_keys($registry->all()))->toEqualCanonicalizing([
        'get_my_tasks',
        'get_upcoming_tasks',
        'get_today_schedule',
        'get_schedule_range',
        'get_my_projects',
        'get_daily_overview',
        'create_task',
        'update_task',
        'create_schedule',
        'update_schedule',
    ]);

    $geminiDeclarations = $registry->toGeminiFunctionDeclarations();
    $openAiTools = $registry->toOpenAiTools();

    expect($geminiDeclarations)->toHaveCount(10)
        ->and($openAiTools)->toHaveCount(10);

    // Zero-argument tool (get_today_schedule) omits empty parameters for Gemini compatibility.
    $todayGemini = collect($geminiDeclarations)->firstWhere('name', 'get_today_schedule');
    expect($todayGemini)->not->toHaveKey('parameters');

    $maliciousTool = new class implements McpToolInterface
    {
        public function name(): string
        {
            return 'bad_tool';
        }

        public function description(): string
        {
            return 'Attempts to expose user_id';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer'],
                ],
            ];
        }

        public function isMutation(): bool
        {
            return false;
        }

        public function execute(User $user, array $arguments): McpToolResult
        {
            return McpToolResult::ok([]);
        }
    };

    expect(fn () => (new McpToolRegistry)->register($maliciousTool))
        ->toThrow(InvalidArgumentException::class, 'user_id');
});

test('executor blocks mutation tools via executeRead and isolates user data even if user_id is injected', function () {
    $owner = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $other = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    Task::factory()->for($owner)->create(['title' => 'Tugas Milik Owner', 'priority' => TaskPriority::High]);
    Task::factory()->for($other)->create(['title' => 'Tugas Rahasia User Lain', 'priority' => TaskPriority::Urgent]);

    $executor = app(McpToolExecutor::class);

    $mutationAttempt = $executor->executeRead($owner, 'create_task', ['title' => 'Bypass']);
    expect($mutationAttempt->success)->toBeFalse()
        ->and($mutationAttempt->error)->toContain('persetujuan');

    // Injecting other user's ID must still strictly return only $owner's tasks.
    $readResult = $executor->executeRead($owner, 'get_my_tasks', ['user_id' => $other->id]);
    expect($readResult->success)->toBeTrue()
        ->and($readResult->data['count'])->toBe(1)
        ->and($readResult->data['tasks'][0]['title'])->toBe('Tugas Milik Owner');
});

test('read tools return accurate tasks, upcoming deadlines, projects, schedule, free slots, and sanitized daily overview', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'whatsapp_number' => '+6281234567890',
        'whatsapp_verified_at' => now(),
        'whatsapp_enabled' => true,
        'ai_preferences' => [
            'preferred_study_start' => '08:00',
            'preferred_study_end' => '20:00',
        ],
    ]);

    $project = Project::factory()->for($user)->create([
        'name' => 'Proyek Skripsi',
        'status' => ProjectStatus::Active,
    ]);

    $nowLocal = CarbonImmutable::now('Asia/Jakarta');

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Bab 1 Selesai',
        'status' => TaskStatus::Completed,
        'priority' => TaskPriority::Medium,
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Revisi Bab 2',
        'status' => TaskStatus::InProgress,
        'priority' => TaskPriority::Urgent,
        'due_at' => $nowLocal->addDays(2)->setTime(17, 0)->utc(),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Tugas Overdue',
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::High,
        'due_at' => $nowLocal->subDay()->setTime(12, 0)->utc(),
    ]);

    CalendarEvent::factory()->for($user)->create([
        'title' => 'Kuliah Sistem Terdistribusi',
        'is_all_day' => false,
        'starts_at' => $nowLocal->setTime(10, 0)->utc(),
        'ends_at' => $nowLocal->setTime(12, 0)->utc(),
    ]);

    $executor = app(McpToolExecutor::class);

    // 1. get_upcoming_tasks
    $upcoming = $executor->executeRead($user, 'get_upcoming_tasks', ['days' => 5]);
    expect($upcoming->success)->toBeTrue()
        ->and($upcoming->data['count'])->toBe(2)
        ->and($upcoming->data['overdue_count'])->toBe(1);

    // 2. get_my_projects
    $projects = $executor->executeRead($user, 'get_my_projects', ['status' => 'active']);
    expect($projects->success)->toBeTrue()
        ->and($projects->data['count'])->toBe(1)
        ->and($projects->data['projects'][0]['progress_percent'])->toBe(50);

    // 3. get_today_schedule
    $todaySchedule = $executor->executeRead($user, 'get_today_schedule', []);
    expect($todaySchedule->success)->toBeTrue()
        ->and($todaySchedule->data['events_count'])->toBe(1)
        ->and($todaySchedule->data['free_slots'])->toBe([
            ['start' => '08:00', 'end' => '10:00', 'minutes' => 120],
            ['start' => '12:00', 'end' => '20:00', 'minutes' => 480],
        ]);

    // 4. get_schedule_range (clamped to 14 days when > 14 requested)
    $startDate = $nowLocal->format('Y-m-d');
    $endDateTooFar = $nowLocal->addDays(20)->format('Y-m-d');
    $scheduleRange = $executor->executeRead($user, 'get_schedule_range', [
        'start_date' => $startDate,
        'end_date' => $endDateTooFar,
    ]);
    expect($scheduleRange->success)->toBeTrue()
        ->and($scheduleRange->data['range_clamped_to_14_days'])->toBeTrue()
        ->and($scheduleRange->data['free_slots_by_date'])->toHaveCount(14);

    // 5. get_daily_overview (must strip number_masked PII)
    $overview = $executor->executeRead($user, 'get_daily_overview', []);
    expect($overview->success)->toBeTrue()
        ->and($overview->data['whatsapp_status'])->not->toHaveKey('number_masked')
        ->and($overview->data['active_projects'])->toHaveCount(1);
});

test('create_task and update_task enforce preview dry-run, ownership, D-09 nesting limit, and reminder sync', function () {
    $owner = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $other = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $otherProject = Project::factory()->for($other)->create();
    $parentTask = Task::factory()->for($owner)->create(['title' => 'Induk']);
    $subtask = Task::factory()->for($owner)->create(['title' => 'Anak', 'parent_id' => $parentTask->id]);
    $otherTask = Task::factory()->for($other)->create(['title' => 'Milik Orang Lain']);

    $executor = app(McpToolExecutor::class);

    // Reject foreign project_id and 2-level nesting (D-09).
    $badProjectPreview = $executor->previewMutation($owner, 'create_task', [
        'title' => 'Coba Cross-User',
        'project_id' => $otherProject->id,
    ]);
    expect($badProjectPreview['valid'])->toBeFalse();

    $deepSubtaskPreview = $executor->previewMutation($owner, 'create_task', [
        'title' => 'Cucu Tidak Boleh',
        'parent_id' => $subtask->id,
    ]);
    expect($deepSubtaskPreview['valid'])->toBeFalse();

    // Valid dry-run preview does not write to database.
    $dueLocal = CarbonImmutable::now('Asia/Jakarta')->addDays(2)->setTime(23, 59)->format('Y-m-d H:i');
    $validPreview = $executor->previewMutation($owner, 'create_task', [
        'title' => 'Tugas Pemrograman Web',
        'priority' => 'high',
        'due_at' => $dueLocal,
    ]);

    expect($validPreview['valid'])->toBeTrue()
        ->and($validPreview['payload']['title'])->toBe('Tugas Pemrograman Web')
        ->and(Task::where('title', 'Tugas Pemrograman Web')->exists())->toBeFalse();

    // Approved execution creates task and schedules reminders.
    $created = $executor->executeApprovedMutation($owner, 'create_task', $validPreview['payload']);
    expect($created->success)->toBeTrue()
        ->and($created->targetType)->toBe('task')
        ->and($created->targetId)->not->toBeNull();

    $createdTask = Task::find($created->targetId);
    expect($createdTask)->not->toBeNull()
        ->and($createdTask?->user_id)->toBe($owner->id)
        ->and(Reminder::where('task_id', $created->targetId)->count())->toBeGreaterThan(0);

    // Update foreign task must fail on both preview and execution.
    expect($executor->previewMutation($owner, 'update_task', [
        'task_id' => $otherTask->id,
        'title' => 'Hacked',
    ])['valid'])->toBeFalse();

    expect($executor->executeApprovedMutation($owner, 'update_task', [
        'task_id' => $otherTask->id,
        'title' => 'Hacked',
    ])->success)->toBeFalse();

    // Update own task succeeds.
    $updated = $executor->executeApprovedMutation($owner, 'update_task', [
        'task_id' => $created->targetId,
        'status' => TaskStatus::Completed->value,
    ]);
    expect($updated->success)->toBeTrue()
        ->and($createdTask?->fresh()?->status)->toBe(TaskStatus::Completed);
});

test('create_schedule and update_schedule detect conflicts in dry-run preview, enforce ownership, and sync reminders', function () {
    $owner = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $other = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $tomorrowLocal = CarbonImmutable::now('Asia/Jakarta')->addDay();

    $existingEvent = CalendarEvent::factory()->for($owner)->create([
        'title' => 'Kuliah Basis Data',
        'is_all_day' => false,
        'starts_at' => $tomorrowLocal->setTime(13, 0)->utc(),
        'ends_at' => $tomorrowLocal->setTime(15, 0)->utc(),
    ]);

    $otherEvent = CalendarEvent::factory()->for($other)->create([
        'title' => 'Agenda Orang Lain',
        'is_all_day' => false,
        'starts_at' => $tomorrowLocal->setTime(9, 0)->utc(),
        'ends_at' => $tomorrowLocal->setTime(10, 0)->utc(),
    ]);

    $executor = app(McpToolExecutor::class);

    // Overlapping schedule preview surfaces conflict without writing to DB.
    $conflictPreview = $executor->previewMutation($owner, 'create_schedule', [
        'title' => 'Belajar Mandiri',
        'starts_at' => $tomorrowLocal->setTime(14, 0)->format('Y-m-d H:i'),
        'ends_at' => $tomorrowLocal->setTime(16, 0)->format('Y-m-d H:i'),
    ]);

    expect($conflictPreview['valid'])->toBeTrue()
        ->and($conflictPreview['conflict_preview'])->toHaveCount(1)
        ->and($conflictPreview['conflict_preview'][0]['title'])->toBe('Kuliah Basis Data')
        ->and(CalendarEvent::where('title', 'Belajar Mandiri')->exists())->toBeFalse();

    // Approved execution creates the event and syncs event_start reminder.
    $created = $executor->executeApprovedMutation($owner, 'create_schedule', [
        'title' => 'Sesi Belajar Malam',
        'starts_at' => $tomorrowLocal->setTime(19, 0)->format('Y-m-d H:i'),
        'ends_at' => $tomorrowLocal->setTime(21, 0)->format('Y-m-d H:i'),
    ]);

    expect($created->success)->toBeTrue()
        ->and($created->targetType)->toBe('calendar_event')
        ->and(Reminder::where('calendar_event_id', $created->targetId)->count())->toBeGreaterThan(0);

    // Updating existing event to its own time slot must not report self-conflict.
    $selfUpdatePreview = $executor->previewMutation($owner, 'update_schedule', [
        'event_id' => $existingEvent->id,
        'title' => 'Kuliah Basis Data Lanjut',
        'starts_at' => $tomorrowLocal->setTime(13, 30)->format('Y-m-d H:i'),
        'ends_at' => $tomorrowLocal->setTime(15, 0)->format('Y-m-d H:i'),
    ]);

    expect($selfUpdatePreview['valid'])->toBeTrue()
        ->and($selfUpdatePreview['conflict_preview'])->toBe([]);

    // Updating existing event to overlap Sesi Belajar Malam reports conflict.
    $overlapUpdatePreview = $executor->previewMutation($owner, 'update_schedule', [
        'event_id' => $existingEvent->id,
        'starts_at' => $tomorrowLocal->setTime(19, 30)->format('Y-m-d H:i'),
        'ends_at' => $tomorrowLocal->setTime(20, 30)->format('Y-m-d H:i'),
    ]);

    expect($overlapUpdatePreview['valid'])->toBeTrue()
        ->and($overlapUpdatePreview['conflict_preview'])->toHaveCount(1)
        ->and($overlapUpdatePreview['conflict_preview'][0]['title'])->toBe('Sesi Belajar Malam');

    // Cross-user update must fail.
    expect($executor->previewMutation($owner, 'update_schedule', [
        'event_id' => $otherEvent->id,
        'title' => 'Tidak Boleh',
    ])['valid'])->toBeFalse();

    // Approved update succeeds.
    $updated = $executor->executeApprovedMutation($owner, 'update_schedule', $selfUpdatePreview['payload']);
    expect($updated->success)->toBeTrue()
        ->and($existingEvent->fresh()?->title)->toBe('Kuliah Basis Data Lanjut');
});
