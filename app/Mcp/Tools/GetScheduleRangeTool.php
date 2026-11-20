<?php

namespace App\Mcp\Tools;

use App\Mcp\Contracts\McpToolInterface;
use App\Mcp\McpToolResult;
use App\Models\User;
use App\Services\Calendar\FreeSlotCalculator;
use App\Services\Calendar\RecurrenceExpanderService;
use App\Services\Calendar\ScheduleConflictDetector;
use Carbon\CarbonImmutable;
use Throwable;

class GetScheduleRangeTool implements McpToolInterface
{
    private const int MAX_DAYS = 14;

    public function __construct(
        private RecurrenceExpanderService $recurrenceExpander,
        private ScheduleConflictDetector $conflictDetector,
        private FreeSlotCalculator $freeSlotCalculator,
    ) {}

    public function name(): string
    {
        return 'get_schedule_range';
    }

    public function description(): string
    {
        return 'Mengambil jadwal/agenda kalender pengguna beserta slot waktu kosong per hari untuk rentang tanggal tertentu (default 7 hari mulai hari ini, maksimal 14 hari). Cocok untuk menyusun jadwal belajar mingguan.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Tanggal mulai format YYYY-MM-DD (zona waktu pengguna). Default hari ini.',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'Tanggal akhir format YYYY-MM-DD (zona waktu pengguna). Default 6 hari setelah start_date (rentang maksimal 14 hari).',
                ],
            ],
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
        $todayLocal = CarbonImmutable::now($timezone)->startOfDay();

        $startLocal = $todayLocal;
        if (isset($arguments['start_date']) && is_string($arguments['start_date']) && trim($arguments['start_date']) !== '') {
            $parsed = $this->parseDate(trim($arguments['start_date']), $timezone);
            if ($parsed === null) {
                return McpToolResult::failure('Format start_date tidak valid. Gunakan format YYYY-MM-DD.');
            }
            $startLocal = $parsed;
        }

        $endLocal = $startLocal->addDays(6);
        if (isset($arguments['end_date']) && is_string($arguments['end_date']) && trim($arguments['end_date']) !== '') {
            $parsed = $this->parseDate(trim($arguments['end_date']), $timezone);
            if ($parsed === null) {
                return McpToolResult::failure('Format end_date tidak valid. Gunakan format YYYY-MM-DD.');
            }
            $endLocal = $parsed;
        }

        if ($endLocal->lt($startLocal)) {
            return McpToolResult::failure('Tanggal end_date harus sama dengan atau setelah start_date.');
        }

        $clamped = false;
        $maxEndLocal = $startLocal->addDays(self::MAX_DAYS - 1);
        if ($endLocal->gt($maxEndLocal)) {
            $endLocal = $maxEndLocal;
            $clamped = true;
        }

        $events = $user->calendarEvents()
            ->forDisplayRange($startLocal, $endLocal)
            ->get();

        $this->recurrenceExpander->loadRelationsForRange($events, $startLocal, $endLocal);

        $occurrences = $this->conflictDetector->annotateConflicts(
            $this->recurrenceExpander->expand($events, $startLocal, $endLocal, $timezone),
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

        $freeSlotsByDate = [];
        $cursor = $startLocal;
        while ($cursor->lte($endLocal)) {
            $dateKey = $cursor->format('Y-m-d');
            $freeSlotsByDate[$dateKey] = $this->freeSlotCalculator->calculateForDate(
                occurrences: $occurrences,
                dateKey: $dateKey,
                dayStartMinutes: $dayStart,
                dayEndMinutes: $dayEnd,
            );
            $cursor = $cursor->addDay();
        }

        return McpToolResult::ok([
            'timezone' => $timezone,
            'start_date' => $startLocal->format('Y-m-d'),
            'end_date' => $endLocal->format('Y-m-d'),
            'range_clamped_to_14_days' => $clamped,
            'events_count' => count($occurrences),
            'events' => $occurrences,
            'free_slots_by_date' => $freeSlotsByDate,
        ]);
    }

    private function parseDate(string $value, string $timezone): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            return $date?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
