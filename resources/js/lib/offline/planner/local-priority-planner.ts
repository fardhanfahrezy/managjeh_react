import {
    expandCalendarEvents,
    resolveNumericId,
} from '@/lib/offline/calendar/recurrence-expander';
import { annotateScheduleConflicts } from '@/lib/offline/calendar/schedule-conflict-detector';
import type { ManagjehUserDb } from '@/lib/offline/db';
import {
    formatUtcIsoToLocalDateTime,
    getLocalDayBoundsUtc,
    getLocalTodayDate,
} from '@/lib/offline/timezone';
import type {
    AiPlannerPriorityItem,
    AiPlannerRecommendedReminder,
    AiPlannerRun,
    AiPlannerWarning,
    CalendarEvent,
} from '@/types/models';

const PRIORITY_WEIGHTS: Record<string, number> = {
    urgent: 100,
    high: 70,
    medium: 40,
    low: 15,
};

interface FreeSlot {
    start: string;
    end: string;
}

function minutesToTime(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function parseTimeMinutes(localDateTime: string | null): number | null {
    if (!localDateTime || localDateTime.length < 16) {
        return null;
    }
    const hour = Number.parseInt(localDateTime.slice(11, 13), 10);
    const minute = Number.parseInt(localDateTime.slice(14, 16), 10);
    if (Number.isNaN(hour) || Number.isNaN(minute)) {
        return null;
    }
    return hour * 60 + minute;
}

function computeLocalFreeSlots(todayEvents: CalendarEvent[]): FreeSlot[] {
    const windowStart = 8 * 60; // 08:00
    const windowEnd = 22 * 60; // 22:00

    const busy: Array<{ start: number; end: number }> = [];
    for (const event of todayEvents) {
        if (event.is_all_day) {
            continue;
        }
        const s = parseTimeMinutes(event.starts_at_local);
        const e = parseTimeMinutes(event.ends_at_local);
        if (s !== null && e !== null && e > s) {
            const clampedStart = Math.max(windowStart, s);
            const clampedEnd = Math.min(windowEnd, e);
            if (clampedEnd > clampedStart) {
                busy.push({ start: clampedStart, end: clampedEnd });
            }
        }
    }

    busy.sort((a, b) => a.start - b.start);

    const slots: FreeSlot[] = [];
    let cursor = windowStart;
    for (const block of busy) {
        if (block.start - cursor >= 30) {
            slots.push({
                start: minutesToTime(cursor),
                end: minutesToTime(block.start),
            });
        }
        cursor = Math.max(cursor, block.end);
    }

    if (windowEnd - cursor >= 30) {
        slots.push({
            start: minutesToTime(cursor),
            end: minutesToTime(windowEnd),
        });
    }

    return slots;
}

/**
 * Deterministic local offline priority planner (`D-25`).
 * Faithful client-side port of `FallbackPlannerProvider.php`.
 */
export async function generateLocalOfflinePlan(
    db: ManagjehUserDb,
    timezone: string,
    now: Date = new Date(),
): Promise<AiPlannerRun> {
    const planningDate = getLocalTodayDate(timezone, now);
    const { startUtc: todayStartUtc } = getLocalDayBoundsUtc(
        planningDate,
        timezone,
    );

    const [allTasks, allEvents] = await Promise.all([
        db.tasks.toArray(),
        db.calendar_events.toArray(),
    ]);

    const activeTasks = allTasks.filter(
        (task) =>
            task.deleted_at === null &&
            task.parent_uuid === null &&
            (task.status === 'todo' || task.status === 'in_progress'),
    );

    const todayEvents = annotateScheduleConflicts(
        expandCalendarEvents(allEvents, planningDate, planningDate, timezone),
    );
    const freeSlots = computeLocalFreeSlots(todayEvents);

    const scored = activeTasks.map((task) => {
        let score = PRIORITY_WEIGHTS[task.priority] ?? 20;
        if (task.status === 'in_progress') {
            score += 20;
        }

        const dueMs = task.due_at ? new Date(task.due_at).getTime() : null;
        const isOverdue = dueMs !== null && dueMs < todayStartUtc.getTime();
        if (isOverdue) {
            score += 80;
        } else if (dueMs !== null) {
            const hoursLeft = (dueMs - now.getTime()) / 3_600_000;
            if (hoursLeft <= 24) {
                score += 55;
            } else if (hoursLeft <= 72) {
                score += 30;
            }
        }

        return { task, score, isOverdue };
    });

    scored.sort((a, b) => b.score - a.score);

    const topThree = scored.slice(0, 3);
    const priorities: AiPlannerPriorityItem[] = [];
    const recommendedReminders: AiPlannerRecommendedReminder[] = [];

    topThree.forEach((item, index) => {
        const { task, isOverdue } = item;
        const reasons: string[] = [];

        if (isOverdue) {
            reasons.push('Sudah melewati tenggat');
        } else if (task.due_at) {
            reasons.push('Deadline mendekat');
        }

        reasons.push(`Prioritas ${task.priority.toUpperCase()}`);
        if (task.status === 'in_progress') {
            reasons.push('Sedang dikerjakan');
        }

        const estimated =
            task.priority === 'urgent' || task.priority === 'high'
                ? 60
                : task.priority === 'medium'
                  ? 45
                  : 30;

        const taskId = resolveNumericId(task.server_id, task.uuid);
        priorities.push({
            task_id: taskId,
            task_title: task.title,
            reason: `${reasons.join(' · ')}.`,
            suggested_action: `Fokus kerjakan "${task.title}" selama ${estimated} menit.`,
            estimated_minutes: estimated,
        });

        const slot = freeSlots[index];
        if (slot) {
            recommendedReminders.push({
                task_id: taskId,
                task_title: task.title,
                remind_at_local: `${planningDate} ${slot.start}:00`,
                reason: `Awal slot kosong lokal (${slot.start}–${slot.end}).`,
            });
        }
    });

    const warnings: AiPlannerWarning[] = [];
    if (todayEvents.some((event) => event.has_conflict)) {
        warnings.push({
            type: 'schedule_conflict',
            message: 'Terdapat agenda hari ini yang waktunya beririsan.',
        });
    }

    if (activeTasks.length >= 8) {
        warnings.push({
            type: 'overload',
            message: `Anda memiliki ${activeTasks.length} tugas aktif; fokus pada 3 prioritas utama terlebih dahulu.`,
        });
    }

    const summary =
        priorities.length > 0
            ? `Rencana prioritas lokal (offline) untuk ${planningDate}: ${priorities.length} prioritas utama dari ${activeTasks.length} tugas aktif.`
            : `Tidak ada tugas aktif untuk dijadwalkan pada ${planningDate}.`;

    const run: AiPlannerRun = {
        id: -Date.now(),
        planning_date: planningDate,
        trigger_type: 'offline_local',
        provider: 'local-offline',
        model: 'deterministic-offline-v1',
        status: 'success',
        result: {
            summary,
            priorities,
            recommended_reminders: recommendedReminders,
            warnings,
        },
        error_message: null,
        created_at:
            formatUtcIsoToLocalDateTime(now.toISOString(), timezone) ??
            now.toISOString(),
    };

    await db.cached_snapshots.put({
        key: 'ai_planner_latest',
        payload: run,
        updated_at: now.toISOString(),
    });

    return run;
}
