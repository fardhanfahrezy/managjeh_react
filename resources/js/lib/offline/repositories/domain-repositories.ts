import {
    expandCalendarEvents,
    resolveNumericId,
} from '@/lib/offline/calendar/recurrence-expander';
import { annotateScheduleConflicts } from '@/lib/offline/calendar/schedule-conflict-detector';
import type { ManagjehUserDb } from '@/lib/offline/db';
import { OfflineEntityRepository } from '@/lib/offline/repositories/offline-entity-repository';
import {
    formatUtcIsoToLocalDateTime,
    generateUuid,
    localDateTimeToUtcIso,
} from '@/lib/offline/timezone';
import type {
    LocalCalendarEventRecord,
    LocalProjectRecord,
    LocalTaskRecord,
} from '@/lib/offline/types';
import type {
    CalendarEvent,
    Project,
    ProjectStatus,
    Task,
    TaskPriority,
    TaskStatus,
} from '@/types/models';

const PROJECT_TRACKED_FIELDS = [
    'name',
    'description',
    'status',
    'start_date',
    'due_date',
    'completed_at',
] as const;

const TASK_TRACKED_FIELDS = [
    'project_uuid',
    'parent_uuid',
    'title',
    'description',
    'status',
    'priority',
    'due_at',
    'completed_at',
    'sort_order',
] as const;

const CALENDAR_TRACKED_FIELDS = [
    'project_uuid',
    'task_uuid',
    'parent_event_uuid',
    'recurrence_id',
    'title',
    'description',
    'starts_at',
    'ends_at',
    'start_date',
    'end_date',
    'is_all_day',
    'is_cancelled',
    'recurrence_rule',
    'recurrence_until',
    'recurrence_exceptions',
] as const;

export interface CreateProjectInput {
    name: string;
    description?: string | null;
    status?: ProjectStatus;
    start_date?: string | null;
    due_date?: string | null;
}

export interface CreateTaskInput {
    title: string;
    description?: string | null;
    status?: TaskStatus;
    priority?: TaskPriority;
    due_at?: string | null;
    due_at_local?: string | null;
    project_uuid?: string | null;
    parent_uuid?: string | null;
    sort_order?: number;
}

export interface CreateCalendarEventInput {
    title: string;
    description?: string | null;
    project_uuid?: string | null;
    task_uuid?: string | null;
    parent_event_uuid?: string | null;
    recurrence_id?: string | null;
    is_all_day: boolean;
    starts_at?: string | null;
    ends_at?: string | null;
    starts_at_local?: string | null;
    ends_at_local?: string | null;
    start_date?: string | null;
    end_date?: string | null;
    is_cancelled?: boolean;
    recurrence_rule?: string | null;
    recurrence_until?: string | null;
    recurrence_exceptions?: string[];
}

export class ProjectRepository extends OfflineEntityRepository<LocalProjectRecord> {
    constructor(db: ManagjehUserDb) {
        super(db, db.projects, 'project', PROJECT_TRACKED_FIELDS);
    }

    async createProject(
        input: CreateProjectInput,
    ): Promise<LocalProjectRecord> {
        const now = new Date().toISOString();
        const status: ProjectStatus = input.status ?? 'active';
        const record: LocalProjectRecord = {
            uuid: generateUuid(),
            server_id: null,
            user_id: this.db.userId,
            name: input.name.trim(),
            description: input.description?.trim() || null,
            status,
            start_date: input.start_date || null,
            due_date: input.due_date || null,
            completed_at: status === 'completed' ? now : null,
            version: 0,
            created_at: now,
            updated_at: now,
            deleted_at: null,
            _sync_status: 'pending',
        };

        return this.createLocal(record);
    }

    async updateProject(
        uuid: string,
        patch: Partial<CreateProjectInput>,
    ): Promise<LocalProjectRecord> {
        const existing = await this.getByUuid(uuid);
        if (!existing) {
            throw new Error('Proyek tidak ditemukan di penyimpanan lokal.');
        }

        const nextStatus = patch.status ?? existing.status;
        const completedAt =
            nextStatus === 'completed'
                ? (existing.completed_at ?? new Date().toISOString())
                : null;

        return this.updateLocal(uuid, {
            ...(patch.name !== undefined ? { name: patch.name.trim() } : {}),
            ...(patch.description !== undefined
                ? { description: patch.description?.trim() || null }
                : {}),
            status: nextStatus,
            ...(patch.start_date !== undefined
                ? { start_date: patch.start_date || null }
                : {}),
            ...(patch.due_date !== undefined
                ? { due_date: patch.due_date || null }
                : {}),
            completed_at: completedAt,
        });
    }

