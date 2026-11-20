<?php

use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifications\OutboundChannelManager;
use App\Services\Reminder\ReminderSynchronizer;
use App\Services\WhatsApp\MetaCloudWhatsAppGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;

test('meta cloud whatsapp gateway sends graph api json payload and handles success and error responses', function () {
    Http::fake([
        'https://graph.facebook.com/v21.0/1029384756/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.HBgLNDIz']]], 200)
            ->push(['error' => ['message' => 'Invalid OAuth access token.']], 401),
    ]);

    $gateway = new MetaCloudWhatsAppGateway(
        phoneNumberId: '1029384756',
        accessToken: 'meta-secret-token',
        apiVersion: 'v21.0',
    );

    $ok = $gateway->sendMessage('+6281234567890', 'Pengingat Managjeh', 'idem-meta-1');
    expect($ok->success)->toBeTrue()
        ->and($ok->providerMessageId)->toBe('wamid.HBgLNDIz');

    Http::assertSent(function (HttpClientRequest $request): bool {
        return $request->url() === 'https://graph.facebook.com/v21.0/1029384756/messages'
            && $request->hasHeader('Authorization', 'Bearer meta-secret-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '6281234567890'
            && $request['text']['body'] === 'Pengingat Managjeh';
    });

    $fail = $gateway->sendVerificationOtp('+6281234567890', '654321');
    expect($fail->success)->toBeFalse()
        ->and($fail->errorMessage)->toBe('Invalid OAuth access token.');
});

test('outbound channel manager resolves whatsapp and safely cancels unregistered channels', function () {
    $manager = app(OutboundChannelManager::class);
    expect($manager->registeredChannels())->toContain('whatsapp')
        ->and($manager->resolve('whatsapp'))->not->toBeNull()
        ->and($manager->resolve('telegram'))->toBeNull();

    $user = User::factory()->create([
        'whatsapp_number' => '+6281234567890',
        'whatsapp_enabled' => true,
        'whatsapp_verified_at' => now(),
    ]);

    $reminder = Reminder::factory()->for($user)->create([
        'channel' => 'telegram',
        'status' => ReminderStatus::Processing,
        'scheduled_at' => now()->subMinute(),
    ]);

    $job = new SendWhatsAppMessageJob($reminder->id);
    $job->handle($manager);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Cancelled)
        ->and($reminder->failure_reason)->toContain('telegram');
});

test('calendar event reminder sync respects event_prep_enabled preference', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 08:00:00', 'Asia/Jakarta'));

    $user = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'notification_preferences' => [
            'event_prep_enabled' => false,
        ],
    ]);

    $event = CalendarEvent::factory()->for($user)->create([
        'title' => 'Rapat Himpunan',
        'is_all_day' => false,
        'starts_at' => CarbonImmutable::parse('2026-11-20 14:00:00', 'Asia/Jakarta')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-20 15:00:00', 'Asia/Jakarta')->utc(),
    ]);

    app(ReminderSynchronizer::class)->syncForCalendarEvent($event);

    $types = Reminder::query()
        ->where('calendar_event_id', $event->id)
        ->where('status', ReminderStatus::Pending->value)
        ->orderBy('scheduled_at')
        ->pluck('reminder_type')
        ->all();

    expect($types)->toBe(['before_1h', 'event_start']);

    // Enable event_prep and resync -> now includes event_prep (H-15m).
    $user->update(['notification_preferences' => ['event_prep_enabled' => true]]);
    $event->refresh();

    app(ReminderSynchronizer::class)->syncForCalendarEvent($event);

    $typesEnabled = Reminder::query()
        ->where('calendar_event_id', $event->id)
        ->where('status', ReminderStatus::Pending->value)
        ->orderBy('scheduled_at')
        ->pluck('reminder_type')
        ->all();

    expect($typesEnabled)->toBe(['before_1h', 'event_prep', 'event_start']);

    CarbonImmutable::setTestNow();
});

