import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar as CalendarIcon,
    ChevronLeft,
    ChevronRight,
    Clock,
    Plus,
    Repeat,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import CalendarEventController from '@/actions/App/Http/Controllers/CalendarEventController';
import { useOffline } from '@/components/offline/offline-provider';
import {
    formatDateDisplay,
    TASK_PRIORITY_BADGE_CLASS,
    TASK_PRIORITY_LABELS,
} from '@/components/tasks/task-constants';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { findConflictsForInterval } from '@/lib/offline/calendar/schedule-conflict-detector';
import {
    addDaysToLocalDate,
    formatUtcIsoToLocalDate,
    formatUtcIsoToLocalDateTime,
    localDateTimeToUtcIso,
    startOfWeekMonday,
} from '@/lib/offline/timezone';
import { index as calendarIndex } from '@/routes/calendar';
import type {
    CalendarEvent,
    CalendarView,
    ConflictWarning,
    ProjectSummary,
    TaskDeadlineItem,
    TaskOption,
} from '@/types/models';

type Props = {
    view: CalendarView;
    active_date: string;
    range_start: string;
    range_end: string;
    events: CalendarEvent[];
    task_deadlines: TaskDeadlineItem[];
    projects: ProjectSummary[];
    tasks_for_select: TaskOption[];
    conflict_warnings: ConflictWarning[];
};

const WEEKDAY_CODES = [
    { code: 'MO', label: 'Sen' },
    { code: 'TU', label: 'Sel' },
    { code: 'WE', label: 'Rab' },
    { code: 'TH', label: 'Kam' },
    { code: 'FR', label: 'Jum' },
    { code: 'SA', label: 'Sab' },
    { code: 'SU', label: 'Min' },
] as const;

const HOURS = Array.from({ length: 19 }, (_, idx) => idx + 5); // 05:00 - 23:00

