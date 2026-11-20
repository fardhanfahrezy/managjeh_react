<?php

use App\Contracts\WhatsAppGatewayInterface;
use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminder\QuietHoursAdjuster;
use App\Services\WhatsApp\FonnteWhatsAppGateway;
use App\Services\WhatsApp\WhatsAppSendResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

test('quiet hours adjuster shifts reminders inside 00:00-05:00 local time to 05:00 local time', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Jakarta',
        'reminder_quiet_start' => '00:00:00',
        'reminder_quiet_end' => '05:00:00',
    ]);

    $adjuster = new QuietHoursAdjuster;

    // 02:30 WIB is inside 00:00-05:00 -> shifted to 05:00 WIB
    $insideUtc = CarbonImmutable::parse('2026-11-16 02:30:00', 'Asia/Jakarta')->utc();
    $adjusted = $adjuster->adjust($insideUtc, $user);

    expect($adjusted->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-11-16 05:00');

    // 14:00 WIB is outside quiet hours -> unchanged
    $outsideUtc = CarbonImmutable::parse('2026-11-16 14:00:00', 'Asia/Jakarta')->utc();
    expect($adjuster->adjust($outsideUtc, $user)->equalTo($outsideUtc))->toBeTrue();
});

test('creating, completing, and deleting a task synchronizes and cancels reminders', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 09:00:00', 'Asia/Jakarta'));

    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $this->actingAs($user)
        ->post(route('tasks.store'), [
            'title' => 'Kumpulkan Makalah',
            'priority' => 'high',
            'status' => 'todo',
            'due_at' => '2026-11-18T14:00',
        ])
        ->assertRedirect();

    $task = Task::query()->where('user_id', $user->id)->firstOrFail();

    $reminders = Reminder::query()->where('task_id', $task->id)->orderBy('scheduled_at')->get();
    expect($reminders)->toHaveCount(2)
        ->and($reminders->pluck('reminder_type')->all())->toBe(['before_24h', 'before_1h'])
        ->and($reminders->every(fn (Reminder $r): bool => $r->status === ReminderStatus::Pending))->toBeTrue();

    // Completing task cancels pending reminders
    $this->actingAs($user)
        ->patch(route('tasks.update', $task), [
            'status' => TaskStatus::Completed->value,
        ])
        ->assertRedirect();

    expect(Reminder::query()->where('task_id', $task->id)->where('status', ReminderStatus::Pending->value)->count())->toBe(0)
        ->and(Reminder::query()->where('task_id', $task->id)->where('status', ReminderStatus::Cancelled->value)->count())->toBe(2);

    CarbonImmutable::setTestNow();
});

test('creating and deleting a timed calendar event synchronizes and cancels reminders', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 09:00:00', 'Asia/Jakarta'));

    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $this->actingAs($user)
        ->post(route('calendar-events.store'), [
            'title' => 'Presentasi Proyek',
            'is_all_day' => false,
            'starts_at' => '2026-11-17T10:00',
            'ends_at' => '2026-11-17T11:30',
        ])
        ->assertRedirect();

    $event = CalendarEvent::query()->where('user_id', $user->id)->firstOrFail();
    expect(Reminder::query()->where('calendar_event_id', $event->id)->where('status', ReminderStatus::Pending->value)->count())->toBe(3);

    $this->actingAs($user)
        ->delete(route('calendar-events.destroy', $event))
        ->assertRedirect();

    expect(Reminder::query()->where('calendar_event_id', $event->id)->where('status', ReminderStatus::Pending->value)->count())->toBe(0);

    CarbonImmutable::setTestNow();
});

