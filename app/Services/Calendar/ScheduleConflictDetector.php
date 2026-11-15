<?php

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;

class ScheduleConflictDetector
{
    /**
     * Annotate expanded occurrences in-place with `has_conflict` when timed intervals overlap.
     *
     * @param  array<int, array<string, mixed>>  $occurrences
     * @return array<int, array<string, mixed>>
     */
    public function annotateConflicts(array $occurrences): array
    {
        $count = count($occurrences);

        for ($i = 0; $i < $count; $i++) {
            if ($occurrences[$i]['is_all_day'] || empty($occurrences[$i]['starts_at']) || empty($occurrences[$i]['ends_at'])) {
                continue;
            }

            $aStart = CarbonImmutable::parse((string) $occurrences[$i]['starts_at']);
            $aEnd = CarbonImmutable::parse((string) $occurrences[$i]['ends_at']);

            for ($j = $i + 1; $j < $count; $j++) {
                if ($occurrences[$j]['is_all_day'] || empty($occurrences[$j]['starts_at']) || empty($occurrences[$j]['ends_at'])) {
                    continue;
                }

                $bStart = CarbonImmutable::parse((string) $occurrences[$j]['starts_at']);
                $bEnd = CarbonImmutable::parse((string) $occurrences[$j]['ends_at']);

                if ($aStart->lt($bEnd) && $aEnd->gt($bStart)) {
                    $occurrences[$i]['has_conflict'] = true;
                    $occurrences[$j]['has_conflict'] = true;
                }
            }
        }

        return $occurrences;
    }

    /**
     * Find occurrences that overlap a candidate timed interval.
     *
     * @param  array<int, array<string, mixed>>  $occurrences
     * @return array<int, array<string, mixed>>
     */
    public function findConflictsForInterval(
        CarbonImmutable $startsAtUtc,
        CarbonImmutable $endsAtUtc,
        array $occurrences,
        ?int $excludeEventId = null,
    ): array {
        $conflicts = [];

        foreach ($occurrences as $occ) {
            if ($occ['is_all_day'] || empty($occ['starts_at']) || empty($occ['ends_at'])) {
                continue;
            }

            if ($excludeEventId !== null && ((int) $occ['id'] === $excludeEventId || (int) $occ['series_id'] === $excludeEventId)) {
                continue;
            }

            $occStart = CarbonImmutable::parse((string) $occ['starts_at']);
            $occEnd = CarbonImmutable::parse((string) $occ['ends_at']);

            if ($startsAtUtc->lt($occEnd) && $endsAtUtc->gt($occStart)) {
                $conflicts[] = [
                    'id' => $occ['id'],
                    'title' => $occ['title'],
                    'starts_at_local' => $occ['starts_at_local'],
                    'ends_at_local' => $occ['ends_at_local'],
                ];
            }
        }

        return $conflicts;
    }
}
