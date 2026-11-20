<?php

namespace App\Services\Reminder;

use App\Enums\TaskStatus;
use App\Models\User;
use App\Services\DashboardAggregatorService;
use Carbon\CarbonImmutable;

class DigestMessageBuilder
{
    public function __construct(
        private DashboardAggregatorService $dashboardAggregator,
    ) {}

    /**
     * Build a deterministic (rule-based, zero-LLM) daily morning summary for outbound WhatsApp.
     */
    public function buildDailySummary(User $user): string
    {
        $overview = $this->dashboardAggregator->aggregate($user);
        $dateLabel = (string) ($overview['local_date_label'] ?? '');
        $aiChatUrl = rtrim((string) config('app.url', 'http://localhost:8000'), '/').'/ai-chat';

        /** @var array<int, array<string, mixed>> $todayTasks */
        $todayTasks = is_array($overview['today_tasks'] ?? null) ? $overview['today_tasks'] : [];
        /** @var array<int, array<string, mixed>> $todayEvents */
        $todayEvents = is_array($overview['today_events'] ?? null) ? $overview['today_events'] : [];
        /** @var array<string, mixed>|null $aiPriorities */
        $aiPriorities = is_array($overview['ai_priorities'] ?? null) ? $overview['ai_priorities'] : null;

        $lines = [
            "Ringkasan Harian Managjeh — {$dateLabel}",
        ];

        $overdueCount = count(array_filter($todayTasks, fn (array $task): bool => (bool) ($task['is_overdue'] ?? false)));
        $taskHeader = sprintf(
            'Tugas Hari Ini & Terlambat: %d tugas%s',
            count($todayTasks),
            $overdueCount > 0 ? " ({$overdueCount} terlambat)" : '',
        );

        $taskBullets = [];
        foreach (array_slice($todayTasks, 0, 3) as $task) {
            $title = (string) ($task['title'] ?? 'Tugas');
            $flag = (bool) ($task['is_overdue'] ?? false) ? ' [Terlambat]' : '';
            $taskBullets[] = "- {$title}{$flag}";
        }

        $lines[] = $taskBullets !== []
            ? $taskHeader."\n".implode("\n", $taskBullets)
            : 'Tugas Hari Ini: Tidak ada deadline mendesak hari ini.';

        $eventBullets = [];
        foreach (array_slice($todayEvents, 0, 3) as $event) {
            $title = (string) ($event['title'] ?? 'Agenda');
            $isAllDay = (bool) ($event['is_all_day'] ?? false);
            $timeLabel = $isAllDay
                ? 'Seharian'
                : substr((string) ($event['starts_at_local'] ?? ''), 11, 5);
            $eventBullets[] = $timeLabel !== '' ? "- {$timeLabel} · {$title}" : "- {$title}";
        }

        $lines[] = $eventBullets !== []
            ? sprintf("Agenda Hari Ini (%d):\n%s", count($todayEvents), implode("\n", $eventBullets))
            : 'Agenda Hari Ini: Belum ada agenda terjadwal.';

        if ($aiPriorities !== null && is_array($aiPriorities['priorities'] ?? null) && $aiPriorities['priorities'] !== []) {
            $topTitles = [];
            foreach (array_slice($aiPriorities['priorities'], 0, 2) as $priorityItem) {
                if (is_array($priorityItem) && is_string($priorityItem['title'] ?? null) && $priorityItem['title'] !== '') {
                    $topTitles[] = '- '.$priorityItem['title'];
                }
            }
            if ($topTitles !== []) {
                $lines[] = "Prioritas AI Hari Ini:\n".implode("\n", $topTitles);
            }
        }

        $lines[] = "Butuh bantuan menyusun jadwal atau memecah tugas hari ini? Buka AI Chat Managjeh: {$aiChatUrl}";

        return implode("\n\n", $lines);
    }

    /**
     * Build a deterministic (rule-based, zero-LLM) weekly review & summary for outbound WhatsApp.
     */
    public function buildWeeklySummary(User $user): string
    {
        $overview = $this->dashboardAggregator->aggregate($user);
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $nowUtc = CarbonImmutable::now('UTC');
        $aiChatUrl = rtrim((string) config('app.url', 'http://localhost:8000'), '/').'/ai-chat';

        /** @var array<string, int> $completedSummary */
        $completedSummary = is_array($overview['completed_summary'] ?? null) ? $overview['completed_summary'] : [];
        $completedLast7Days = (int) ($completedSummary['last_7_days_count'] ?? 0);
        $activeCount = (int) ($completedSummary['active_count'] ?? 0);

        $overdueActiveCount = $user->tasks()
            ->whereNull('parent_id')
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<', $nowUtc)
            ->count();

        /** @var array<int, array<string, mixed>> $activeProjects */
        $activeProjects = is_array($overview['active_projects'] ?? null) ? $overview['active_projects'] : [];
        /** @var array<int, array<string, mixed>> $upcomingTasks */
        $upcomingTasks = is_array($overview['upcoming_tasks'] ?? null) ? $overview['upcoming_tasks'] : [];

        $localWeekLabel = CarbonImmutable::now($timezone)->format('d M Y');

        $lines = [
            "Evaluasi & Ringkasan Mingguan Managjeh ({$localWeekLabel})",
            implode("\n", [
                "- Tugas diselesaikan (7 hari terakhir): {$completedLast7Days}",
                "- Tugas aktif tersisa: {$activeCount}",
                "- Tugas terlambat: {$overdueActiveCount}",
                '- Deadline 7 hari ke depan: '.count($upcomingTasks),
            ]),
        ];

        if ($activeProjects !== []) {
            $projectLines = [];
            foreach (array_slice($activeProjects, 0, 3) as $project) {
                $name = (string) ($project['name'] ?? 'Proyek');
                $progress = (int) ($project['progress'] ?? 0);
                $projectLines[] = "- {$name}: {$progress}%";
            }
            $lines[] = "Progres Proyek Aktif:\n".implode("\n", $projectLines);
        }

        $lines[] = "Luangkan 5 menit untuk refleksi dan susun jadwal pekan depan bersama AI Chat Managjeh: {$aiChatUrl}";

        return implode("\n\n", $lines);
    }
}
