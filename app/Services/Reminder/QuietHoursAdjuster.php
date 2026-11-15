<?php

namespace App\Services\Reminder;

use App\Models\User;
use Carbon\CarbonImmutable;

class QuietHoursAdjuster
{
    /**
     * Adjust a UTC timestamp so it does not fall inside the user's local quiet hours.
     */
    public function adjust(CarbonImmutable $scheduledAtUtc, User $user): CarbonImmutable
    {
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $startStr = $this->normalizeTime((string) ($user->reminder_quiet_start ?? '00:00:00'));
        $endStr = $this->normalizeTime((string) ($user->reminder_quiet_end ?? '05:00:00'));

        if ($startStr === $endStr) {
            return $scheduledAtUtc->utc();
        }

        $local = $scheduledAtUtc->setTimezone($timezone);
        $currentTime = $local->format('H:i:s');

        if ($startStr < $endStr) {
            if ($currentTime >= $startStr && $currentTime < $endStr) {
                return $local->setTimeFromTimeString($endStr)->utc();
            }

            return $scheduledAtUtc->utc();
        }

        // Overnight quiet window (e.g., 22:00 - 05:00)
        if ($currentTime >= $startStr) {
            return $local->addDay()->setTimeFromTimeString($endStr)->utc();
        }

        if ($currentTime < $endStr) {
            return $local->setTimeFromTimeString($endStr)->utc();
        }

        return $scheduledAtUtc->utc();
    }

    private function normalizeTime(string $time): string
    {
        if (strlen($time) === 5) {
            return $time.':00';
        }

        return substr($time, 0, 8);
    }
}
