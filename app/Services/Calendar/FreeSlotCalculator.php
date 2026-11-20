<?php

namespace App\Services\Calendar;

class FreeSlotCalculator
{
    public const int DEFAULT_DAY_START_MINUTES = 6 * 60;

    public const int DEFAULT_DAY_END_MINUTES = 22 * 60;

    /**
     * Calculate free time slots for a specific local date from expanded calendar occurrences.
     *
     * @param  array<int, array<string, mixed>>  $occurrences
     * @return array<int, array{start: string, end: string, minutes: int}>
     */
    public function calculateForDate(
        array $occurrences,
        string $dateKey,
        int $dayStartMinutes = self::DEFAULT_DAY_START_MINUTES,
        int $dayEndMinutes = self::DEFAULT_DAY_END_MINUTES,
        int $minSlotMinutes = 30,
        int $maxSlots = 5,
    ): array {
        if ($dayEndMinutes <= $dayStartMinutes) {
            $dayStartMinutes = self::DEFAULT_DAY_START_MINUTES;
            $dayEndMinutes = self::DEFAULT_DAY_END_MINUTES;
        }

        $busy = [];

        foreach ($occurrences as $event) {
            $isAllDay = (bool) ($event['is_all_day'] ?? false);
            $startsAtLocal = $event['starts_at_local'] ?? null;
            $endsAtLocal = $event['ends_at_local'] ?? null;

            if ($isAllDay || ! is_string($startsAtLocal) || ! is_string($endsAtLocal)) {
                continue;
            }

            if (! str_starts_with($startsAtLocal, $dateKey)) {
                continue;
            }

            $startMins = $this->isoLocalToMinutes($startsAtLocal);
            $endMins = str_starts_with($endsAtLocal, $dateKey)
                ? $this->isoLocalToMinutes($endsAtLocal)
                : 24 * 60;

            if ($endMins > $dayStartMinutes && $startMins < $dayEndMinutes) {
                $busy[] = [max($dayStartMinutes, $startMins), min($dayEndMinutes, $endMins)];
            }
        }

        usort($busy, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $slots = [];
        $cursor = $dayStartMinutes;

        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($busyStart - $cursor >= $minSlotMinutes) {
                $slots[] = [
                    'start' => $this->formatMinutes($cursor),
                    'end' => $this->formatMinutes($busyStart),
                    'minutes' => $busyStart - $cursor,
                ];
            }

            $cursor = max($cursor, $busyEnd);
        }

        if ($dayEndMinutes - $cursor >= $minSlotMinutes) {
            $slots[] = [
                'start' => $this->formatMinutes($cursor),
                'end' => $this->formatMinutes($dayEndMinutes),
                'minutes' => $dayEndMinutes - $cursor,
            ];
        }

        return array_slice($slots, 0, max(1, $maxSlots));
    }

    public function parseTimeToMinutes(?string $hhmm, int $fallbackMinutes): int
    {
        if ($hhmm === null || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($hhmm), $matches)) {
            return $fallbackMinutes;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    private function isoLocalToMinutes(string $localIso): int
    {
        $hour = (int) substr($localIso, 11, 2);
        $minute = (int) substr($localIso, 14, 2);

        return ($hour * 60) + $minute;
    }

    private function formatMinutes(int $totalMinutes): string
    {
        return sprintf('%02d:%02d', intdiv($totalMinutes, 60), $totalMinutes % 60);
    }
}