test('reminders:sync-recurring schedules deterministic reminders for recurring events within 48 hours without duplicates', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 08:00:00', 'Asia/Jakarta'));

    $user = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'whatsapp_number' => '+6281234567890',
        'whatsapp_enabled' => true,
        'whatsapp_verified_at' => now(),
    ]);

    $event = CalendarEvent::factory()->for($user)->create([
        'title' => 'Kuliah Algoritma',
        'is_all_day' => false,
        'starts_at' => CarbonImmutable::parse('2026-11-18 10:00:00', 'Asia/Jakarta')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-18 12:00:00', 'Asia/Jakarta')->utc(),
        'recurrence_rule' => 'FREQ=DAILY',
        'recurrence_until' => '2026-11-30',
    ]);

    $this->artisan('reminders:sync-recurring')->assertSuccessful();

    // Occurrences inside 48h window from 2026-11-20 08:00: 2026-11-20 10:00 and 2026-11-21 10:00 -> 2 * 3 = 6 reminders.
    $firstRunCount = Reminder::query()->where('user_id', $user->id)->count();
    expect($firstRunCount)->toBe(6);

    $expectedKey = sprintf('event:%d:2026-11-21:event_prep', $event->id);
    expect(Reminder::query()->where('idempotency_key', $expectedKey)->exists())->toBeTrue();

    // Second run must be idempotent.
    $this->artisan('reminders:sync-recurring')->assertSuccessful();
    expect(Reminder::query()->where('user_id', $user->id)->count())->toBe($firstRunCount);

    CarbonImmutable::setTestNow();
});

test('reminders:schedule-digests schedules idempotent deterministic daily and weekly summaries for verified users only', function () {
    // Sunday 2026-11-22 19:15 WIB (dayOfWeek === 0 -> Sunday)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 19:15:00', 'Asia/Jakarta'));

    $verifiedUser = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'whatsapp_number' => '+6281234567890',
        'whatsapp_enabled' => true,
        'whatsapp_verified_at' => now(),
        'notification_preferences' => [
            'daily_summary_enabled' => true,
            'daily_summary_time' => '07:00',
            'weekly_summary_enabled' => true,
            'weekly_summary_day' => 0,
            'weekly_summary_time' => '19:00',
            'event_prep_enabled' => true,
        ],
    ]);

    $unverifiedUser = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'whatsapp_number' => '+6289876543210',
        'whatsapp_enabled' => true,
        'whatsapp_verified_at' => null,
    ]);

    $project = Project::factory()->for($verifiedUser)->create(['name' => 'Proyek Basis Data']);
    Task::factory()->for($verifiedUser)->for($project)->create([
        'title' => 'Normalisasi Skema',
        'status' => TaskStatus::Completed,
        'completed_at' => now()->subDays(2),
    ]);
    Task::factory()->for($verifiedUser)->for($project)->create([
        'title' => 'Submit SQL Final',
        'status' => TaskStatus::Todo,
        'due_at' => CarbonImmutable::parse('2026-11-22 23:00:00', 'Asia/Jakarta')->utc(),
    ]);

    $this->artisan('reminders:schedule-digests')->assertSuccessful();

    $verifiedReminders = Reminder::query()
        ->where('user_id', $verifiedUser->id)
        ->orderBy('reminder_type')
        ->get();

    expect($verifiedReminders)->toHaveCount(2)
        ->and($verifiedReminders->pluck('reminder_type')->all())->toBe(['daily_summary', 'weekly_summary'])
        ->and($verifiedReminders[0]->message)->toContain('Ringkasan Harian Managjeh')
        ->and($verifiedReminders[0]->message)->toContain('Submit SQL Final')
        ->and($verifiedReminders[1]->message)->toContain('Evaluasi & Ringkasan Mingguan Managjeh')
        ->and($verifiedReminders[1]->message)->toContain('Proyek Basis Data')
        ->and(Reminder::query()->where('user_id', $unverifiedUser->id)->count())->toBe(0);

    // Running again 15 minutes later must not create duplicates.
    $this->artisan('reminders:schedule-digests')->assertSuccessful();
    expect(Reminder::query()->where('user_id', $verifiedUser->id)->count())->toBe(2);

    CarbonImmutable::setTestNow();
});