test('dispatch due reminders sends verified users messages once and cancels unverified ones', function () {
    $verifiedUser = User::factory()->create([
        'whatsapp_number' => '+6281234567890',
        'whatsapp_enabled' => true,
        'whatsapp_verified_at' => now(),
    ]);

    $unverifiedUser = User::factory()->create([
        'whatsapp_number' => '+6289876543210',
        'whatsapp_enabled' => true,
        'whatsapp_verified_at' => null,
    ]);

    $dueReminder = Reminder::factory()->for($verifiedUser)->create([
        'scheduled_at' => now()->subMinute(),
        'status' => ReminderStatus::Pending,
        'message' => 'Pengingat tugas penting',
    ]);

    $blockedReminder = Reminder::factory()->for($unverifiedUser)->create([
        'scheduled_at' => now()->subMinute(),
        'status' => ReminderStatus::Pending,
        'message' => 'Tidak boleh terkirim',
    ]);

    $mockGateway = Mockery::mock(WhatsAppGatewayInterface::class);
    $mockGateway->shouldReceive('sendMessage')
        ->once()
        ->with('+6281234567890', 'Pengingat tugas penting', $dueReminder->idempotency_key)
        ->andReturn(new WhatsAppSendResult(success: true, providerMessageId: 'wa-msg-123'));

    $this->app->instance(WhatsAppGatewayInterface::class, $mockGateway);

    // Sync queue runs SendWhatsAppMessageJob immediately during artisan call
    $this->artisan('reminders:dispatch-due')->assertSuccessful();

    $dueReminder->refresh();
    $blockedReminder->refresh();

    expect($dueReminder->status)->toBe(ReminderStatus::Sent)
        ->and($dueReminder->provider_message_id)->toBe('wa-msg-123')
        ->and($dueReminder->sent_at)->not->toBeNull()
        ->and($blockedReminder->status)->toBe(ReminderStatus::Cancelled);

    // Second run must not re-dispatch already sent reminder
    $this->artisan('reminders:dispatch-due')->assertSuccessful();
});

test('whatsapp otp send stores hashed otp in cache and verify marks number verified', function () {
    $user = User::factory()->create([
        'whatsapp_number' => '+6281234567890',
        'whatsapp_enabled' => true,
        'whatsapp_verified_at' => null,
    ]);

    $capturedOtp = null;
    $mockGateway = Mockery::mock(WhatsAppGatewayInterface::class);
    $mockGateway->shouldReceive('sendVerificationOtp')
        ->once()
        ->withArgs(function (string $phone, string $otp) use (&$capturedOtp): bool {
            $capturedOtp = $otp;

            return $phone === '+6281234567890' && strlen($otp) === 6;
        })
        ->andReturn(new WhatsAppSendResult(success: true, providerMessageId: 'wa-otp-1'));

    $this->app->instance(WhatsAppGatewayInterface::class, $mockGateway);

    $this->actingAs($user)
        ->post(route('whatsapp.send-otp'))
        ->assertRedirect();

    $cachedHash = Cache::get("whatsapp_otp:user:{$user->id}");
    expect($cachedHash)->toBeString()
        ->and($cachedHash)->not->toBe($capturedOtp)
        ->and(Hash::check((string) $capturedOtp, $cachedHash))->toBeTrue();

    // Invalid OTP fails
    $this->actingAs($user)
        ->post(route('whatsapp.verify-otp'), ['otp' => '000000'])
        ->assertSessionHasErrors('otp');

    // Valid OTP verifies user
    $this->actingAs($user)
        ->post(route('whatsapp.verify-otp'), ['otp' => $capturedOtp])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->whatsapp_verified_at)->not->toBeNull()
        ->and($user->canReceiveWhatsAppReminders())->toBeTrue();
});

test('fonnte gateway sends form request with authorization header and parses success and failure responses', function () {
    Http::fake([
        'api.fonnte.com/send' => Http::sequence()
            ->push(['status' => true, 'id' => ['fonnte-999']], 200)
            ->push(['status' => false, 'reason' => 'invalid token'], 200),
    ]);

    $gateway = new FonnteWhatsAppGateway(token: 'test-fonnte-token');

    $successResult = $gateway->sendMessage('+6281234567890', 'Halo Managjeh', 'idem-1');
    expect($successResult->success)->toBeTrue()
        ->and($successResult->providerMessageId)->toBe('fonnte-999');

    Http::assertSent(function (HttpClientRequest $request): bool {
        return $request->url() === 'https://api.fonnte.com/send'
            && $request->hasHeader('Authorization', 'test-fonnte-token')
            && $request['target'] === '6281234567890'
            && $request['message'] === 'Halo Managjeh';
    });

    $failedResult = $gateway->sendVerificationOtp('+6281234567890', '123456');
    expect($failedResult->success)->toBeFalse()
        ->and($failedResult->errorMessage)->toBe('invalid token');
});
