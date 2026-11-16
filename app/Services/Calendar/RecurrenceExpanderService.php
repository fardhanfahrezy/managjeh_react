<?php

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class RecurrenceExpanderService
{
    private const array DAY_MAP = [
        'SU' => 0,
        'MO' => 1,
        'TU' => 2,
        'WE' => 3,
        'TH' => 4,
        'FR' => 5,
        'SA' => 6,
    ];

    /**
     * Conditionally eager-load only the relations actually referenced by the filtered events.
     *
     * @param  EloquentCollection<int, CalendarEvent>  $events
     */
    public function loadRelationsForRange(
        EloquentCollection $events,
        CarbonImmutable $rangeStartLocal,
        CarbonImmutable $rangeEndLocal,
    ): void {
        if ($events->isEmpty()) {
            return;
        }

        if ($events->contains(fn (CalendarEvent $event): bool => $event->project_id !== null)) {
            $events->load('project:id,name');
        }

        if ($events->contains(fn (CalendarEvent $event): bool => $event->task_id !== null)) {
            $events->load('task:id,title,status');
        }

        $hasRecurring = $events->contains(
            fn (CalendarEvent $event): bool => $event->recurrence_rule !== null && $event->recurrence_rule !== '',
        );

        if ($hasRecurring) {
            $rangeStartDate = $rangeStartLocal->format('Y-m-d');
            $rangeEndDate = $rangeEndLocal->format('Y-m-d');

            $events->load([
                'overrides' => fn ($query) => $query
                    ->whereBetween('recurrence_id', [$rangeStartDate, $rangeEndDate])
                    ->with(['project:id,name', 'task:id,title,status']),
            ]);
        }
    }

    /**
     * Expand standalone and recurring events into concrete local-timezone occurrences within [rangeStartLocal, rangeEndLocal].
     *
     * @param  Collection<int, CalendarEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    public function expand(
        Collection $events,
        CarbonImmutable $rangeStartLocal,
        CarbonImmutable $rangeEndLocal,
        string $timezone,
    ): array {
        $occurrences = [];
        $rangeStartDay = $rangeStartLocal->startOfDay();
        $rangeEndDay = $rangeEndLocal->endOfDay();

        foreach ($events as $event) {
            if ($event->parent_event_id !== null || $event->is_cancelled) {
                continue;
            }

            if ($event->recurrence_rule === null || $event->recurrence_rule === '') {
                if ($this->overlapsRange($event, $rangeStartDay, $rangeEndDay, $timezone)) {
                    $occurrences[] = $this->formatOccurrence($event, $event, null, false, $timezone);
                }

                continue;
            }

            foreach ($this->expandRecurringEvent($event, $rangeStartDay, $rangeEndDay, $timezone) as $item) {
                $occurrences[] = $item;
            }
        }

        usort($occurrences, function (array $a, array $b): int {
            $aKey = ($a['is_all_day'] ? ($a['start_date'].'T00:00') : ($a['starts_at_local'] ?? ''));
            $bKey = ($b['is_all_day'] ? ($b['start_date'].'T00:00') : ($b['starts_at_local'] ?? ''));

            return $aKey <=> $bKey;
        });

        return $occurrences;
    }

    private function overlapsRange(
        CalendarEvent $event,
        CarbonImmutable $rangeStartLocal,
        CarbonImmutable $rangeEndLocal,
        string $timezone,
    ): bool {
        if ($event->is_all_day) {
            if ($event->start_date === null) {
                return false;
            }

            $eventStart = $event->start_date->format('Y-m-d');
            $eventEnd = ($event->end_date ?? $event->start_date)->format('Y-m-d');

            return $eventStart <= $rangeEndLocal->format('Y-m-d')
                && $eventEnd >= $rangeStartLocal->format('Y-m-d');
        }

        if ($event->starts_at === null || $event->ends_at === null) {
            return false;
        }

        $rangeStartUtc = $rangeStartLocal->utc();
        $rangeEndUtc = $rangeEndLocal->utc();

        return $event->starts_at->lte($rangeEndUtc) && $event->ends_at->gte($rangeStartUtc);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expandRecurringEvent(
        CalendarEvent $master,
        CarbonImmutable $rangeStartLocal,
        CarbonImmutable $rangeEndLocal,
        string $timezone,
    ): array {
        $parsed = $this->parseRrule((string) $master->recurrence_rule);
        if ($parsed === null) {
            return [];
        }

        $anchorLocal = $this->getAnchorLocalDate($master, $timezone);
        if ($anchorLocal === null) {
            return [];
        }

        $effectiveEnd = $rangeEndLocal->startOfDay();
        if ($master->recurrence_until !== null) {
            $untilLocal = CarbonImmutable::parse($master->recurrence_until->format('Y-m-d'), $timezone)->startOfDay();
            if ($untilLocal->lt($effectiveEnd)) {
                $effectiveEnd = $untilLocal;
            }
        }

        if ($effectiveEnd->lt($anchorLocal->startOfDay())) {
            return [];
        }

        /** @var array<int, string> $exceptions */
        $exceptions = $master->recurrence_exceptions ?? [];
        $overridesByRecurrenceId = $master->relationLoaded('overrides')
            ? $master->overrides->keyBy('recurrence_id')
            : collect();

        $results = [];
        $candidateDates = $this->generateCandidateDates(
            $anchorLocal,
            $rangeStartLocal->startOfDay(),
            $effectiveEnd,
            $parsed['freq'],
            $parsed['interval'],
            $parsed['byday'],
        );

        foreach ($candidateDates as $occurrenceDate) {
            $recurrenceId = $occurrenceDate->format('Y-m-d');

            if (in_array($recurrenceId, $exceptions, true)) {
                continue;
            }

            /** @var CalendarEvent|null $override */
            $override = $overridesByRecurrenceId->get($recurrenceId);
            if ($override !== null) {
                if ($override->is_cancelled) {
                    continue;
                }

                $results[] = $this->formatOccurrence($master, $override, $recurrenceId, true, $timezone);

                continue;
            }

            $results[] = $this->formatShiftedOccurrence($master, $occurrenceDate, $recurrenceId, $timezone);
        }

        return $results;
    }

    private function getAnchorLocalDate(CalendarEvent $event, string $timezone): ?CarbonImmutable
    {
        if ($event->is_all_day) {
            return $event->start_date !== null
                ? CarbonImmutable::parse($event->start_date->format('Y-m-d'), $timezone)
                : null;
        }

        return $event->starts_at?->setTimezone($timezone);
    }

    /**
     * @return array{freq: string, interval: int, byday: array<int, int>}|null
     */
    private function parseRrule(string $rrule): ?array
    {
        $parts = explode(';', strtoupper(trim($rrule)));
        $freq = null;
        $interval = 1;
        $byday = [];

        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);

            if ($key === 'FREQ' && in_array($value, ['DAILY', 'WEEKLY', 'MONTHLY'], true)) {
                $freq = $value;
            } elseif ($key === 'INTERVAL' && ctype_digit($value) && (int) $value >= 1) {
                $interval = (int) $value;
            } elseif ($key === 'BYDAY') {
                foreach (explode(',', $value) as $dayCode) {
                    if (isset(self::DAY_MAP[$dayCode])) {
                        $byday[] = self::DAY_MAP[$dayCode];
                    }
                }
            }
        }

        if ($freq === null) {
            return null;
        }

        return [
            'freq' => $freq,
            'interval' => $interval,
            'byday' => array_values(array_unique($byday)),
        ];
    }

    /**
     * @param  array<int, int>  $byday
     * @return array<int, CarbonImmutable>
     */
    private function generateCandidateDates(
        CarbonImmutable $anchor,
        CarbonImmutable $rangeStart,
        CarbonImmutable $effectiveEnd,
        string $freq,
        int $interval,
        array $byday,
    ): array {
        $dates = [];
        $anchorDay = $anchor->startOfDay();
        $maxIterations = 400;
        $iterations = 0;

        if ($freq === 'DAILY') {
            $cursor = $anchorDay;
            while ($cursor->lte($effectiveEnd) && $iterations < $maxIterations) {
                if ($cursor->gte($rangeStart)) {
                    $dates[] = $cursor;
                }
                $cursor = $cursor->addDays($interval);
                $iterations++;
            }

            return $dates;
        }

        if ($freq === 'WEEKLY') {
            $targetWeekdays = $byday !== [] ? $byday : [$anchorDay->dayOfWeek];
            $weekStart = $anchorDay->startOfWeek(CarbonImmutable::MONDAY);

            while ($weekStart->lte($effectiveEnd) && $iterations < $maxIterations) {
                for ($offset = 0; $offset < 7; $offset++) {
                    $day = $weekStart->addDays($offset);
                    if ($day->lt($anchorDay) || $day->gt($effectiveEnd)) {
                        continue;
                    }
                    if ($day->gte($rangeStart) && in_array($day->dayOfWeek, $targetWeekdays, true)) {
                        $dates[] = $day;
                    }
                }
                $weekStart = $weekStart->addWeeks($interval);
                $iterations++;
            }

            return $dates;
        }

        // MONTHLY
        $step = 0;
        while ($iterations < $maxIterations) {
            $candidate = $anchorDay->addMonthsNoOverflow($step * $interval);
            if ($candidate->gt($effectiveEnd)) {
                break;
            }
            if ($candidate->gte($rangeStart)) {
                $dates[] = $candidate;
            }
            $step++;
            $iterations++;
        }

        return $dates;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatShiftedOccurrence(
        CalendarEvent $master,
        CarbonImmutable $occurrenceDateLocal,
        string $recurrenceId,
        string $timezone,
    ): array {
        $base = $this->formatOccurrence($master, $master, $recurrenceId, false, $timezone);

        if ($master->is_all_day && $master->start_date !== null) {
            $spanDays = (int) $master->start_date->diffInDays($master->end_date ?? $master->start_date);
            $base['start_date'] = $occurrenceDateLocal->format('Y-m-d');
            $base['end_date'] = $occurrenceDateLocal->addDays($spanDays)->format('Y-m-d');

            return $base;
        }

        if ($master->starts_at !== null && $master->ends_at !== null) {
            $anchorLocalStart = $master->starts_at->setTimezone($timezone);
            $durationSeconds = (int) $master->starts_at->diffInSeconds($master->ends_at);
            $occStartLocal = $occurrenceDateLocal->setTime(
                $anchorLocalStart->hour,
                $anchorLocalStart->minute,
                $anchorLocalStart->second,
            );
            $occEndLocal = $occStartLocal->addSeconds($durationSeconds);

            $base['starts_at'] = $occStartLocal->utc()->toISOString();
            $base['ends_at'] = $occEndLocal->utc()->toISOString();
            $base['starts_at_local'] = $occStartLocal->format('Y-m-d\TH:i');
            $base['ends_at_local'] = $occEndLocal->format('Y-m-d\TH:i');
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOccurrence(
        CalendarEvent $master,
        CalendarEvent $source,
        ?string $recurrenceId,
        bool $isOverride,
        string $timezone,
    ): array {
        $startsAtLocal = $source->starts_at?->setTimezone($timezone);
        $endsAtLocal = $source->ends_at?->setTimezone($timezone);

        return [
            'id' => $source->id,
            'series_id' => $master->id,
            'occurrence_key' => $recurrenceId !== null ? "{$master->id}:{$recurrenceId}" : (string) $source->id,
            'user_id' => $source->user_id,
            'project_id' => $source->project_id,
            'task_id' => $source->task_id,
            'parent_event_id' => $source->parent_event_id,
            'recurrence_id' => $recurrenceId,
            'is_recurring' => $master->recurrence_rule !== null && $master->recurrence_rule !== '',
            'is_override' => $isOverride,
            'title' => $source->title,
            'description' => $source->description,
            'starts_at' => $source->starts_at?->toISOString(),
            'ends_at' => $source->ends_at?->toISOString(),
            'starts_at_local' => $startsAtLocal?->format('Y-m-d\TH:i'),
            'ends_at_local' => $endsAtLocal?->format('Y-m-d\TH:i'),
            'start_date' => $source->start_date?->format('Y-m-d'),
            'end_date' => $source->end_date?->format('Y-m-d'),
            'is_all_day' => $source->is_all_day,
            'is_cancelled' => $source->is_cancelled,
            'recurrence_rule' => $master->recurrence_rule,
            'recurrence_until' => $master->recurrence_until?->format('Y-m-d'),
            'project' => $source->relationLoaded('project') && $source->project !== null ? [
                'id' => $source->project->id,
                'name' => $source->project->name,
            ] : null,
            'task' => $source->relationLoaded('task') && $source->task !== null ? [
                'id' => $source->task->id,
                'title' => $source->task->title,
                'status' => $source->task->status->value,
            ] : null,
            'has_conflict' => false,
        ];
    }
}
