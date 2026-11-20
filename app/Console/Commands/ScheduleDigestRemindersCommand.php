<?php

namespace App\Console\Commands;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminder\DigestMessageBuilder;
use App\Services\Reminder\QuietHoursAdjuster;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ScheduleDigestRemindersCommand extends Command
{
    protected $signature = 'reminders:schedule-digests';

    protected $description = 'Schedule idempotent daily and weekly deterministic digest reminders based on user local time preferences';

    public function handle(
        DigestMessageBuilder $digestBuilder,
        QuietHoursAdjuster $quietHoursAdjuster,
    ): int {
        $scheduled = 0;

        User::query()
            ->where('whatsapp_enabled', true)
            ->whereNotNull('whatsapp_number')
            ->whereNotNull('whatsapp_verified_at')
            ->chunkById(100, function (Collection $users) use ($digestBuilder, $quietHoursAdjuster, &$scheduled): void {
                foreach ($users as $user) {
                    $scheduled += $this->scheduleForUser($user, $digestBuilder, $quietHoursAdjuster);
                }
            });

        $this->info(sprintf('Scheduled %d digest reminder(s).', $scheduled));

        return self::SUCCESS;
    }

    private function scheduleForUser(
        User $user,
        DigestMessageBuilder $digestBuilder,
        QuietHoursAdjuster $quietHoursAdjuster,
    ): int {
        if (! $user->canReceiveWhatsAppReminders()) {
            return 0;
        }

        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $nowLocal = CarbonImmutable::now($timezone);
        $prefs = $user->resolvedNotificationPreferences();
        $count = 0;

        // 1. Daily summary digest
        if ($prefs['daily_summary_enabled']) {
            $dailyKey = sprintf('daily_summary:%d:%s', $user->id, $nowLocal->format('Y-m-d'));
            $dailyTargetLocal = $this->parseTodayTime($nowLocal, $prefs['daily_summary_time']);

            if ($nowLocal->gte($dailyTargetLocal) && ! Reminder::query()->where('idempotency_key', $dailyKey)->exists()) {
                $scheduledAtUtc = $quietHoursAdjuster->adjust($nowLocal->utc(), $user);

                Reminder::query()->create([
                    'user_id' => $user->id,
                    'task_id' => null,
                    'calendar_event_id' => null,
                    'channel' => 'whatsapp',
                    'reminder_type' => 'daily_summary',
                    'scheduled_at' => $scheduledAtUtc,
                    'status' => ReminderStatus::Pending,
                    'message' => $digestBuilder->buildDailySummary($user),
                    'idempotency_key' => $dailyKey,
                ]);
                $count++;
            }
        }

        // 2. Weekly summary digest
        if ($prefs['weekly_summary_enabled'] && $nowLocal->dayOfWeek === $prefs['weekly_summary_day']) {
            $weeklyKey = sprintf('weekly_summary:%d:%s', $user->id, $nowLocal->format('o-W'));
            $weeklyTargetLocal = $this->parseTodayTime($nowLocal, $prefs['weekly_summary_time']);

            if ($nowLocal->gte($weeklyTargetLocal) && ! Reminder::query()->where('idempotency_key', $weeklyKey)->exists()) {
                $scheduledAtUtc = $quietHoursAdjuster->adjust($nowLocal->utc(), $user);

                Reminder::query()->create([
                    'user_id' => $user->id,
                    'task_id' => null,
                    'calendar_event_id' => null,
                    'channel' => 'whatsapp',
                    'reminder_type' => 'weekly_summary',
                    'scheduled_at' => $scheduledAtUtc,
                    'status' => ReminderStatus::Pending,
                    'message' => $digestBuilder->buildWeeklySummary($user),
                    'idempotency_key' => $weeklyKey,
                ]);
                $count++;
            }
        }

        return $count;
    }

    private function parseTodayTime(CarbonImmutable $nowLocal, string $hhmm): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $hhmm));

        return $nowLocal->setTime($hour, $minute, 0);
    }
}
