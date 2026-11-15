import type { ProjectStatus, Task, TaskPriority, TaskStatus } from '@/types';

export const TASK_STATUS_LABELS: Record<TaskStatus, string> = {
    todo: 'Belum Dikerjakan',
    in_progress: 'Sedang Dikerjakan',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};

export const TASK_PRIORITY_LABELS: Record<TaskPriority, string> = {
    low: 'Rendah',
    medium: 'Sedang',
    high: 'Tinggi',
    urgent: 'Mendesak',
};

export const PROJECT_STATUS_LABELS: Record<ProjectStatus, string> = {
    active: 'Aktif',
    completed: 'Selesai',
    archived: 'Diarsipkan',
};

export const TASK_PRIORITY_BADGE_CLASS: Record<TaskPriority, string> = {
    low: 'border-border bg-secondary text-muted-foreground',
    medium: 'border-border bg-card text-foreground',
    high: 'border-amber-600/25 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/50 dark:text-amber-300',
    urgent: 'border-red-600/25 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-950/50 dark:text-red-300',
};

export const PROJECT_STATUS_BADGE_CLASS: Record<ProjectStatus, string> = {
    active: 'border-emerald-600/25 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-950/50 dark:text-emerald-300',
    completed: 'border-border bg-secondary text-foreground',
    archived: 'border-border bg-muted text-muted-foreground',
};

export type UrgencyBucket =
    | 'overdue'
    | 'today'
    | 'upcoming'
    | 'no_due'
    | 'closed';

/**
 * Determine urgency bucket using the user's local timezone date string.
 */
export function getTaskUrgencyBucket(
    task: Task,
    timezone: string,
): UrgencyBucket {
    if (task.status === 'completed' || task.status === 'cancelled') {
        return 'closed';
    }

    if (!task.due_at) {
        return 'no_due';
    }

    const now = new Date();
    const due = new Date(task.due_at);

    if (due.getTime() < now.getTime()) {
        return 'overdue';
    }

    const todayLocal = formatLocalDateKey(now, timezone);
    const dueLocal = formatLocalDateKey(due, timezone);

    if (dueLocal === todayLocal) {
        return 'today';
    }

    return 'upcoming';
}

export function formatLocalDateKey(date: Date, timezone: string): string {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(date);
    } catch {
        return date.toISOString().slice(0, 10);
    }
}

export function formatDueAtDisplay(
    dueAt: string | null,
    timezone: string,
): string {
    if (!dueAt) {
        return 'Tanpa deadline';
    }

    try {
        return new Intl.DateTimeFormat('id-ID', {
            timeZone: timezone,
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(dueAt));
    } catch {
        return dueAt;
    }
}

export function formatDateDisplay(dateStr: string | null): string {
    if (!dateStr) {
        return '—';
    }

    try {
        return new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }).format(new Date(`${dateStr}T00:00:00`));
    } catch {
        return dateStr;
    }
}