    async listWithProgress(
        statusFilter?: ProjectStatus | '',
    ): Promise<Project[]> {
        const [projects, tasks] = await Promise.all([
            this.listActive(),
            this.db.tasks.toArray(),
        ]);

        const activeTasks = tasks.filter((task) => task.deleted_at === null);
        const filtered = statusFilter
            ? projects.filter((project) => project.status === statusFilter)
            : projects;

        filtered.sort((a, b) => {
            const aNull = a.due_date === null ? 1 : 0;
            const bNull = b.due_date === null ? 1 : 0;
            if (aNull !== bNull) {
                return aNull - bNull;
            }
            if (a.due_date && b.due_date && a.due_date !== b.due_date) {
                return a.due_date.localeCompare(b.due_date);
            }
            return b.created_at.localeCompare(a.created_at);
        });

        return filtered.map((project) => {
            const projectTasks = activeTasks.filter(
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
                version: project.version,
                user_id: project.user_id,
                name: project.name,
                description: project.description,
                status: project.status,
                start_date: project.start_date,
                due_date: project.due_date,
                completed_at: project.completed_at,
                progress,
                completed_tasks_count: completed.length,
                non_cancelled_tasks_count: nonCancelled.length,
                created_at: project.created_at,
                updated_at: project.updated_at,
            };
        });
    }
}

export class TaskRepository extends OfflineEntityRepository<LocalTaskRecord> {
    constructor(db: ManagjehUserDb) {
        super(db, db.tasks, 'task', TASK_TRACKED_FIELDS);
    }

    async createTask(
        input: CreateTaskInput,
        timezone: string,
    ): Promise<LocalTaskRecord> {
        if (input.parent_uuid) {
            const parent = await this.getByUuid(input.parent_uuid);
            if (!parent || parent.deleted_at !== null) {
                throw new Error('Tugas induk tidak ditemukan.');
            }
            if (parent.parent_uuid !== null) {
                throw new Error(
                    'Subtugas tidak boleh lebih dari 1 tingkat kedalaman.',
                );
            }
        }

        const project = input.project_uuid
            ? await this.db.projects.get(input.project_uuid)
            : undefined;
        const parent = input.parent_uuid
            ? await this.getByUuid(input.parent_uuid)
            : undefined;

        const now = new Date().toISOString();
        const status: TaskStatus = input.status ?? 'todo';
        const dueAt =
            input.due_at !== undefined
                ? input.due_at
                : input.due_at_local
                  ? localDateTimeToUtcIso(input.due_at_local, timezone)
                  : null;

        const record: LocalTaskRecord = {
            uuid: generateUuid(),
            server_id: null,
            user_id: this.db.userId,
            project_uuid: input.project_uuid ?? null,
            project_id: project?.server_id ?? null,
            parent_uuid: input.parent_uuid ?? null,
            parent_id: parent?.server_id ?? null,
            title: input.title.trim(),
            description: input.description?.trim() || null,
            status,
            priority: input.priority ?? 'medium',
            due_at: dueAt,
            completed_at: status === 'completed' ? now : null,
            sort_order: input.sort_order ?? 0,
            version: 0,
            created_at: now,
            updated_at: now,
            deleted_at: null,
            _sync_status: 'pending',
        };

        return this.createLocal(record);
    }

