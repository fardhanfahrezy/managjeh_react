<?php

use App\Enums\AiPlannerRunStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReminderStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\AiPlannerRun;
use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('prune retention data command purges old soft-deleted records and old logs while keeping recent ones', function () {
    $user = User::factory()->create();

    $oldProject = Project::create([
        'user_id' => $user->id,
        'name' => 'Old Deleted Project',
        'status' => ProjectStatus::Archived,
    ]);
    $oldProject->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

    $recentProject = Project::create([
        'user_id' => $user->id,
        'name' => 'Recent Deleted Project',
        'status' => ProjectStatus::Archived,
    ]);
    $recentProject->forceFill(['deleted_at' => now()->subDays(5)])->saveQuietly();

    $oldTask = Task::create([
        'user_id' => $user->id,
        'title' => 'Old Deleted Task',
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::Low,
    ]);
    $oldTask->forceFill(['deleted_at' => now()->subDays(40)])->saveQuietly();

    $recentTask = Task::create([
        'user_id' => $user->id,
        'title' => 'Recent Deleted Task',
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::Low,
    ]);
    $recentTask->forceFill(['deleted_at' => now()->subDays(10)])->saveQuietly();

    $oldEvent = CalendarEvent::create([
        'user_id' => $user->id,
        'title' => 'Old Deleted Event',
        'starts_at' => now()->subDays(40),
        'ends_at' => now()->subDays(40)->addHour(),
        'is_all_day' => false,
    ]);
    $oldEvent->forceFill(['deleted_at' => now()->subDays(35)])->saveQuietly();

    $recentEvent = CalendarEvent::create([
        'user_id' => $user->id,
        'title' => 'Recent Deleted Event',
        'starts_at' => now()->subDays(5),
        'ends_at' => now()->subDays(5)->addHour(),
        'is_all_day' => false,
    ]);
    $recentEvent->forceFill(['deleted_at' => now()->subDays(3)])->saveQuietly();

    $oldSentReminder = Reminder::create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'message' => 'Old sent reminder',
        'scheduled_at' => now()->subDays(35),
        'sent_at' => now()->subDays(35),
        'status' => ReminderStatus::Sent,
        'idempotency_key' => 'old-sent-reminder-1',
    ]);
    $oldSentReminder->forceFill(['updated_at' => now()->subDays(35)])->saveQuietly();

    $oldPendingReminder = Reminder::create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'message' => 'Future pending reminder',
        'scheduled_at' => now()->addDay(),
        'status' => ReminderStatus::Pending,
        'idempotency_key' => 'old-pending-reminder-1',
    ]);
    $oldPendingReminder->forceFill(['updated_at' => now()->subDays(35)])->saveQuietly();

    $oldAiRun = AiPlannerRun::create([
        'user_id' => $user->id,
        'planning_date' => now()->subDays(35)->toDateString(),
        'provider' => 'fallback',
        'model' => 'rule-based-v1',
        'status' => AiPlannerRunStatus::Success,
        'result' => ['priorities' => [], 'recommended_reminders' => [], 'warnings' => [], 'reasoning_summary' => 'Old'],
    ]);
    $oldAiRun->forceFill(['created_at' => now()->subDays(35)])->saveQuietly();

    $recentAiRun = AiPlannerRun::create([
        'user_id' => $user->id,
        'planning_date' => now()->toDateString(),
        'provider' => 'fallback',
        'model' => 'rule-based-v1',
        'status' => AiPlannerRunStatus::Success,
        'result' => ['priorities' => [], 'recommended_reminders' => [], 'warnings' => [], 'reasoning_summary' => 'New'],
    ]);

    $this->artisan('app:prune-retention-data')
        ->assertSuccessful();

    expect(Project::withTrashed()->find($oldProject->id))->toBeNull();
    expect(Project::withTrashed()->find($recentProject->id))->not->toBeNull();
    expect(Task::withTrashed()->find($oldTask->id))->toBeNull();
    expect(Task::withTrashed()->find($recentTask->id))->not->toBeNull();
    expect(CalendarEvent::withTrashed()->find($oldEvent->id))->toBeNull();
    expect(CalendarEvent::withTrashed()->find($recentEvent->id))->not->toBeNull();
    expect(Reminder::find($oldSentReminder->id))->toBeNull();
    expect(Reminder::find($oldPendingReminder->id))->not->toBeNull();
    expect(AiPlannerRun::find($oldAiRun->id))->toBeNull();
    expect(AiPlannerRun::find($recentAiRun->id))->not->toBeNull();
});

test('user can export their own productivity data as json without sensitive credentials or other users data', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $otherUser = User::factory()->create();

    $myProject = Project::create([
        'user_id' => $user->id,
        'name' => 'Skripsi Bab 4',
        'status' => ProjectStatus::Active,
    ]);
    Project::create([
        'user_id' => $otherUser->id,
        'name' => 'Other Secret Project',
        'status' => ProjectStatus::Active,
    ]);

    $myTask = Task::create([
        'user_id' => $user->id,
        'project_id' => $myProject->id,
        'title' => 'Revisi Daftar Pustaka',
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::High,
    ]);
    Task::create([
        'user_id' => $otherUser->id,
        'title' => 'Other Secret Task',
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::Low,
    ]);

    $response = $this->actingAs($user)->get(route('profile.export'));

    $response->assertOk();
    $disposition = (string) $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('attachment; filename="managjeh-export-');

    $data = $response->json();

    expect($data['user']['email'])->toBe($user->email)
        ->and($data['user'])->not->toHaveKey('password')
        ->and($data['user'])->not->toHaveKey('remember_token')
        ->and($data['projects'])->toHaveCount(1)
        ->and($data['projects'][0]['name'])->toBe('Skripsi Bab 4')
        ->and($data['tasks'])->toHaveCount(1)
        ->and($data['tasks'][0]['id'])->toBe($myTask->id);
});

test('whatsapp otp send endpoint enforces rate limiting', function () {
    $user = User::factory()->create([
        'whatsapp_number' => '+6281234567890',
        'whatsapp_verified_at' => null,
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)
            ->post(route('whatsapp.send-otp'))
            ->assertRedirect();
    }

    $this->actingAs($user)
        ->post(route('whatsapp.send-otp'))
        ->assertStatus(429);
});

test('ai planner generate endpoint enforces rate limiting', function () {
    Http::fake(['*' => Http::response([], 503)]);
    $this->freezeTime();

    $user = User::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($user)
            ->post(route('ai-planner.generate'))
            ->assertRedirect();
    }

    $this->actingAs($user)
        ->post(route('ai-planner.generate'))
        ->assertStatus(429);
});
