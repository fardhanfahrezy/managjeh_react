<?php

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard with aggregated sections', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 09:00:00', 'Asia/Jakarta'));

    $user = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'whatsapp_number' => '+6281234567890',
        'whatsapp_enabled' => true,
        'whatsapp_verified_at' => now(),
        'reminder_quiet_start' => '00:00:00',
        'reminder_quiet_end' => '05:00:00',
    ]);

    $otherUser = User::factory()->create();

    $activeProject = Project::factory()->for($user)->create([
        'name' => 'Skripsi Bab 3',
        'status' => ProjectStatus::Active,
    ]);

    // 1 completed today, 1 in_progress (today_tasks), 1 overdue (upcoming_tasks), 1 due in 3 days (upcoming_tasks)
    Task::factory()->for($user)->for($activeProject)->create([
        'title' => 'Selesai Hari Ini',
        'status' => TaskStatus::Completed,
        'completed_at' => CarbonImmutable::parse('2026-11-16 08:00:00', 'Asia/Jakarta')->utc(),
    ]);

    Task::factory()->for($user)->for($activeProject)->create([
        'title' => 'Kerjakan Bab 3',
        'status' => TaskStatus::InProgress,
        'priority' => TaskPriority::High,
        'due_at' => CarbonImmutable::parse('2026-11-16 17:00:00', 'Asia/Jakarta')->utc(),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Tugas Terlewat',
        'status' => TaskStatus::Todo,
        'due_at' => CarbonImmutable::parse('2026-11-14 23:00:00', 'Asia/Jakarta')->utc(),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'Deadline 3 Hari Lagi',
        'status' => TaskStatus::Todo,
        'due_at' => CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Jakarta')->utc(),
    ]);

    // Recurring event occurring today (2026-11-16 is Monday -> MO)
    CalendarEvent::factory()->for($user)->create([
        'title' => 'Kuliah Pagi Rutin',
        'starts_at' => CarbonImmutable::parse('2026-11-09 08:00:00', 'Asia/Jakarta')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Jakarta')->utc(),
        'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
    ]);

    // Another user's data must not appear
    Task::factory()->for($otherUser)->create([
        'title' => 'Rahasia User Lain',
        'status' => TaskStatus::InProgress,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('greeting', 'Selamat pagi')
            ->where('local_date', '2026-11-16')
            ->where('timezone', 'Asia/Jakarta')
            ->has('today_tasks', 1)
            ->where('today_tasks.0.title', 'Kerjakan Bab 3')
            ->has('upcoming_tasks', 2)
            ->where('upcoming_tasks.0.title', 'Tugas Terlewat')
            ->where('upcoming_tasks.0.is_overdue', true)
            ->where('upcoming_tasks.1.title', 'Deadline 3 Hari Lagi')
            ->where('upcoming_tasks.1.is_overdue', false)
            ->has('today_events', 1)
            ->where('today_events.0.title', 'Kuliah Pagi Rutin')
            ->has('active_projects', 1)
            ->where('active_projects.0.progress', 50)
            ->where('whatsapp_status.can_receive', true)
            ->where('whatsapp_status.number_masked', '**********7890')
            ->where('whatsapp_status.quiet_hours', '00:00–05:00')
            ->where('completed_summary.today_count', 1)
            ->where('completed_summary.last_7_days_count', 1)
            ->where('completed_summary.active_count', 3)
        );

    CarbonImmutable::setTestNow();
});
