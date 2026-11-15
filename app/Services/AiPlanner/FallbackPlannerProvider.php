<?php

namespace App\Services\AiPlanner;

use App\Contracts\AiPlannerProviderInterface;
use Carbon\CarbonImmutable;

class FallbackPlannerProvider implements AiPlannerProviderInterface
{
    public function name(): string
    {
        return 'fallback';
    }

    public function model(): ?string
    {
        return 'deterministic-v1';
    }

    public function generatePlan(AiPlannerContext $context): AiPlannerProviderResponse
    {
        $priorityWeights = [
            'urgent' => 100,
            'high' => 70,
            'medium' => 40,
            'low' => 15,
        ];

        $scoredTasks = [];
        foreach ($context->activeTasks as $task) {
            $score = $priorityWeights[$task['priority']] ?? 20;

            if ($task['status'] === 'in_progress') {
                $score += 20;
            }

            if ($task['is_overdue']) {
                $score += 80;
            } elseif ($task['due_at_local'] !== null) {
                $hoursLeft = CarbonImmutable::parse($context->planningDate, $context->timezone)
                    ->diffInHours(CarbonImmutable::parse($task['due_at_local'], $context->timezone), false);

                if ($hoursLeft <= 24) {
                    $score += 55;
                } elseif ($hoursLeft <= 72) {
                    $score += 30;
                }
            }

            $scoredTasks[] = [
                'task' => $task,
                'score' => $score,
            ];
        }

        usort($scoredTasks, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $topThree = array_slice($scoredTasks, 0, 3);
        $priorities = [];
        $recommendedReminders = [];

        foreach ($topThree as $index => $item) {
            $task = $item['task'];
            $reasonParts = [];

            if ($task['is_overdue']) {
                $reasonParts[] = 'Sudah melewati tenggat';
            } elseif ($task['due_at_local'] !== null) {
                $reasonParts[] = 'Deadline mendekat';
            }

            $reasonParts[] = 'Prioritas '.strtoupper($task['priority']);
            if ($task['status'] === 'in_progress') {
                $reasonParts[] = 'Sedang dikerjakan';
            }

            $estimated = match ($task['priority']) {
                'urgent', 'high' => 60,
                'medium' => 45,
                default => 30,
            };

            $priorities[] = [
                'task_id' => $task['id'],
                'reason' => implode(' · ', $reasonParts).'.',
                'suggested_action' => sprintf('Fokus kerjakan "%s" selama %d menit.', $task['title'], $estimated),
                'estimated_minutes' => $estimated,
            ];

            if (isset($context->freeSlots[$index])) {
                $slot = $context->freeSlots[$index];
                $recommendedReminders[] = [
                    'task_id' => $task['id'],
                    'remind_at_local' => sprintf('%s %s:00', $context->planningDate, $slot['start']),
                    'reason' => sprintf('Awal slot kosong (%s–%s).', $slot['start'], $slot['end']),
                ];
            }
        }

        $warnings = [];
        $hasConflict = false;
        foreach ($context->todayEvents as $event) {
            if ($event['has_conflict']) {
                $hasConflict = true;
                break;
            }
        }

        if ($hasConflict) {
            $warnings[] = [
                'type' => 'schedule_conflict',
                'message' => 'Terdapat agenda hari ini yang waktunya beririsan.',
            ];
        }

        if (count($context->activeTasks) >= 8) {
            $warnings[] = [
                'type' => 'overload',
                'message' => sprintf('Anda memiliki %d tugas aktif; fokus pada 3 prioritas utama terlebih dahulu.', count($context->activeTasks)),
            ];
        }

        $summary = count($priorities) > 0
            ? sprintf('Rencana deterministik untuk %s: %d prioritas utama dari %d tugas aktif.', $context->planningDate, count($priorities), count($context->activeTasks))
            : sprintf('Tidak ada tugas aktif untuk dijadwalkan pada %s.', $context->planningDate);

        return new AiPlannerProviderResponse(
            provider: $this->name(),
            model: $this->model(),
            result: [
                'summary' => $summary,
                'priorities' => $priorities,
                'recommended_reminders' => $recommendedReminders,
                'warnings' => $warnings,
            ],
        );
    }
}
