import {
    expandCalendarEvents,
    resolveNumericId,
} from '@/lib/offline/calendar/recurrence-expander';
import { annotateScheduleConflicts } from '@/lib/offline/calendar/schedule-conflict-detector';
import type { ManagjehUserDb } from '@/lib/offline/db';
import {
    addDaysToLocalDate,
    formatIndonesianDateLabel,
    formatUtcIsoToLocalDateTime,
    getLocalDayBoundsUtc,
    getLocalHour,
    getLocalTodayDate,
} from '@/lib/offline/timezone';
import type {
    AuthLeaseRecord,
    LocalProjectRecord,
    LocalTaskRecord,
} from '@/lib/offline/types';
import type {
    AiPlannerRun,
    DashboardData,
    DashboardProjectItem,
    DashboardTaskItem,
} from '@/types/models';

function resolveGreeting(hour: number): string {
    if (hour >= 4 && hour < 11) {
        return 'Selamat pagi';
    }
    if (hour >= 11 && hour < 15) {
        return 'Selamat siang';
    }
    if (hour >= 15 && hour < 18) {
        return 'Selamat sore';
    }
    return 'Selamat malam';
}

export async function buildLocalDashboardData(
    db: ManagjehUserDb,
    lease: AuthLeaseRecord,
    now: Date = new Date(),
): Promise<DashboardData> {
    const timezone = lease.timezone || 'Asia/Jakarta';
    const localDate = getLocalTodayDate(timezone, now);
    const { startUtc: todayStartUtc, endUtc: todayEndUtc } =
        getLocalDayBoundsUtc(localDate, timezone);
    const upcomingEndDate = addDaysToLocalDate(localDate, 7);
    const { endUtc: upcomingEndUtc } = getLocalDayBoundsUtc(
        upcomingEndDate,
        timezone,
    );
    const sevenDaysStartDate = addDaysToLocalDate(localDate, -6);
    const { startUtc: sevenDaysStartUtc } = getLocalDayBoundsUtc(
        sevenDaysStartDate,
        timezone,
    );

    const [allProjects, allTasks, allEvents, aiSnapshot] = await Promise.all([
        db.projects.toArray(),
        db.tasks.toArray(),
        db.calendar_events.toArray(),
        db.cached_snapshots.get('ai_planner_latest'),
    ]);

    const activeProjectsRaw = allProjects.filter(
        (project) => project.deleted_at === null,
    );
    const activeTasksAll = allTasks.filter((task) => task.deleted_at === null);

    const projectsByUuid = new Map<string, LocalProjectRecord>();
    for (const project of activeProjectsRaw) {
        projectsByUuid.set(project.uuid, project);
    }

    const tasksByUuid = new Map<string, LocalTaskRecord>();
    for (const task of activeTasksAll) {
        tasksByUuid.set(task.uuid, task);
    }

    const serializeDashboardTask = (
        task: LocalTaskRecord,
    ): DashboardTaskItem => {
        const project = task.project_uuid
            ? projectsByUuid.get(task.project_uuid)
            : undefined;
        const dueMs = task.due_at ? new Date(task.due_at).getTime() : null;

        return {
            id: resolveNumericId(task.server_id, task.uuid),
            uuid: task.uuid,
            title: task.title,
            status: task.status,
            priority: task.priority,
            due_at: task.due_at,
            due_at_local: formatUtcIsoToLocalDateTime(task.due_at, timezone),
            is_overdue: dueMs !== null && dueMs < todayStartUtc.getTime(),
            project: project
                ? {
                      id: resolveNumericId(project.server_id, project.uuid),
                      uuid: project.uuid,
                      name: project.name,
                      status: project.status,
                  }
                : null,
        };
    };

    const rootOpenTasks = activeTasksAll.filter(
        (task) =>
            task.parent_uuid === null &&
            (task.status === 'todo' || task.status === 'in_progress'),
    );

    const todayStartMs = todayStartUtc.getTime();
    const todayEndMs = todayEndUtc.getTime();
    const upcomingEndMs = upcomingEndUtc.getTime();

    const todayTasks = rootOpenTasks
        .filter((task) => {
            if (task.status === 'in_progress') {
                return true;
            }
            if (!task.due_at) {
                return false;
            }
            const dueMs = new Date(task.due_at).getTime();
            return dueMs >= todayStartMs && dueMs <= todayEndMs;
        })
        .sort((a, b) => {
            const aNull = a.due_at === null ? 1 : 0;
            const bNull = b.due_at === null ? 1 : 0;
            if (aNull !== bNull) {
                return aNull - bNull;
            }
            if (a.due_at && b.due_at && a.due_at !== b.due_at) {
                return a.due_at.localeCompare(b.due_at);
            }
            return a.sort_order - b.sort_order;
        })
        .slice(0, 10)
        .map(serializeDashboardTask);

    const upcomingTasks = rootOpenTasks
        .filter((task) => {
            if (!task.due_at) {
                return false;
            }
            const dueMs = new Date(task.due_at).getTime();
            return (
                dueMs < todayStartMs ||
                (dueMs > todayEndMs && dueMs <= upcomingEndMs)
            );
        })
        .sort((a, b) => (a.due_at ?? '').localeCompare(b.due_at ?? ''))
        .slice(0, 8)
        .map(serializeDashboardTask);

    const todayEvents = annotateScheduleConflicts(
        expandCalendarEvents(
            allEvents,
            localDate,
            localDate,
            timezone,
            projectsByUuid,
            tasksByUuid,
        ),
    );

    const activeProjects: DashboardProjectItem[] = activeProjectsRaw
        .filter((project) => project.status === 'active')
        .sort((a, b) => {
            const aNull = a.due_date === null ? 1 : 0;
            const bNull = b.due_date === null ? 1 : 0;
            if (aNull !== bNull) {
                return aNull - bNull;
            }
            if (a.due_date && b.due_date && a.due_date !== b.due_date) {
                return a.due_date.localeCompare(b.due_date);
            }
            return b.created_at.localeCompare(a.created_at);
        })
        .slice(0, 5)
        .map((project) => {
            const projectTasks = activeTasksAll.filter(
                (task) => task.project_uuid === project.uuid,
            );
            const nonCancelled = projectTasks.filter(
                (task) => task.status !== 'cancelled',
            );
            const completed = nonCancelled.filter(
                (task) => task.status === 'completed',
            );
            const progress =
                nonCancelled.length > 0
                    ? Math.round((completed.length / nonCancelled.length) * 100)
                    : 0;

            return {
                id: resolveNumericId(project.server_id, project.uuid),
                uuid: project.uuid,
                name: project.name,
                status: project.status,
                due_date: project.due_date,
                progress,
                completed_tasks_count: completed.length,
                non_cancelled_tasks_count: nonCancelled.length,
            };
        });

    let todayCount = 0;
    let last7DaysCount = 0;
    for (const task of activeTasksAll) {
        if (task.status === 'completed' && task.completed_at) {
            const completedMs = new Date(task.completed_at).getTime();
            if (completedMs >= todayStartMs && completedMs <= todayEndMs) {
                todayCount++;
            }
            if (completedMs >= sevenDaysStartUtc.getTime()) {
                last7DaysCount++;
            }
        }
    }

    let aiPriorities: DashboardData['ai_priorities'] = null;
    const latestRun = aiSnapshot?.payload as AiPlannerRun | null | undefined;
    if (latestRun?.status === 'success' && latestRun.result) {
        aiPriorities = {
            provider: latestRun.provider,
            summary: latestRun.result.summary,
            priorities: latestRun.result.priorities ?? [],
        };
    }

    return {
        greeting: resolveGreeting(getLocalHour(timezone, now)),
        local_date: localDate,
        local_date_label: formatIndonesianDateLabel(localDate),
        timezone,
        today_tasks: todayTasks,
        upcoming_tasks: upcomingTasks,
        today_events: todayEvents,
        active_projects: activeProjects,
        ai_priorities: aiPriorities,
        whatsapp_status: {
            enabled: lease.whatsapp_enabled,
            verified: lease.whatsapp_verified,
            can_receive: lease.whatsapp_enabled && lease.whatsapp_verified,
            number_masked: lease.whatsapp_number_masked,
            quiet_hours: lease.quiet_hours || '00:00–05:00',
        },
        completed_summary: {
            today_count: todayCount,
            last_7_days_count: last7DaysCount,
            active_count: rootOpenTasks.length,
        },
    };
}
