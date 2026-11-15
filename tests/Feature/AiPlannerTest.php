<?php

use App\Enums\AiPlannerRunStatus;
use App\Enums\ReminderStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\AiPlannerRun;
use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\AiPlanner\AiPlannerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('ai planner falls back from gemini to grok to openrouter and stores validated result', function () {
    config([
        'services.gemini.api_key' => 'test-gemini-key',
        'services.grok.api_key' => 'test-grok-key',
        'services.openrouter.api_key' => 'test-openrouter-key',
    ]);

    $user = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'email' => 'rahasia@kampus.ac.id',
        'whatsapp_number' => '+6281234567890',
    ]);

    $task1 = Task::factory()->for($user)->create([
        'title' => 'Submit Tugas Akhir',
        'priority' => TaskPriority::Urgent,
        'status' => TaskStatus::Todo,
    ]);

    $openRouterJson = json_encode([
        'summary' => 'Prioritaskan pengumpulan tugas akhir hari ini.',
        'priorities' => [
            [
                'task_id' => $task1->id,
                'reason' => 'Urgent priority.',
                'suggested_action' => 'Finalisasi PDF dan unggah.',
                'estimated_minutes' => 60,
            ],
            [
                // Hallucinated task_id must be filtered out by validator
                'task_id' => 999999,
                'reason' => 'Halusinasi.',
                'suggested_action' => 'Abaikan.',
                'estimated_minutes' => 30,
            ],
        ],
        'recommended_reminders' => [
            [
                'task_id' => $task1->id,
                'remind_at_local' => now('Asia/Jakarta')->format('Y-m-d').' 09:00:00',
                'reason' => 'Slot kosong pagi.',
            ],
        ],
        'warnings' => [],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'server error'], 500),
        'api.x.ai/*' => Http::response(['error' => 'rate limited'], 429),
        'openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['content' => $openRouterJson]],
            ],
        ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('ai-planner.generate'))
        ->assertRedirect();

    $run = AiPlannerRun::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

    expect($run->status)->toBe(AiPlannerRunStatus::Success)
        ->and($run->provider)->toBe('openrouter')
        ->and($run->result['priorities'])->toHaveCount(1)
        ->and($run->result['priorities'][0]['task_id'])->toBe($task1->id);

    // D-15: sanitized input_snapshot must not contain email or whatsapp_number
    $snapshotJson = json_encode($run->input_snapshot, JSON_THROW_ON_ERROR);
    expect($snapshotJson)->not->toContain('rahasia@kampus.ac.id')
        ->and($snapshotJson)->not->toContain('+6281234567890');
});

test('circuit breaker skips gemini after 3 consecutive failures', function () {
    Cache::flush();

    config([
        'services.gemini.api_key' => 'test-gemini-key',
        'services.grok.api_key' => '',
        'services.openrouter.api_key' => '',
    ]);

    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    Task::factory()->for($user)->create(['title' => 'Latihan UTS', 'priority' => TaskPriority::High]);

    $geminiCalls = 0;
    Http::fake([
        'generativelanguage.googleapis.com/*' => function () use (&$geminiCalls) {
            $geminiCalls++;

            return Http::response(['error' => 'down'], 503);
        },
    ]);

    $service = app(AiPlannerService::class);

    // 3 failures -> each falls back to deterministic fallback
    for ($i = 0; $i < 3; $i++) {
        $run = $service->generateForUser($user, 'manual');
        expect($run->provider)->toBe('fallback');
    }

    expect($geminiCalls)->toBe(3);

    // 4th call must skip Gemini because circuit breaker is open
    $fourthRun = $service->generateForUser($user, 'manual');
    expect($fourthRun->provider)->toBe('fallback')
        ->and($geminiCalls)->toBe(3);
});

test('deterministic fallback scores tasks and detects calendar conflicts', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 08:00:00', 'Asia/Jakarta'));

    config([
        'services.gemini.api_key' => '',
        'services.grok.api_key' => '',
        'services.openrouter.api_key' => '',
    ]);

    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $lowTask = Task::factory()->for($user)->create([
        'title' => 'Rapikan Catatan',
        'priority' => TaskPriority::Low,
        'status' => TaskStatus::Todo,
    ]);

    $urgentTask = Task::factory()->for($user)->create([
        'title' => 'Kumpulkan Laporan Praktikum',
        'priority' => TaskPriority::Urgent,
        'status' => TaskStatus::Todo,
        'due_at' => CarbonImmutable::parse('2026-11-16 17:00:00', 'Asia/Jakarta')->utc(),
    ]);

    // Overlapping calendar events today
    CalendarEvent::factory()->for($user)->create([
        'title' => 'Kuliah A',
        'starts_at' => CarbonImmutable::parse('2026-11-16 09:00:00', 'Asia/Jakarta')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-16 11:00:00', 'Asia/Jakarta')->utc(),
    ]);
    CalendarEvent::factory()->for($user)->create([
        'title' => 'Rapat B',
        'starts_at' => CarbonImmutable::parse('2026-11-16 10:00:00', 'Asia/Jakarta')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-16 11:30:00', 'Asia/Jakarta')->utc(),
    ]);

    $this->actingAs($user)
        ->post(route('ai-planner.generate'))
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('ai-planner.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ai-planner/index')
            ->where('latest_run.provider', 'fallback')
            ->where('latest_run.result.priorities.0.task_id', $urgentTask->id)
            ->where('latest_run.result.warnings.0.type', 'schedule_conflict')
        );

    // Dashboard also displays today's AI priorities
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ai_priorities.provider', 'fallback')
            ->where('ai_priorities.priorities.0.task_id', $urgentTask->id)
        );

    expect($lowTask->id)->not->toBe($urgentTask->id);

    CarbonImmutable::setTestNow();
});

test('user can approve ai recommended reminder with quiet hours adjustment and cannot approve another users task', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'reminder_quiet_start' => '00:00:00',
        'reminder_quiet_end' => '05:00:00',
    ]);
    $otherUser = User::factory()->create();

    $myTask = Task::factory()->for($user)->create(['title' => 'Baca Jurnal']);
    $otherTask = Task::factory()->for($otherUser)->create(['title' => 'Milik Orang Lain']);

    // 02:30 WIB falls in quiet hours -> shifted to 05:00 WIB (22:00 UTC previous day)
    $this->actingAs($user)
        ->post(route('ai-planner.approve-reminder'), [
            'task_id' => $myTask->id,
            'remind_at_local' => '2026-11-17 02:30:00',
            'reason' => 'Slot fokus pagi.',
        ])
        ->assertRedirect();

    $reminder = Reminder::query()->where('task_id', $myTask->id)->firstOrFail();

    expect($reminder->reminder_type)->toBe('ai_recommended')
        ->and($reminder->status)->toBe(ReminderStatus::Pending)
        ->and($reminder->scheduled_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-11-17 05:00');

    $this->actingAs($user)
        ->post(route('ai-planner.approve-reminder'), [
            'task_id' => $otherTask->id,
            'remind_at_local' => '2026-11-17 09:00:00',
        ])
        ->assertSessionHasErrors('task_id');
});