    async updateTask(
        uuid: string,
        patch: Partial<CreateTaskInput>,
        timezone: string,
    ): Promise<LocalTaskRecord> {
        const existing = await this.getByUuid(uuid);
        if (!existing) {
            throw new Error('Tugas tidak ditemukan di penyimpanan lokal.');
        }

        if (patch.parent_uuid !== undefined && patch.parent_uuid !== null) {
            if (patch.parent_uuid === uuid) {
                throw new Error(
                    'Tugas tidak boleh menjadi subtugas dirinya sendiri.',
                );
            }
            const parent = await this.getByUuid(patch.parent_uuid);
            if (!parent || parent.parent_uuid !== null) {
                throw new Error(
                    'Subtugas tidak boleh lebih dari 1 tingkat kedalaman.',
                );
            }
            const hasChildren =
                (await this.db.tasks
                    .where('parent_uuid')
                    .equals(uuid)
                    .filter((t) => t.deleted_at === null)
                    .count()) > 0;
            if (hasChildren) {
                throw new Error(
                    'Tugas yang sudah memiliki subtugas tidak dapat diubah menjadi subtugas.',
                );
            }
        }

        const nextStatus = patch.status ?? existing.status;
        const completedAt =
            nextStatus === 'completed'
                ? (existing.completed_at ?? new Date().toISOString())
                : null;

        let dueAt = existing.due_at;
        if (patch.due_at !== undefined) {
            dueAt = patch.due_at;
        } else if (patch.due_at_local !== undefined) {
            dueAt = patch.due_at_local
                ? localDateTimeToUtcIso(patch.due_at_local, timezone)
                : null;
        }

        const nextProjectUuid =
            patch.project_uuid !== undefined
                ? patch.project_uuid
                : existing.project_uuid;
        const project = nextProjectUuid
            ? await this.db.projects.get(nextProjectUuid)
            : undefined;

        const nextParentUuid =
            patch.parent_uuid !== undefined
                ? patch.parent_uuid
                : existing.parent_uuid;
        const parent = nextParentUuid
            ? await this.getByUuid(nextParentUuid)
            : undefined;

        return this.updateLocal(uuid, {
            ...(patch.title !== undefined ? { title: patch.title.trim() } : {}),
            ...(patch.description !== undefined
                ? { description: patch.description?.trim() || null }
                : {}),
            status: nextStatus,
            ...(patch.priority !== undefined
                ? { priority: patch.priority }
                : {}),
            due_at: dueAt,
            completed_at: completedAt,
            project_uuid: nextProjectUuid,
            project_id: project?.server_id ?? null,
            parent_uuid: nextParentUuid,
            parent_id: parent?.server_id ?? null,
            ...(patch.sort_order !== undefined
                ? { sort_order: patch.sort_order }
                : {}),
        });
    }

    async listRootTasksForUi(
        timezone: string,
        filters?: {
            status?: TaskStatus | '';
            priority?: TaskPriority | '';
            projectUuid?: string;
            search?: string;
        },
    ): Promise<Task[]> {
        const [allTasks, allProjects] = await Promise.all([
            this.listActive(),
            this.db.projects.toArray(),
        ]);

        const projectsByUuid = new Map<string, LocalProjectRecord>();
        for (const project of allProjects) {
            if (project.deleted_at === null) {
                projectsByUuid.set(project.uuid, project);
            }
        }

        const subtasksByParentUuid = new Map<string, LocalTaskRecord[]>();
        const roots: LocalTaskRecord[] = [];

        for (const task of allTasks) {
            if (task.parent_uuid) {
                const list = subtasksByParentUuid.get(task.parent_uuid) ?? [];
                list.push(task);
                subtasksByParentUuid.set(task.parent_uuid, list);
            } else {
                roots.push(task);
            }
        }

        const searchLower = filters?.search?.trim().toLowerCase() ?? '';
        const filteredRoots = roots.filter((task) => {
            if (filters?.status && task.status !== filters.status) {
                return false;
            }
            if (filters?.priority && task.priority !== filters.priority) {
                return false;
            }
            if (
                filters?.projectUuid &&
                task.project_uuid !== filters.projectUuid
            ) {
                return false;
            }
            if (searchLower) {
                const haystack =
                    `${task.title} ${task.description ?? ''}`.toLowerCase();
                if (!haystack.includes(searchLower)) {
                    return false;
                }
            }
            return true;
        });

        const sortTasks = (a: LocalTaskRecord, b: LocalTaskRecord): number => {
            if (a.sort_order !== b.sort_order) {
                return a.sort_order - b.sort_order;
            }
            const aNull = a.due_at === null ? 1 : 0;
            const bNull = b.due_at === null ? 1 : 0;
            if (aNull !== bNull) {
                return aNull - bNull;
            }
            if (a.due_at && b.due_at && a.due_at !== b.due_at) {
                return a.due_at.localeCompare(b.due_at);
            }
            return b.created_at.localeCompare(a.created_at);
        };

        filteredRoots.sort(sortTasks);

        const mapTask = (record: LocalTaskRecord): Task => {
            const project = record.project_uuid
                ? projectsByUuid.get(record.project_uuid)
                : undefined;
            const subs = (subtasksByParentUuid.get(record.uuid) ?? []).sort(
                (a, b) =>
                    a.sort_order - b.sort_order ||
                    a.created_at.localeCompare(b.created_at),
            );

            return {
                id: resolveNumericId(record.server_id, record.uuid),
                uuid: record.uuid,
                version: record.version,
                user_id: record.user_id,
                project_id: project
                    ? resolveNumericId(project.server_id, project.uuid)
                    : record.project_id,
                project_uuid: record.project_uuid,
                parent_id: record.parent_id,
                parent_uuid: record.parent_uuid,
                title: record.title,
                description: record.description,
                status: record.status,
                priority: record.priority,
                due_at: record.due_at,
                due_at_local: formatUtcIsoToLocalDateTime(
                    record.due_at,
                    timezone,
                ),
                completed_at: record.completed_at,
                sort_order: record.sort_order,
                project: project
                    ? {
                          id: resolveNumericId(project.server_id, project.uuid),
                          uuid: project.uuid,
                          name: project.name,
                          status: project.status,
                      }
                    : null,
                subtasks: subs.map(mapTask),
                created_at: record.created_at,
                updated_at: record.updated_at,
            };
        };

        return filteredRoots.map(mapTask);
    }
}