function parseDateKey(dateKey: string): Date {
    const [year, month, day] = dateKey.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function toDateKey(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

function addDays(dateKey: string, days: number): string {
    const d = parseDateKey(dateKey);
    d.setDate(d.getDate() + days);

    return toDateKey(d);
}

function getDateDaysBetween(startKey: string, endKey: string): string[] {
    const days: string[] = [];
    let current = startKey;

    while (current <= endKey) {
        days.push(current);
        current = addDays(current, 1);
    }

    return days;
}

function formatDayHeader(dateKey: string): {
    weekday: string;
    dayNum: string;
    monthShort: string;
} {
    const date = parseDateKey(dateKey);

    return {
        weekday: date.toLocaleDateString('id-ID', { weekday: 'short' }),
        dayNum: String(date.getDate()).padStart(2, '0'),
        monthShort: date.toLocaleDateString('id-ID', { month: 'short' }),
    };
}

function formatTimeRange(event: CalendarEvent): string {
    if (event.is_all_day) {
        return 'Sepanjang hari';
    }

    const startTime = event.starts_at_local?.slice(11, 16) ?? '--:--';
    const endTime = event.ends_at_local?.slice(11, 16) ?? '--:--';

    return `${startTime}–${endTime}`;
}

function parseMinutesFromLocal(localIso: string | null): number {
    if (!localIso || localIso.length < 16) {
        return 0;
    }

    const hour = Number(localIso.slice(11, 13));
    const minute = Number(localIso.slice(14, 16));

    return hour * 60 + minute;
}

function parseRecurrenceRule(rule: string | null): {
    freq: 'none' | 'DAILY' | 'WEEKLY' | 'MONTHLY';
    byDay: string[];
} {
    if (!rule) {
        return { freq: 'none', byDay: [] };
    }

    const parts = rule.toUpperCase().split(';');
    let freq: 'none' | 'DAILY' | 'WEEKLY' | 'MONTHLY' = 'none';
    let byDay: string[] = [];

    for (const part of parts) {
        if (part.startsWith('FREQ=')) {
            const val = part.slice(5);
            if (val === 'DAILY' || val === 'WEEKLY' || val === 'MONTHLY') {
                freq = val;
            }
        }
        if (part.startsWith('BYDAY=')) {
            byDay = part.slice(6).split(',').filter(Boolean);
        }
    }

    return { freq, byDay };
}

function buildRecurrenceRule(
    freq: 'none' | 'DAILY' | 'WEEKLY' | 'MONTHLY',
    byDay: string[],
): string | null {
    if (freq === 'none') {
        return null;
    }

    if (freq === 'WEEKLY' && byDay.length > 0) {
        return `FREQ=WEEKLY;BYDAY=${byDay.join(',')}`;
    }

    return `FREQ=${freq}`;
}

function computeRangeBounds(
    view: CalendarView,
    activeDate: string,
): { rangeStart: string; rangeEnd: string } {
    if (view === 'day') {
        return { rangeStart: activeDate, rangeEnd: activeDate };
    }
    if (view === 'week') {
        const start = startOfWeekMonday(activeDate);
        return { rangeStart: start, rangeEnd: addDaysToLocalDate(start, 6) };
    }
    const [year, month] = activeDate.split('-').map(Number);
    const firstOfMonth = `${year}-${String(month).padStart(2, '0')}-01`;
    const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const lastOfMonth = `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
    const gridStart = startOfWeekMonday(firstOfMonth);
    const endDow = new Date(`${lastOfMonth}T00:00:00Z`).getUTCDay();
    const trailingDays = endDow === 0 ? 0 : 7 - endDow;
    return {
        rangeStart: gridStart,
        rangeEnd: addDaysToLocalDate(lastOfMonth, trailingDays),
    };
}

export default function CalendarIndex({
    view: serverView,
    active_date: serverActiveDate,
    range_start: serverRangeStart,
    range_end: serverRangeEnd,
    events: serverEvents,
    task_deadlines: serverTaskDeadlines,
    projects: serverProjects,
    tasks_for_select: serverTasksForSelect,
    conflict_warnings: serverConflictWarnings,
}: Props) {
    const {
        repositories,
        lease,
        hydratedFromServer,
        isOnline,
        dataVersion,
        notifyLocalMutation,
    } = useOffline();
    const timezone = lease?.timezone || 'Asia/Jakarta';

    const [viewState, setViewState] = useState<CalendarView>(serverView);
    const [activeDateState, setActiveDateState] = useState(serverActiveDate);
    const [rangeStartState, setRangeStartState] = useState(serverRangeStart);
    const [rangeEndState, setRangeEndState] = useState(serverRangeEnd);

    const [localEvents, setLocalEvents] = useState<CalendarEvent[] | null>(
        null,
    );
    const [localDeadlines, setLocalDeadlines] = useState<
        TaskDeadlineItem[] | null
    >(null);
    const [localProjects, setLocalProjects] = useState<ProjectSummary[] | null>(
        null,
    );
    const [localTasksSelect, setLocalTasksSelect] = useState<
        TaskOption[] | null
    >(null);
    const [localConflictWarnings, setLocalConflictWarnings] = useState<
        ConflictWarning[]
    >([]);

    useEffect(() => {
        setViewState(serverView);
        setActiveDateState(serverActiveDate);
        setRangeStartState(serverRangeStart);
        setRangeEndState(serverRangeEnd);
    }, [serverView, serverActiveDate, serverRangeStart, serverRangeEnd]);

    const view = viewState;
    const active_date = activeDateState;
    const range_start = rangeStartState;
    const range_end = rangeEndState;

    useEffect(() => {
        if (!repositories || (!hydratedFromServer && isOnline)) {
            return;
        }

        let cancelled = false;
        async function loadLocal() {
            const [expanded, projList, taskList] = await Promise.all([
                repositories!.calendar.listExpandedOccurrences(
                    range_start,
                    range_end,
                    timezone,
                ),
                repositories!.projects.listWithProgress(),
                repositories!.tasks.listRootTasksForUi(timezone),
            ]);

            const summaries: ProjectSummary[] = projList.map((p) => ({
                id: p.id,
                uuid: p.uuid,
                name: p.name,
                status: p.status,
            }));

            const deadlines: TaskDeadlineItem[] = taskList
                .filter((t) => {
                    if (
                        !t.due_at ||
                        t.status === 'completed' ||
                        t.status === 'cancelled'
                    ) {
                        return false;
                    }
                    const d = formatUtcIsoToLocalDate(t.due_at, timezone);
                    return d !== null && d >= range_start && d <= range_end;
                })
                .map((t) => ({
                    id: t.id,
                    title: t.title,
                    status: t.status,
                    priority: t.priority,
                    due_at: t.due_at,
                    due_at_local: formatUtcIsoToLocalDateTime(
                        t.due_at,
                        timezone,
                    ),
                    due_date_local: formatUtcIsoToLocalDate(t.due_at, timezone),
                    project: t.project
                        ? { id: t.project.id, name: t.project.name }
                        : null,
                }));

            const options: TaskOption[] = taskList
                .filter(
                    (t) => t.status === 'todo' || t.status === 'in_progress',
                )
                .map((t) => ({
                    id: t.id,
                    title: t.title,
                    status: t.status,
                }));

            if (!cancelled) {
                setLocalEvents(expanded);
                setLocalProjects(summaries);
                setLocalDeadlines(deadlines);
                setLocalTasksSelect(options);
            }
        }

        void loadLocal();

        return () => {
            cancelled = true;
        };
    }, [
        repositories,
        hydratedFromServer,
        isOnline,
        dataVersion,
        range_start,
        range_end,
        timezone,
    ]);

    const events = localEvents ?? serverEvents;
    const task_deadlines = localDeadlines ?? serverTaskDeadlines;
    const projects = localProjects ?? serverProjects;
    const tasks_for_select = localTasksSelect ?? serverTasksForSelect;
    const conflict_warnings =
        localConflictWarnings.length > 0
            ? localConflictWarnings
            : serverConflictWarnings;
    const [dialogOpen, setDialogOpen] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [editingEvent, setEditingEvent] = useState<CalendarEvent | null>(
        null,
    );

    const [recurrenceFreq, setRecurrenceFreq] = useState<
        'none' | 'DAILY' | 'WEEKLY' | 'MONTHLY'
    >('none');
    const [recurrenceByDay, setRecurrenceByDay] = useState<string[]>([]);
    const [editScope, setEditScope] = useState<'single' | 'series'>('single');
    const [deleteScope, setDeleteScope] = useState<'single' | 'series'>(
        'single',
    );

    const form = useForm<{
        title: string;
        description: string;
        is_all_day: boolean;
        starts_at: string;
        ends_at: string;
        start_date: string;
        end_date: string;
        project_id: string;
        task_id: string;
        recurrence_rule: string;
        recurrence_until: string;
        edit_scope: 'single' | 'series';
        recurrence_id: string;
    }>({
        title: '',
        description: '',
        is_all_day: false,
        starts_at: `${active_date}T09:00`,
        ends_at: `${active_date}T10:00`,
        start_date: active_date,
        end_date: active_date,
        project_id: '',
        task_id: '',
        recurrence_rule: '',
        recurrence_until: '',
        edit_scope: 'series',
        recurrence_id: '',
    });

    const visibleDays = useMemo(
        () => getDateDaysBetween(range_start, range_end),
        [range_start, range_end],
    );

    const allDayEvents = useMemo(
        () => events.filter((event) => event.is_all_day),
        [events],
    );

    const timedEvents = useMemo(
        () => events.filter((event) => !event.is_all_day),
        [events],
    );

    const conflictCount = useMemo(
        () => events.filter((event) => event.has_conflict).length,
        [events],
    );

    const navigateCalendar = (nextView: CalendarView, nextDate: string) => {
        const bounds = computeRangeBounds(nextView, nextDate);
        setViewState(nextView);
        setActiveDateState(nextDate);
        setRangeStartState(bounds.rangeStart);
        setRangeEndState(bounds.rangeEnd);

        if (!isOnline || hydratedFromServer) {
            if (typeof window !== 'undefined') {
                const nextUrl = calendarIndex.url({
                    query: { view: nextView, date: nextDate },
                });
                window.history.replaceState({}, '', nextUrl);
            }
            return;
        }

        router.get(
            calendarIndex.url({ query: { view: nextView, date: nextDate } }),
            {},
            { preserveScroll: true },
        );
    };

    const shiftDate = (direction: -1 | 1) => {
        if (view === 'day') {
            navigateCalendar(view, addDays(active_date, direction));

            return;
        }

        if (view === 'week') {
            navigateCalendar(view, addDays(active_date, direction * 7));

            return;
        }

        const d = parseDateKey(active_date);
        d.setMonth(d.getMonth() + direction);
        navigateCalendar(view, toDateKey(d));
    };

    const openCreateModal = (
        dateKey: string = active_date,
        startHour: number = 9,
    ) => {
        const hourStr = String(startHour).padStart(2, '0');
        const endHourStr = String(Math.min(startHour + 1, 23)).padStart(2, '0');

        setEditingEvent(null);
        setRecurrenceFreq('none');
        setRecurrenceByDay([]);
        setEditScope('series');
        form.clearErrors();
        form.setData({
            title: '',
            description: '',
            is_all_day: false,
            starts_at: `${dateKey}T${hourStr}:00`,
            ends_at: `${dateKey}T${endHourStr}:00`,
            start_date: dateKey,
            end_date: dateKey,
            project_id: '',
            task_id: '',
            recurrence_rule: '',
            recurrence_until: '',
            edit_scope: 'series',
            recurrence_id: '',
        });
        setDialogOpen(true);
    };

    const openEditModal = (event: CalendarEvent) => {
        const parsedRule = parseRecurrenceRule(event.recurrence_rule);
        const defaultScope: 'single' | 'series' = event.is_recurring
            ? 'single'
            : 'series';

        setEditingEvent(event);
        setRecurrenceFreq(parsedRule.freq);
        setRecurrenceByDay(parsedRule.byDay);
        setEditScope(defaultScope);
        setDeleteScope(event.is_recurring ? 'single' : 'series');
        form.clearErrors();
        form.setData({
            title: event.title,
            description: event.description ?? '',
            is_all_day: event.is_all_day,
            starts_at: event.starts_at_local ?? `${active_date}T09:00`,
            ends_at: event.ends_at_local ?? `${active_date}T10:00`,
            start_date: event.start_date ?? active_date,
            end_date: event.end_date ?? event.start_date ?? active_date,
            project_id: event.project_id ? String(event.project_id) : '',
            task_id: event.task_id ? String(event.task_id) : '',
            recurrence_rule: event.recurrence_rule ?? '',
            recurrence_until: event.recurrence_until ?? '',
            edit_scope: defaultScope,
            recurrence_id: event.recurrence_id ?? '',
        });
        setDialogOpen(true);
    };

    const toggleWeekDay = (code: string) => {
        setRecurrenceByDay((prev) =>
            prev.includes(code)
                ? prev.filter((item) => item !== code)
                : [...prev, code],
        );
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        const rule = buildRecurrenceRule(recurrenceFreq, recurrenceByDay);

        if (repositories) {
            try {
                let projectUuid: string | null = null;
                if (form.data.project_id !== '') {
                    const pId = Number(form.data.project_id);
                    const match = projects.find((p) => p.id === pId);
                    projectUuid =
                        match?.uuid ??
                        (await repositories.projects.getByServerId(pId))
                            ?.uuid ??
                        null;
                }

                let taskUuid: string | null = null;
                if (form.data.task_id !== '') {
                    const tId = Number(form.data.task_id);
                    taskUuid =
                        (await repositories.tasks.getByServerId(tId))?.uuid ??
                        null;
                }

                const eventInput = {
                    title: form.data.title,
                    description:
                        form.data.description.trim() === ''
                            ? null
                            : form.data.description,
                    is_all_day: form.data.is_all_day,
                    starts_at_local: form.data.is_all_day
                        ? null
                        : form.data.starts_at,
                    ends_at_local: form.data.is_all_day
                        ? null
                        : form.data.ends_at,
                    start_date: form.data.is_all_day
                        ? form.data.start_date
                        : null,
                    end_date: form.data.is_all_day ? form.data.end_date : null,
                    project_uuid: projectUuid,
                    task_uuid: taskUuid,
                    recurrence_rule:
                        editingEvent && editScope === 'single' ? null : rule,
                    recurrence_until:
                        editingEvent && editScope === 'single'
                            ? null
                            : form.data.recurrence_until || null,
                };

                // Detect local schedule conflicts before saving (`D-23`).
                if (
                    !eventInput.is_all_day &&
                    eventInput.starts_at_local &&
                    eventInput.ends_at_local
                ) {
                    const startIso = localDateTimeToUtcIso(
                        eventInput.starts_at_local,
                        timezone,
                    );
                    const endIso = localDateTimeToUtcIso(
                        eventInput.ends_at_local,
                        timezone,
                    );
                    const warnings = findConflictsForInterval(
                        startIso,
                        endIso,
                        events,
                        editingEvent?.uuid ?? editingEvent?.series_uuid ?? null,
                    );
                    setLocalConflictWarnings(warnings);
                } else {
                    setLocalConflictWarnings([]);
                }

                if (editingEvent) {
                    const seriesUuid =
                        editingEvent.series_uuid ??
                        (
                            await repositories.calendar.getByServerId(
                                editingEvent.series_id,
                            )
                        )?.uuid;

                    if (
                        editingEvent.is_recurring &&
                        editScope === 'single' &&
                        seriesUuid &&
                        editingEvent.recurrence_id
                    ) {
                        if (editingEvent.is_override && editingEvent.uuid) {
                            await repositories.calendar.updateEvent(
                                editingEvent.uuid,
                                eventInput,
                                timezone,
                            );
                        } else {
                            await repositories.calendar.createEvent(
                                {
                                    ...eventInput,
                                    parent_event_uuid: seriesUuid,
                                    recurrence_id: editingEvent.recurrence_id,
                                    recurrence_rule: null,
                                    recurrence_until: null,
                                },
                                timezone,
                            );
                        }
                    } else if (seriesUuid) {
                        await repositories.calendar.updateEvent(
                            seriesUuid,
                            eventInput,
                            timezone,
                        );
                    }
                    notifyLocalMutation();
                    toast.success(
                        isOnline
                            ? 'Agenda diperbarui.'
                            : 'Perubahan agenda disimpan di perangkat.',
                    );
                    setDialogOpen(false);
                    return;
                }

                await repositories.calendar.createEvent(eventInput, timezone);
                notifyLocalMutation();
                toast.success(
                    isOnline
                        ? 'Agenda berhasil dibuat.'
                        : 'Agenda tersimpan di perangkat (offline).',
                );
                setDialogOpen(false);
                return;
            } catch (err) {
                toast.error(
                    err instanceof Error
                        ? err.message
                        : 'Gagal menyimpan agenda.',
                );
                return;
            }
        }

        const payload = {
            title: form.data.title,
            description:
                form.data.description.trim() === ''
                    ? null
                    : form.data.description,
            is_all_day: form.data.is_all_day,
            starts_at: form.data.is_all_day ? null : form.data.starts_at,
            ends_at: form.data.is_all_day ? null : form.data.ends_at,
            start_date: form.data.is_all_day ? form.data.start_date : null,
            end_date: form.data.is_all_day ? form.data.end_date : null,
            project_id:
                form.data.project_id === ''
                    ? null
                    : Number(form.data.project_id),
            task_id:
                form.data.task_id === '' ? null : Number(form.data.task_id),
            recurrence_rule:
                editingEvent && editScope === 'single' ? null : rule,
            recurrence_until:
                editingEvent && editScope === 'single'
                    ? null
                    : form.data.recurrence_until === ''
                      ? null
                      : form.data.recurrence_until,
            edit_scope: editScope,
            recurrence_id: form.data.recurrence_id || null,
        };

        if (editingEvent) {
            form.transform(() => payload);
            form.patch(
                CalendarEventController.update.url(editingEvent.series_id),
                {
                    preserveScroll: true,
                    onSuccess: () => setDialogOpen(false),
                },
            );

            return;
        }

        form.transform(() => payload);
        form.post(CalendarEventController.store.url(), {
            preserveScroll: true,
            onSuccess: () => setDialogOpen(false),
        });
    };

    const handleConfirmDelete = async () => {
        if (!editingEvent) {
            return;
        }

        if (repositories) {
            const seriesUuid =
                editingEvent.series_uuid ??
                (
                    await repositories.calendar.getByServerId(
                        editingEvent.series_id,
                    )
                )?.uuid;

            if (seriesUuid) {
                if (
                    editingEvent.is_recurring &&
                    deleteScope === 'single' &&
                    editingEvent.recurrence_id
                ) {
                    if (editingEvent.is_override && editingEvent.uuid) {
                        await repositories.calendar.deleteLocal(
                            editingEvent.uuid,
                        );
                    }
                    await repositories.calendar.excludeSingleOccurrence(
                        seriesUuid,
                        editingEvent.recurrence_id,
                    );
                } else {
                    await repositories.calendar.deleteLocal(seriesUuid);
                }
                notifyLocalMutation();
                toast.success('Agenda berhasil dihapus.');
                setDeleteDialogOpen(false);
                setDialogOpen(false);
                return;
            }
        }

        router.delete(
            CalendarEventController.destroy.url(editingEvent.series_id),
            {
                data: {
                    edit_scope: deleteScope,
                    recurrence_id: editingEvent.recurrence_id,
                },
                preserveScroll: true,
                onSuccess: () => {
                    setDeleteDialogOpen(false);
                    setDialogOpen(false);
                },
            },
        );
    };

    const rangeLabel = useMemo(() => {
        const start = parseDateKey(range_start);
        const end = parseDateKey(range_end);

        if (view === 'day') {
            return start.toLocaleDateString('id-ID', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric',
            });
        }

        if (view === 'month') {
            return parseDateKey(active_date).toLocaleDateString('id-ID', {
                month: 'long',
                year: 'numeric',
            });
        }

        const startText = start.toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'short',
        });
        const endText = end.toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });

        return `${startText} – ${endText}`;
    }, [active_date, range_end, range_start, view]);

    const dayColumns = view === 'day' ? [active_date] : visibleDays;
    const hourRowHeight = 52;
    const firstHourMinutes = HOURS[0] * 60;

    const getEventsForDay = (dayKey: string) =>
        timedEvents.filter((event) => {
            const eventDate = event.starts_at_local?.slice(0, 10);

            return eventDate === dayKey;
        });

    const getAllDayForDay = (dayKey: string) =>
        allDayEvents.filter((event) => {
            const start = event.start_date ?? '';
            const end = event.end_date ?? start;

            return start <= dayKey && dayKey <= end;
        });

    const getDeadlinesForDay = (dayKey: string) =>
        task_deadlines.filter((task) => task.due_date_local === dayKey);

    return (
        <>
            <Head title="Calendar" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                {/* Header & View Controls */}
                <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight text-foreground">
                            Kalender & Agenda
                        </h1>
                        <p className="text-sm text-muted-foreground tabular-nums">
                            {rangeLabel}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <div className="inline-flex items-center rounded-lg border border-border bg-muted/40 p-0.5">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-8 px-2"
                                onClick={() => shiftDate(-1)}
                                aria-label="Periode sebelumnya"
                            >
                                <ChevronLeft className="size-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-8 px-3 text-xs font-medium"
                                onClick={() =>
                                    navigateCalendar(
                                        view,
                                        toDateKey(new Date()),
                                    )
                                }
                            >
                                Hari Ini
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-8 px-2"
                                onClick={() => shiftDate(1)}
                                aria-label="Periode berikutnya"
                            >
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>

                        <div className="inline-flex items-center rounded-lg border border-border bg-muted/40 p-0.5">
                            {(
                                [
                                    { key: 'day', label: 'Harian' },
                                    { key: 'week', label: 'Mingguan' },
                                    { key: 'month', label: 'Bulanan' },
                                ] as const
                            ).map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() =>
                                        navigateCalendar(
                                            option.key,
                                            active_date,
                                        )
                                    }
                                    className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                        view === option.key
                                            ? 'bg-card text-foreground shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>

                        <Button
                            type="button"
                            onClick={() => openCreateModal(active_date, 9)}
                        >
                            <Plus className="size-4" />
                            Tambah Agenda
                        </Button>
                    </div>
                </div>

                {/* Non-blocking Conflict Warning Banner */}
                {(conflict_warnings.length > 0 || conflictCount > 0) && (
                    <div
                        role="status"
                        className="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50/70 px-4 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" />
                        <div className="space-y-1">
                            <p className="font-medium">
                                Terdeteksi bentrok jadwal (agenda tetap
                                disimpan).
                            </p>
                            {conflict_warnings.length > 0 ? (
                                <p className="text-xs text-amber-800 tabular-nums dark:text-amber-200">
                                    Beririsan dengan:{' '}
                                    {conflict_warnings
                                        .map(
                                            (item) =>
                                                `${item.title} (${item.starts_at_local?.slice(11, 16) ?? ''}–${item.ends_at_local?.slice(11, 16) ?? ''})`,
                                        )
                                        .join(', ')}
                                </p>
                            ) : (
                                <p className="text-xs text-amber-800 tabular-nums dark:text-amber-200">
                                    Ada {conflictCount} blok agenda pada rentang
                                    ini yang waktunya saling beririsan.
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {/* Month View */}
                {view === 'month' && (
                    <div className="overflow-hidden rounded-xl border border-border bg-card">
                        <div className="grid grid-cols-7 border-b border-border bg-muted/30 text-center text-xs font-medium text-muted-foreground">
                            {[
                                'Sen',
                                'Sel',
                                'Rab',
                                'Kam',
                                'Jum',
                                'Sab',
                                'Min',
                            ].map((day) => (
                                <div key={day} className="py-2.5">
                                    {day}
                                </div>
                            ))}
                        </div>
                        <div className="grid grid-cols-7 divide-x divide-y divide-border">
                            {visibleDays.map((dayKey) => {
                                const dayEvents = [
                                    ...getAllDayForDay(dayKey),
                                    ...getEventsForDay(dayKey),
                                ];
                                const dayDeadlines = getDeadlinesForDay(dayKey);
                                const isCurrentMonth =
                                    dayKey.slice(0, 7) ===
                                    active_date.slice(0, 7);
                                const isToday =
                                    dayKey === toDateKey(new Date());

                                return (
                                    <div
                                        key={dayKey}
                                        className={`min-h-28 p-2 transition-colors hover:bg-muted/20 ${
                                            isCurrentMonth
                                                ? 'bg-card'
                                                : 'bg-muted/20 text-muted-foreground'
                                        }`}
                                    >
                                        <div className="mb-1.5 flex items-center justify-between">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    navigateCalendar(
                                                        'day',
                                                        dayKey,
                                                    )
                                                }
                                                className={`inline-flex size-6 items-center justify-center rounded-full text-xs font-medium tabular-nums ${
                                                    isToday
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'hover:bg-muted'
                                                }`}
                                            >
                                                {Number(dayKey.slice(8, 10))}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    openCreateModal(dayKey, 9)
                                                }
                                                className="rounded p-0.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:bg-muted hover:text-foreground sm:opacity-60"
                                                aria-label={`Tambah agenda ${dayKey}`}
                                            >
                                                <Plus className="size-3.5" />
                                            </button>
                                        </div>

                                        <div className="space-y-1">
                                            {dayEvents
                                                .slice(0, 3)
                                                .map((event) => (
                                                    <button
                                                        key={
                                                            event.occurrence_key
                                                        }
                                                        type="button"
                                                        onClick={() =>
                                                            openEditModal(event)
                                                        }
                                                        className={`flex w-full items-center justify-between gap-1 rounded border px-1.5 py-0.5 text-left text-[11px] transition-colors ${
                                                            event.has_conflict
                                                                ? 'border-amber-400 bg-amber-50 text-amber-950'
                                                                : 'border-border bg-background text-foreground hover:border-primary/50'
                                                        }`}
                                                    >
                                                        <span className="truncate font-medium">
                                                            {!event.is_all_day &&
                                                            event.starts_at_local
                                                                ? `${event.starts_at_local.slice(11, 16)} `
                                                                : ''}
                                                            {event.title}
                                                        </span>
                                                        {event.is_recurring && (
                                                            <Repeat className="size-2.5 shrink-0 text-muted-foreground" />
                                                        )}
                                                    </button>
                                                ))}

                                            {dayDeadlines
                                                .slice(0, 2)
                                                .map((task) => (
                                                    <div
                                                        key={`deadline-${task.id}`}
                                                        className={`truncate rounded border px-1.5 py-0.5 text-[11px] ${TASK_PRIORITY_BADGE_CLASS[task.priority]}`}
                                                        title={`Deadline tugas: ${task.title}`}
                                                    >
                                                        Deadline: {task.title}
                                                    </div>
                                                ))}

                                            {dayEvents.length +
                                                dayDeadlines.length >
                                                5 && (
                                                <p className="text-[10px] text-muted-foreground tabular-nums">
                                                    +
                                                    {dayEvents.length +
                                                        dayDeadlines.length -
                                                        5}{' '}
                                                    lainnya
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* Week & Day View */}
                {view !== 'month' && (
                    <>
                        {/* Desktop Time Grid */}
                        <div className="hidden max-h-[700px] overflow-y-auto rounded-xl border border-border bg-card md:block">
                            {/* Sticky Header & All-day Strip inside the same scroll container to prevent scrollbar alignment drift */}
                            <div className="sticky top-0 z-10 bg-card">
                                {/* Day Columns Header */}
                                <div
                                    className="grid border-b border-border bg-muted/20"
                                    style={{
                                        gridTemplateColumns: `64px repeat(${dayColumns.length}, minmax(0, 1fr))`,
                                    }}
                                >
                                    <div className="border-r border-border p-2 text-[11px] font-medium text-muted-foreground">
                                        Jam
                                    </div>
                                    {dayColumns.map((dayKey) => {
                                        const header = formatDayHeader(dayKey);
                                        const isToday =
                                            dayKey === toDateKey(new Date());

                                        return (
                                            <div
                                                key={dayKey}
                                                className="border-r border-border px-3 py-2.5 last:border-r-0"
                                            >
                                                <div className="flex items-center justify-between">
                                                    <span className="text-xs font-medium text-muted-foreground">
                                                        {header.weekday}
                                                    </span>
                                                    <span
                                                        className={`inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums ${
                                                            isToday
                                                                ? 'bg-primary text-primary-foreground'
                                                                : 'text-foreground'
                                                        }`}
                                                    >
                                                        {header.dayNum}
                                                    </span>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {/* All-day & Task Deadlines Strip */}
                                <div
                                    className="grid border-b border-border bg-background/60"
                                    style={{
                                        gridTemplateColumns: `64px repeat(${dayColumns.length}, minmax(0, 1fr))`,
                                    }}
                                >
                                    <div className="border-r border-border px-2 py-2 text-right text-[11px] font-medium text-muted-foreground">
                                        All-day
                                    </div>
                                    {dayColumns.map((dayKey) => {
                                        const dayAllDay =
                                            getAllDayForDay(dayKey);
                                        const dayDeadlines =
                                            getDeadlinesForDay(dayKey);

                                        return (
                                            <div
                                                key={`strip-${dayKey}`}
                                                className="min-h-10 space-y-1 border-r border-border p-1.5 last:border-r-0"
                                            >
                                                {dayAllDay.map((event) => (
                                                    <button
                                                        key={
                                                            event.occurrence_key
                                                        }
                                                        type="button"
                                                        onClick={() =>
                                                            openEditModal(event)
                                                        }
                                                        className="flex w-full items-center justify-between gap-1 rounded-md border border-primary/30 bg-primary/10 px-2 py-1 text-left text-xs font-medium text-foreground hover:bg-primary/15"
                                                    >
                                                        <span className="truncate">
                                                            {event.title}
                                                        </span>
                                                        {event.is_recurring && (
                                                            <Repeat className="size-3 shrink-0 text-muted-foreground" />
                                                        )}
                                                    </button>
                                                ))}

                                                {dayDeadlines.map((task) => (
                                                    <div
                                                        key={`task-strip-${task.id}`}
                                                        className={`flex items-center justify-between gap-1 rounded-md border px-2 py-1 text-xs ${TASK_PRIORITY_BADGE_CLASS[task.priority]}`}
                                                    >
                                                        <span className="truncate font-medium">
                                                            {task.title}
                                                        </span>
                                                        <span className="shrink-0 text-[10px] tabular-nums">
                                                            {task.due_at_local?.slice(
                                                                11,
                                                                16,
                                                            ) ?? ''}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Hourly Grid */}
                            <div>
                                <div
                                    className="relative grid"
                                    style={{
                                        gridTemplateColumns: `64px repeat(${dayColumns.length}, minmax(0, 1fr))`,
                                    }}
                                >
                                    {/* Time Labels Column */}
                                    <div className="border-r border-border bg-muted/10">
                                        {HOURS.map((hour) => (
                                            <div
                                                key={hour}
                                                style={{
                                                    height: `${hourRowHeight}px`,
                                                }}
                                                className="border-b border-border/60 px-2 pt-1 text-right text-[11px] text-muted-foreground tabular-nums"
                                            >
                                                {String(hour).padStart(2, '0')}
                                                :00
                                            </div>
                                        ))}
                                    </div>

                                    {/* Day Columns */}
                                    {dayColumns.map((dayKey) => {
                                        const dayEvents =
                                            getEventsForDay(dayKey);

                                        return (
                                            <div
                                                key={`col-${dayKey}`}
                                                className="relative border-r border-border last:border-r-0"
                                                style={{
                                                    height: `${HOURS.length * hourRowHeight}px`,
                                                }}
                                            >
                                                {HOURS.map((hour) => (
                                                    <button
                                                        key={hour}
                                                        type="button"
                                                        onClick={() =>
                                                            openCreateModal(
                                                                dayKey,
                                                                hour,
                                                            )
                                                        }
                                                        style={{
                                                            height: `${hourRowHeight}px`,
                                                        }}
                                                        className="block w-full border-b border-border/60 text-left transition-colors hover:bg-muted/25"
                                                        aria-label={`Tambah agenda ${dayKey} jam ${hour}:00`}
                                                    />
                                                ))}

                                                {dayEvents.map((event) => {
                                                    const startMins = Math.max(
                                                        parseMinutesFromLocal(
                                                            event.starts_at_local,
                                                        ) - firstHourMinutes,
                                                        0,
                                                    );
                                                    const endMins = Math.max(
                                                        parseMinutesFromLocal(
                                                            event.ends_at_local,
                                                        ) - firstHourMinutes,
                                                        startMins + 30,
                                                    );
                                                    const topPx =
                                                        (startMins / 60) *
                                                        hourRowHeight;
                                                    const heightPx = Math.max(
                                                        ((endMins - startMins) /
                                                            60) *
                                                            hourRowHeight,
                                                        28,
                                                    );

                                                    return (
                                                        <button
                                                            key={
                                                                event.occurrence_key
                                                            }
                                                            type="button"
                                                            onClick={() =>
                                                                openEditModal(
                                                                    event,
                                                                )
                                                            }
                                                            style={{
                                                                top: `${topPx}px`,
                                                                height: `${heightPx}px`,
                                                            }}
                                                            className={`absolute right-1 left-1 overflow-hidden rounded-lg border px-2 py-1 text-left shadow-xs transition-colors ${
                                                                event.has_conflict
                                                                    ? 'border-amber-400 bg-amber-50/95 text-amber-950 hover:border-amber-500'
                                                                    : 'border-border bg-card text-foreground hover:border-primary/60'
                                                            }`}
                                                        >
                                                            <div className="flex items-center justify-between gap-1">
                                                                <span className="truncate text-xs font-semibold">
                                                                    {
                                                                        event.title
                                                                    }
                                                                </span>
                                                                <div className="flex shrink-0 items-center gap-1">
                                                                    {event.has_conflict && (
                                                                        <AlertTriangle className="size-3 text-amber-600" />
                                                                    )}
                                                                    {event.is_recurring && (
                                                                        <Repeat className="size-3 text-muted-foreground" />
                                                                    )}
                                                                </div>
                                                            </div>
                                                            <p className="text-[11px] text-muted-foreground tabular-nums">
                                                                {formatTimeRange(
                                                                    event,
                                                                )}
                                                            </p>
                                                            {event.project && (
                                                                <p className="truncate text-[10px] text-primary">
                                                                    {
                                                                        event
                                                                            .project
                                                                            .name
                                                                    }
                                                                </p>
                                                            )}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>

                        {/* Mobile Agenda List Fallback */}
                        <div className="space-y-3 md:hidden">
                            {dayColumns.map((dayKey) => {
                                const dayAllDay = getAllDayForDay(dayKey);
                                const dayEvents = getEventsForDay(dayKey);
                                const dayDeadlines = getDeadlinesForDay(dayKey);

                                return (
                                    <div
                                        key={`mobile-${dayKey}`}
                                        className="rounded-xl border border-border bg-card p-3.5"
                                    >
                                        <div className="mb-2 flex items-center justify-between">
                                            <p className="text-sm font-semibold text-foreground">
                                                {formatDateDisplay(dayKey)}
                                            </p>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 px-2 text-xs"
                                                onClick={() =>
                                                    openCreateModal(dayKey, 9)
                                                }
                                            >
                                                <Plus className="size-3.5" />
                                                Tambah
                                            </Button>
                                        </div>

                                        {dayAllDay.length === 0 &&
                                        dayEvents.length === 0 &&
                                        dayDeadlines.length === 0 ? (
                                            <p className="text-xs text-muted-foreground">
                                                Belum ada agenda.
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {[
                                                    ...dayAllDay,
                                                    ...dayEvents,
                                                ].map((event) => (
                                                    <button
                                                        key={
                                                            event.occurrence_key
                                                        }
                                                        type="button"
                                                        onClick={() =>
                                                            openEditModal(event)
                                                        }
                                                        className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left ${
                                                            event.has_conflict
                                                                ? 'border-amber-400 bg-amber-50/70'
                                                                : 'border-border bg-background'
                                                        }`}
                                                    >
                                                        <div>
                                                            <p className="text-sm font-medium text-foreground">
                                                                {event.title}
                                                            </p>
                                                            <p className="text-xs text-muted-foreground tabular-nums">
                                                                {formatTimeRange(
                                                                    event,
                                                                )}
                                                            </p>
                                                        </div>
                                                        <div className="flex items-center gap-1.5">
                                                            {event.has_conflict && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-amber-400 text-amber-800"
                                                                >
                                                                    Bentrok
                                                                </Badge>
                                                            )}
                                                            {event.is_recurring && (
                                                                <Repeat className="size-3.5 text-muted-foreground" />
                                                            )}
                                                        </div>
                                                    </button>
                                                ))}

                                                {dayDeadlines.map((task) => (
                                                    <div
                                                        key={`m-task-${task.id}`}
                                                        className="flex items-center justify-between rounded-lg border border-border bg-muted/20 px-3 py-2"
                                                    >
                                                        <div>
                                                            <p className="text-xs font-medium text-foreground">
                                                                Deadline:{' '}
                                                                {task.title}
                                                            </p>
                                                            <p className="text-[11px] text-muted-foreground tabular-nums">
                                                                {task.due_at_local?.slice(
                                                                    11,
                                                                    16,
                                                                ) ?? ''}
                                                            </p>
                                                        </div>
                                                        <Badge
                                                            variant="outline"
                                                            className={
                                                                TASK_PRIORITY_BADGE_CLASS[
                                                                    task
                                                                        .priority
                                                                ]
                                                            }
                                                        >
                                                            {
                                                                TASK_PRIORITY_LABELS[
                                                                    task
                                                                        .priority
                                                                ]
                                                            }
                                                        </Badge>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}
            </div>

            {/* Create / Edit Event Modal */}
            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingEvent ? 'Edit Agenda' : 'Tambah Agenda'}
                        </DialogTitle>
                        <DialogDescription>
                            Atur jadwal kuliah, blok belajar, atau kegiatan
                            berulang Anda.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleSubmit} className="space-y-4">
                        {editingEvent && editingEvent.is_recurring && (
                            <div className="space-y-2 rounded-lg border border-border bg-muted/30 p-3">
                                <Label className="text-xs font-semibold">
                                    Cakupan Perubahan Agenda Berulang
                                </Label>
                                <div className="grid grid-cols-2 gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setEditScope('single')}
                                        className={`rounded-md border px-3 py-2 text-left text-xs font-medium transition-colors ${
                                            editScope === 'single'
                                                ? 'border-primary bg-primary/10 text-foreground'
                                                : 'border-border bg-card text-muted-foreground'
                                        }`}
                                    >
                                        Hanya kejadian ini
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setEditScope('series')}
                                        className={`rounded-md border px-3 py-2 text-left text-xs font-medium transition-colors ${
                                            editScope === 'series'
                                                ? 'border-primary bg-primary/10 text-foreground'
                                                : 'border-border bg-card text-muted-foreground'
                                        }`}
                                    >
                                        Seluruh rangkaian
                                    </button>
                                </div>
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="event-title">Judul Agenda</Label>
                            <Input
                                id="event-title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="Contoh: Kuliah Pemrograman Web"
                                required
                            />
                            {form.errors.title && (
                                <p className="text-xs text-destructive">
                                    {form.errors.title}
                                </p>
                            )}
                        </div>

                        <div className="flex items-center justify-between rounded-lg border border-border px-3 py-2.5">
                            <div className="flex items-center gap-2">
                                <CalendarIcon className="size-4 text-muted-foreground" />
                                <Label
                                    htmlFor="event-all-day"
                                    className="cursor-pointer text-sm"
                                >
                                    Agenda sepanjang hari (All-day)
                                </Label>
                            </div>
                            <input
                                id="event-all-day"
                                type="checkbox"
                                checked={form.data.is_all_day}
                                onChange={(e) =>
                                    form.setData('is_all_day', e.target.checked)
                                }
                                className="size-4 accent-primary"
                            />
                        </div>

                        {form.data.is_all_day ? (
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="event-start-date">
                                        Tanggal Mulai
                                    </Label>
                                    <Input
                                        id="event-start-date"
                                        type="date"
                                        value={form.data.start_date}
                                        onChange={(e) =>
                                            form.setData(
                                                'start_date',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    {form.errors.start_date && (
                                        <p className="text-xs text-destructive">
                                            {form.errors.start_date}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="event-end-date">
                                        Tanggal Selesai
                                    </Label>
                                    <Input
                                        id="event-end-date"
                                        type="date"
                                        value={form.data.end_date}
                                        onChange={(e) =>
                                            form.setData(
                                                'end_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.end_date && (
                                        <p className="text-xs text-destructive">
                                            {form.errors.end_date}
                                        </p>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="event-starts-at">
                                        Mulai
                                    </Label>
                                    <Input
                                        id="event-starts-at"
                                        type="datetime-local"
                                        value={form.data.starts_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'starts_at',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    {form.errors.starts_at && (
                                        <p className="text-xs text-destructive">
                                            {form.errors.starts_at}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="event-ends-at">
                                        Selesai
                                    </Label>
                                    <Input
                                        id="event-ends-at"
                                        type="datetime-local"
                                        value={form.data.ends_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'ends_at',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    {form.errors.ends_at && (
                                        <p className="text-xs text-destructive">
                                            {form.errors.ends_at}
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}

                        {(!editingEvent || editScope === 'series') && (
                            <div className="space-y-3 rounded-lg border border-border p-3">
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="event-recurrence">
                                            Pengulangan
                                        </Label>
                                        <Select
                                            value={recurrenceFreq}
                                            onValueChange={(value) =>
                                                setRecurrenceFreq(
                                                    value as
                                                        | 'none'
                                                        | 'DAILY'
                                                        | 'WEEKLY'
                                                        | 'MONTHLY',
                                                )
                                            }
                                        >
                                            <SelectTrigger id="event-recurrence">
                                                <SelectValue placeholder="Tidak berulang" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    Tidak berulang
                                                </SelectItem>
                                                <SelectItem value="DAILY">
                                                    Harian
                                                </SelectItem>
                                                <SelectItem value="WEEKLY">
                                                    Mingguan
                                                </SelectItem>
                                                <SelectItem value="MONTHLY">
                                                    Bulanan
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {recurrenceFreq !== 'none' && (
                                        <div className="space-y-1.5">
                                            <Label htmlFor="event-recurrence-until">
                                                Berulang Sampai (Opsional)
                                            </Label>
                                            <Input
                                                id="event-recurrence-until"
                                                type="date"
                                                value={
                                                    form.data.recurrence_until
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'recurrence_until',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    )}
                                </div>

                                {recurrenceFreq === 'WEEKLY' && (
                                    <div className="space-y-1.5">
                                        <Label className="text-xs text-muted-foreground">
                                            Hari Pengulangan Mingguan
                                        </Label>
                                        <div className="flex flex-wrap gap-1.5">
                                            {WEEKDAY_CODES.map((day) => {
                                                const active =
                                                    recurrenceByDay.includes(
                                                        day.code,
                                                    );

                                                return (
                                                    <button
                                                        key={day.code}
                                                        type="button"
                                                        onClick={() =>
                                                            toggleWeekDay(
                                                                day.code,
                                                            )
                                                        }
                                                        className={`rounded-md border px-2.5 py-1 text-xs font-medium transition-colors ${
                                                            active
                                                                ? 'border-primary bg-primary text-primary-foreground'
                                                                : 'border-border bg-card text-foreground hover:bg-muted'
                                                        }`}
                                                    >
                                                        {day.label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="event-project">
                                    Project (Opsional)
                                </Label>
                                <Select
                                    value={form.data.project_id || 'none'}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'project_id',
                                            value === 'none' ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger id="event-project">
                                        <SelectValue placeholder="Pilih project" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Tanpa project
                                        </SelectItem>
                                        {projects.map((project) => (
                                            <SelectItem
                                                key={project.id}
                                                value={String(project.id)}
                                            >
                                                {project.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="event-task">
                                    Tautkan Task (Opsional)
                                </Label>
                                <Select
                                    value={form.data.task_id || 'none'}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'task_id',
                                            value === 'none' ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger id="event-task">
                                        <SelectValue placeholder="Pilih task" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Tanpa task
                                        </SelectItem>
                                        {tasks_for_select.map((task) => (
                                            <SelectItem
                                                key={task.id}
                                                value={String(task.id)}
                                            >
                                                {task.title}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="event-description">
                                Catatan (Opsional)
                            </Label>
                            <textarea
                                id="event-description"
                                rows={3}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Ruangan, link pertemuan, atau detail persiapan"
                                className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                        </div>

                        <DialogFooter className="flex flex-row items-center justify-between gap-2 sm:justify-between">
                            {editingEvent ? (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() => setDeleteDialogOpen(true)}
                                >
                                    <Trash2 className="size-4" />
                                    Hapus
                                </Button>
                            ) : (
                                <span />
                            )}

                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setDialogOpen(false)}
                                >
                                    Batal
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    <Clock className="size-4" />
                                    {editingEvent
                                        ? 'Simpan Perubahan'
                                        : 'Simpan Agenda'}
                                </Button>
                            </div>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation Dialog */}
            <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Hapus Agenda?</DialogTitle>
                        <DialogDescription>
                            {editingEvent?.is_recurring
                                ? 'Pilih apakah Anda ingin menghapus kejadian ini saja atau seluruh rangkaian agenda berulang.'
                                : `Agenda "${editingEvent?.title ?? ''}" akan dihapus dari kalender Anda.`}
                        </DialogDescription>
                    </DialogHeader>

                    {editingEvent?.is_recurring && (
                        <div className="grid grid-cols-2 gap-2 py-2">
                            <button
                                type="button"
                                onClick={() => setDeleteScope('single')}
                                className={`rounded-md border px-3 py-2 text-left text-xs font-medium ${
                                    deleteScope === 'single'
                                        ? 'border-destructive bg-destructive/10 text-foreground'
                                        : 'border-border bg-card text-muted-foreground'
                                }`}
                            >
                                Hanya kejadian ini
                            </button>
                            <button
                                type="button"
                                onClick={() => setDeleteScope('series')}
                                className={`rounded-md border px-3 py-2 text-left text-xs font-medium ${
                                    deleteScope === 'series'
                                        ? 'border-destructive bg-destructive/10 text-foreground'
                                        : 'border-border bg-card text-muted-foreground'
                                }`}
                            >
                                Seluruh rangkaian
                            </button>
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDeleteDialogOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={handleConfirmDelete}
                        >
                            Hapus Agenda
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
