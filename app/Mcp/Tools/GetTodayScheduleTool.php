<?php

namespace App\Mcp\Tools;

use App\Mcp\Contracts\McpToolInterface;
use App\Mcp\McpToolResult;
use App\Models\User;
use App\Services\Calendar\FreeSlotCalculator;
use App\Services\Calendar\RecurrenceExpanderService;
use App\Services\Calendar\ScheduleConflictDetector;
use Carbon\CarbonImmutable;

class GetTodayScheduleTool implements McpToolInterface
{
    public function __construct(
        private RecurrenceExpanderService $recurrenceExpander,
        private ScheduleConflictDetector $conflictDetector,
        private FreeSlotCalculator $freeSlotCalculator,
    ) {}

    public function name(): string
    {
        return 'get_today_schedule';
    }

    public function description(): string
    {
        return 'Mengambil jadwal/agenda kalender pengguna untuk hari ini (termasuk agenda berulang dan penanda bentrok) beserta daftar slot waktu kosong hari ini.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function isMutation(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(User $user, array $arguments): McpToolResult
    {
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $todayLocal = CarbonImmutable::now($timezone);
        $dateKey = $todayLocal->format('Y-m-d');

        $events = $user->calendarEvents()
            ->forDisplayRange($todayLocal, $todayLocal)
            ->get();

        $this->recurrenceExpander->loadRelationsForRange($events, $todayLocal, $todayLocal);

        $occurrences = $this->conflictDetector->annotateConflicts(
            $this->recurrenceExpander->expand($events, $todayLocal, $todayLocal, $timezone),
        );

        $aiPrefs = $user->resolvedAiPreferences();
        $dayStart = $this->freeSlotCalculator->parseTimeToMinutes(
            $aiPrefs['preferred_study_start'],
            FreeSlotCalculator::DEFAULT_DAY_START_MINUTES,
        );
        $dayEnd = $this->freeSlotCalculator->parseTimeToMinutes(
            $aiPrefs['preferred_study_end'],
            FreeSlotCalculator::DEFAULT_DAY_END_MINUTES,
        );

        $freeSlots = $this->freeSlotCalculator->calculateForDate(
            occurrences: $occurrences,
            dateKey: $dateKey,
            dayStartMinutes: $dayStart,
            dayEndMinutes: $dayEnd,
        );

        return McpToolResult::ok([
            'timezone' => $timezone,
            'local_date' => $dateKey,
            'events_count' => count($occurrences),
            'events' => $occurrences,
            'free_slots' => $freeSlots,
        ]);
    }
}