export class CalendarRepository extends OfflineEntityRepository<LocalCalendarEventRecord> {
    constructor(db: ManagjehUserDb) {
        super(
            db,
            db.calendar_events,
            'calendar_event',
            CALENDAR_TRACKED_FIELDS,
        );
    }

    async createEvent(
        input: CreateCalendarEventInput,
        timezone: string,
    ): Promise<LocalCalendarEventRecord> {
        const now = new Date().toISOString();

        let startsAt: string | null = null;
        let endsAt: string | null = null;
        let startDate: string | null = null;
        let endDate: string | null = null;

        if (input.is_all_day) {
            if (!input.start_date) {
                throw new Error(
                    'Agenda seharian wajib memiliki tanggal mulai.',
                );
            }
            startDate = input.start_date;
            endDate = input.end_date || input.start_date;
            if (endDate < startDate) {
                throw new Error(
                    'Tanggal selesai harus sama atau setelah tanggal mulai.',
                );
            }
        } else {
            startsAt =
                input.starts_at ??
                (input.starts_at_local
                    ? localDateTimeToUtcIso(input.starts_at_local, timezone)
                    : null);
            endsAt =
                input.ends_at ??
                (input.ends_at_local
                    ? localDateTimeToUtcIso(input.ends_at_local, timezone)
                    : null);

            if (!startsAt || !endsAt) {
                throw new Error(
                    'Agenda berjam wajib memiliki waktu mulai dan selesai.',
                );
            }
            if (new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
                throw new Error('Waktu selesai harus setelah waktu mulai.');
            }
        }

        const project = input.project_uuid
            ? await this.db.projects.get(input.project_uuid)
            : undefined;
        const task = input.task_uuid
            ? await this.db.tasks.get(input.task_uuid)
            : undefined;
        const parent = input.parent_event_uuid
            ? await this.getByUuid(input.parent_event_uuid)
            : undefined;

        const record: LocalCalendarEventRecord = {
            uuid: generateUuid(),
            server_id: null,
            user_id: this.db.userId,
            project_uuid: input.project_uuid ?? null,
            project_id: project?.server_id ?? null,
            task_uuid: input.task_uuid ?? null,
            task_id: task?.server_id ?? null,
            parent_event_uuid: input.parent_event_uuid ?? null,
            parent_event_id: parent?.server_id ?? null,
            recurrence_id: input.recurrence_id ?? null,
            title: input.title.trim(),
            description: input.description?.trim() || null,
            starts_at: startsAt,
            ends_at: endsAt,
            start_date: startDate,
            end_date: endDate,
            is_all_day: input.is_all_day,
            is_cancelled: input.is_cancelled ?? false,
            recurrence_rule: input.recurrence_rule || null,
            recurrence_until: input.recurrence_until || null,
            recurrence_exceptions: input.recurrence_exceptions ?? [],
            version: 0,
            created_at: now,
            updated_at: now,
            deleted_at: null,
            _sync_status: 'pending',
        };

        return this.createLocal(record);
    }

    async updateEvent(
        uuid: string,
        patch: Partial<CreateCalendarEventInput>,
        timezone: string,
    ): Promise<LocalCalendarEventRecord> {
        const existing = await this.getByUuid(uuid);
        if (!existing) {
            throw new Error('Agenda tidak ditemukan di penyimpanan lokal.');
        }

        const isAllDay = patch.is_all_day ?? existing.is_all_day;
        let startsAt = existing.starts_at;
        let endsAt = existing.ends_at;
        let startDate = existing.start_date;
        let endDate = existing.end_date;

        if (isAllDay) {
            startDate = patch.start_date ?? existing.start_date;
            endDate = patch.end_date ?? existing.end_date ?? startDate;
            if (!startDate) {
                throw new Error(
                    'Agenda seharian wajib memiliki tanggal mulai.',
                );
            }
            if (endDate && endDate < startDate) {
                throw new Error(
                    'Tanggal selesai harus sama atau setelah tanggal mulai.',
                );
            }
            startsAt = null;
            endsAt = null;
        } else {
            if (patch.starts_at !== undefined) {
                startsAt = patch.starts_at;
            } else if (patch.starts_at_local) {
                startsAt = localDateTimeToUtcIso(
                    patch.starts_at_local,
                    timezone,
                );
            }

            if (patch.ends_at !== undefined) {
                endsAt = patch.ends_at;
            } else if (patch.ends_at_local) {
                endsAt = localDateTimeToUtcIso(patch.ends_at_local, timezone);
            }

            if (!startsAt || !endsAt) {
                throw new Error(
                    'Agenda berjam wajib memiliki waktu mulai dan selesai.',
                );
            }
            if (new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
                throw new Error('Waktu selesai harus setelah waktu mulai.');
            }
            startDate = null;
            endDate = null;
        }

        return this.updateLocal(uuid, {
            ...(patch.title !== undefined ? { title: patch.title.trim() } : {}),
            ...(patch.description !== undefined
                ? { description: patch.description?.trim() || null }
                : {}),
            ...(patch.project_uuid !== undefined
                ? { project_uuid: patch.project_uuid }
                : {}),
            ...(patch.task_uuid !== undefined
                ? { task_uuid: patch.task_uuid }
                : {}),
            is_all_day: isAllDay,
            starts_at: startsAt,
            ends_at: endsAt,
            start_date: startDate,
            end_date: endDate,
            ...(patch.is_cancelled !== undefined
                ? { is_cancelled: patch.is_cancelled }
                : {}),
            ...(patch.recurrence_rule !== undefined
                ? { recurrence_rule: patch.recurrence_rule || null }
                : {}),
            ...(patch.recurrence_until !== undefined
                ? { recurrence_until: patch.recurrence_until || null }
                : {}),
            ...(patch.recurrence_exceptions !== undefined
                ? { recurrence_exceptions: patch.recurrence_exceptions }
                : {}),
        });
    }

    async excludeSingleOccurrence(
        masterUuid: string,
        recurrenceId: string,
    ): Promise<void> {
        const master = await this.getByUuid(masterUuid);
        if (!master) {
            return;
        }

        const nextExceptions = Array.from(
            new Set([...(master.recurrence_exceptions ?? []), recurrenceId]),
        );

        await this.updateLocal(masterUuid, {
            recurrence_exceptions: nextExceptions,
        });
    }

    async listExpandedOccurrences(
        rangeStartDate: string,
        rangeEndDate: string,
        timezone: string,
    ): Promise<CalendarEvent[]> {
        const [events, projects, tasks] = await Promise.all([
            this.listActive(),
            this.db.projects.toArray(),
            this.db.tasks.toArray(),
        ]);

        const projectsByUuid = new Map<string, LocalProjectRecord>();
        for (const project of projects) {
            projectsByUuid.set(project.uuid, project);
        }

        const tasksByUuid = new Map<string, LocalTaskRecord>();
        for (const task of tasks) {
            tasksByUuid.set(task.uuid, task);
        }

        const expanded = expandCalendarEvents(
            events,
            rangeStartDate,
            rangeEndDate,
            timezone,
            projectsByUuid,
            tasksByUuid,
        );

        return annotateScheduleConflicts(expanded);
    }
}
