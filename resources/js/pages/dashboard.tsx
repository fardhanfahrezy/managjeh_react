import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Calendar as CalendarIcon,
    CheckCircle2,
    Circle,
    Clock,
    FolderKanban,
    MessageSquare,
    Plus,
    Repeat,
    Sparkles,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import TaskController from '@/actions/App/Http/Controllers/TaskController';
import { useOffline } from '@/components/offline/offline-provider';
import {
    formatDateDisplay,
    formatDueAtDisplay,
    TASK_PRIORITY_BADGE_CLASS,
    TASK_PRIORITY_LABELS,
} from '@/components/tasks/task-constants';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { buildLocalDashboardData } from '@/lib/offline/dashboard/local-dashboard-aggregator';
import { dashboard } from '@/routes';
import { index as calendarIndex } from '@/routes/calendar';
import { edit as profileEdit } from '@/routes/profile';
import {
    index as projectsIndex,
    show as projectsShow,
} from '@/routes/projects';
import { index as tasksIndex } from '@/routes/tasks';
import type { DashboardData, DashboardTaskItem } from '@/types/models';

export default function Dashboard(serverProps: DashboardData) {
    const {
        db,
        lease,
        repositories,
        hydratedFromServer,
        isOnline,
        dataVersion,
        notifyLocalMutation,
    } = useOffline();

    const [localData, setLocalData] = useState<DashboardData | null>(null);
    const [completingTaskId, setCompletingTaskId] = useState<number | null>(
        null,
    );

    useEffect(() => {
        if (!db || !lease || (!hydratedFromServer && isOnline)) {
            return;
        }

        let cancelled = false;
        void buildLocalDashboardData(db, lease).then((computed) => {
            if (!cancelled) {
                setLocalData(computed);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [db, lease, hydratedFromServer, isOnline, dataVersion]);

    const {
        greeting,
        local_date_label,
        timezone,
        today_tasks,
        upcoming_tasks,
        today_events,
        active_projects,
        ai_priorities,
        whatsapp_status,
        completed_summary,
    } = localData ?? serverProps;

    const handleCompleteTask = async (task: DashboardTaskItem) => {
        setCompletingTaskId(task.id);

        if (repositories) {
            try {
                const targetUuid =
                    task.uuid ??
                    (await repositories.tasks.getByServerId(task.id))?.uuid;
                if (targetUuid) {
                    await repositories.tasks.updateTask(
                        targetUuid,
                        { status: 'completed' },
                        timezone,
                    );
                    notifyLocalMutation();
                    toast.success(
                        isOnline
                            ? 'Tugas ditandai selesai.'
                            : 'Tersimpan di perangkat (akan disinkronkan saat online).',
                    );
                    setCompletingTaskId(null);
                    return;
                }
            } catch {
                // Fall back to online Inertia request if local lookup misses.
            }
        }

        router.patch(
            TaskController.update.url(task.id),
            { status: 'completed' },
            {
                preserveScroll: true,
                onFinish: () => setCompletingTaskId(null),
            },
        );
    };

    const overdueCount = upcoming_tasks.filter((t) => t.is_overdue).length;

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                {/* 1. Greeting & Local Date */}
                <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight text-foreground">
                                {greeting}
                            </h1>
                            <Badge
                                variant="outline"
                                className="text-[11px] font-normal text-muted-foreground"
                            >
                                {timezone}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {local_date_label}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={calendarIndex()}>
                                <CalendarIcon className="size-4" />
                                Buka Kalender
                            </Link>
                        </Button>
                        <Button asChild size="sm">
                            <Link href={tasksIndex()}>
                                <Plus className="size-4" />
                                Kelola Tugas
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* 8. Completed & Overview Summary Strip */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div className="rounded-xl border border-border bg-card p-4">
                        <p className="text-xs font-medium text-muted-foreground">
                            Tugas Aktif
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-foreground tabular-nums">
                            {completed_summary.active_count}
                        </p>
                    </div>

                    <div className="rounded-xl border border-border bg-card p-4">
                        <p className="text-xs font-medium text-muted-foreground">
                            Selesai Hari Ini
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-primary tabular-nums">
                            {completed_summary.today_count}
                        </p>
                    </div>

                    <div className="rounded-xl border border-border bg-card p-4">
                        <p className="text-xs font-medium text-muted-foreground">
                            Selesai 7 Hari
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-foreground tabular-nums">
                            {completed_summary.last_7_days_count}
                        </p>
                    </div>

                    <div className="rounded-xl border border-border bg-card p-4">
                        <p className="text-xs font-medium text-muted-foreground">
                            Agenda Hari Ini
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-foreground tabular-nums">
                            {today_events.length}
                        </p>
                    </div>
                </div>

                {/* Main 2-Column Workspace Grid */}
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-12">
                    {/* Left Column (7 cols): Tasks & Projects */}
                    <div className="space-y-5 lg:col-span-7">
                        {/* 2. Today's Tasks */}
                        <section className="rounded-xl border border-border bg-card p-5">
                            <div className="mb-4 flex items-center justify-between">
                                <div>
                                    <h2 className="text-base font-semibold text-foreground">
                                        Tugas Hari Ini
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        Tugas yang sedang dikerjakan atau jatuh
                                        tempo hari ini.
                                    </p>
                                </div>
                                <Link
                                    href={tasksIndex()}
                                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                >
                                    Semua tugas
                                    <ArrowRight className="size-3.5" />
                                </Link>
                            </div>

                            {today_tasks.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-border bg-muted/20 p-6 text-center">
                                    <CheckCircle2 className="mx-auto size-6 text-primary/70" />
                                    <p className="mt-2 text-sm font-medium text-foreground">
                                        Tidak ada tugas mendesak hari ini
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Tambahkan tugas baru atau mulai kerjakan
                                        backlog Anda.
                                    </p>
                                </div>
                            ) : (
                                <div className="divide-y divide-border rounded-lg border border-border">
                                    {today_tasks.map((task) => (
                                        <div
                                            key={task.id}
                                            className="flex items-start justify-between gap-3 p-3 transition-colors hover:bg-muted/20"
                                        >
                                            <div className="flex min-w-0 items-start gap-2.5">
                                                <button
                                                    type="button"
                                                    disabled={
                                                        completingTaskId ===
                                                        task.id
                                                    }
                                                    onClick={() =>
                                                        handleCompleteTask(task)
                                                    }
                                                    aria-label={`Tandai "${task.title}" selesai`}
                                                    className="mt-0.5 text-muted-foreground transition-colors hover:text-primary disabled:opacity-50"
                                                >
                                                    <Circle className="size-4" />
                                                </button>
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-foreground">
                                                        {task.title}
                                                    </p>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                                        {task.project && (
                                                            <span className="rounded bg-muted px-1.5 py-0.5 text-[11px] text-foreground">
                                                                {
                                                                    task.project
                                                                        .name
                                                                }
                                                            </span>
                                                        )}
                                                        {task.due_at && (
                                                            <span className="inline-flex items-center gap-1 tabular-nums">
                                                                <Clock className="size-3" />
                                                                {formatDueAtDisplay(
                                                                    task.due_at,
                                                                    timezone,
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>

                                            <Badge
                                                variant="outline"
                                                className={`shrink-0 ${TASK_PRIORITY_BADGE_CLASS[task.priority]}`}
                                            >
                                                {
                                                    TASK_PRIORITY_LABELS[
                                                        task.priority
                                                    ]
                                                }
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>

                        {/* 3. Upcoming & Overdue Deadlines */}
                        <section className="rounded-xl border border-border bg-card p-5">
                            <div className="mb-4 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <h2 className="text-base font-semibold text-foreground">
                                        Mendekati Deadline
                                    </h2>
                                    {overdueCount > 0 && (
                                        <Badge
                                            variant="destructive"
                                            className="tabular-nums"
                                        >
                                            {overdueCount} Terlewat
                                        </Badge>
                                    )}
                                </div>
                                <Link
                                    href={tasksIndex()}
                                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                >
                                    Lihat daftar
                                    <ArrowRight className="size-3.5" />
                                </Link>
                            </div>

                            {upcoming_tasks.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-border bg-muted/20 p-4 text-center text-xs text-muted-foreground">
                                    Tidak ada deadline dalam 7 hari ke depan.
                                </p>
                            ) : (
                                <div className="divide-y divide-border rounded-lg border border-border">
                                    {upcoming_tasks.map((task) => (
                                        <div
                                            key={task.id}
                                            className="flex items-center justify-between gap-3 p-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {task.title}
                                                </p>
                                                <p className="mt-0.5 text-xs text-muted-foreground tabular-nums">
                                                    {task.due_at
                                                        ? formatDueAtDisplay(
                                                              task.due_at,
                                                              timezone,
                                                          )
                                                        : 'Tanpa tenggat'}
                                                    {task.project
                                                        ? ` · ${task.project.name}`
                                                        : ''}
                                                </p>
                                            </div>

                                            <Badge
                                                variant={
                                                    task.is_overdue
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                                className={
                                                    task.is_overdue
                                                        ? 'shrink-0'
                                                        : `shrink-0 ${TASK_PRIORITY_BADGE_CLASS[task.priority]}`
                                                }
                                            >
                                                {task.is_overdue
                                                    ? 'Terlewat'
                                                    : TASK_PRIORITY_LABELS[
                                                          task.priority
                                                      ]}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>

                        {/* 5. Active Projects Progress */}
                        <section className="rounded-xl border border-border bg-card p-5">
                            <div className="mb-4 flex items-center justify-between">
                                <div>
                                    <h2 className="text-base font-semibold text-foreground">
                                        Proyek Aktif
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        Progres penyelesaian tugas per proyek.
                                    </p>
                                </div>
                                <Link
                                    href={projectsIndex()}
                                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                >
                                    Semua proyek
                                    <ArrowRight className="size-3.5" />
                                </Link>
                            </div>

                            {active_projects.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-border bg-muted/20 p-5 text-center">
                                    <FolderKanban className="mx-auto size-6 text-muted-foreground" />
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Belum ada proyek aktif.
                                    </p>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {active_projects.map((project) => (
                                        <Link
                                            key={project.id}
                                            href={projectsShow(project.id)}
                                            className="block rounded-lg border border-border p-3.5 transition-colors hover:border-primary/50 hover:bg-muted/20"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {project.name}
                                                </p>
                                                <span className="shrink-0 text-xs font-semibold text-foreground tabular-nums">
                                                    {project.progress}%
                                                </span>
                                            </div>
                                            <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-primary transition-all"
                                                    style={{
                                                        width: `${project.progress}%`,
                                                    }}
                                                />
                                            </div>
                                            <div className="mt-2 flex items-center justify-between text-[11px] text-muted-foreground tabular-nums">
                                                <span>
                                                    {
                                                        project.completed_tasks_count
                                                    }
                                                    /
                                                    {
                                                        project.non_cancelled_tasks_count
                                                    }{' '}
                                                    tugas selesai
                                                </span>
                                                {project.due_date && (
                                                    <span>
                                                        Target{' '}
                                                        {formatDateDisplay(
                                                            project.due_date,
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>

                    {/* Right Column (5 cols): Calendar, AI Priorities, WhatsApp */}
                    <div className="space-y-5 lg:col-span-5">
                        {/* 4. Today's Calendar Events */}
                        <section className="rounded-xl border border-border bg-card p-5">
                            <div className="mb-4 flex items-center justify-between">
                                <div>
                                    <h2 className="text-base font-semibold text-foreground">
                                        Agenda Hari Ini
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        Jadwal kuliah & kegiatan hari ini.
                                    </p>
                                </div>
                                <Link
                                    href={calendarIndex()}
                                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                >
                                    Kalender
                                    <ArrowRight className="size-3.5" />
                                </Link>
                            </div>

                            {today_events.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-border bg-muted/20 p-5 text-center">
                                    <CalendarIcon className="mx-auto size-6 text-muted-foreground" />
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Tidak ada agenda terjadwal hari ini.
                                    </p>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {today_events.map((event) => (
                                        <div
                                            key={event.occurrence_key}
                                            className={`rounded-lg border p-3 ${
                                                event.has_conflict
                                                    ? 'border-amber-400 bg-amber-50/70 dark:border-amber-800 dark:bg-amber-950/30'
                                                    : 'border-border bg-background'
                                            }`}
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="text-sm font-medium text-foreground">
                                                    {event.title}
                                                </p>
                                                <div className="flex shrink-0 items-center gap-1">
                                                    {event.has_conflict && (
                                                        <AlertTriangle className="size-3.5 text-amber-600" />
                                                    )}
                                                    {event.is_recurring && (
                                                        <Repeat className="size-3.5 text-muted-foreground" />
                                                    )}
                                                </div>
                                            </div>
                                            <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                                {event.is_all_day
                                                    ? 'Sepanjang hari'
                                                    : `${event.starts_at_local?.slice(11, 16) ?? '--:--'}–${event.ends_at_local?.slice(11, 16) ?? '--:--'}`}
                                                {event.project
                                                    ? ` · ${event.project.name}`
                                                    : ''}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>

                        {/* 6. Top 3 AI Priorities */}
                        <section className="rounded-xl border border-border bg-card p-5">
                            <div className="mb-3 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Sparkles className="size-4 text-primary" />
                                    <h2 className="text-base font-semibold text-foreground">
                                        3 Prioritas AI Planner
                                    </h2>
                                </div>
                                {ai_priorities?.provider && (
                                    <Badge
                                        variant="outline"
                                        className="text-[11px] uppercase"
                                    >
                                        {ai_priorities.provider}
                                    </Badge>
                                )}
                            </div>

                            {ai_priorities &&
                            ai_priorities.priorities.length > 0 ? (
                                <div className="space-y-2.5">
                                    {ai_priorities.summary && (
                                        <p className="text-xs text-muted-foreground">
                                            {ai_priorities.summary}
                                        </p>
                                    )}
                                    {ai_priorities.priorities
                                        .slice(0, 3)
                                        .map((item, idx) => (
                                            <div
                                                key={`${item.task_id}-${idx}`}
                                                className="rounded-lg border border-border p-3"
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-sm font-medium text-foreground">
                                                        {idx + 1}.{' '}
                                                        {item.task_title ??
                                                            `Task #${item.task_id}`}
                                                    </span>
                                                    <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                                        {item.estimated_minutes}{' '}
                                                        mnt
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {item.reason}
                                                </p>
                                            </div>
                                        ))}
                                </div>
                            ) : (
                                <div className="rounded-lg border border-dashed border-border bg-muted/20 p-4 text-xs text-muted-foreground">
                                    Rekomendasi 3 prioritas harian AI Planner
                                    belum tersedia (akan aktif pada Phase 6).
                                </div>
                            )}
                        </section>

                        {/* 7. WhatsApp Reminder Status */}
                        <section className="rounded-xl border border-border bg-card p-5">
                            <div className="mb-3 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <MessageSquare className="size-4 text-muted-foreground" />
                                    <h2 className="text-base font-semibold text-foreground">
                                        Pengingat WhatsApp
                                    </h2>
                                </div>
                                <Badge
                                    variant={
                                        whatsapp_status.can_receive
                                            ? 'default'
                                            : 'outline'
                                    }
                                    className={
                                        whatsapp_status.can_receive
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground'
                                    }
                                >
                                    {whatsapp_status.can_receive
                                        ? 'Aktif & Terverifikasi'
                                        : whatsapp_status.enabled
                                          ? 'Belum Diverifikasi'
                                          : 'Nonaktif'}
                                </Badge>
                            </div>

                            <div className="space-y-1.5 text-xs text-muted-foreground">
                                <div className="flex items-center justify-between">
                                    <span>Nomor WhatsApp</span>
                                    <span className="font-medium text-foreground tabular-nums">
                                        {whatsapp_status.number_masked ??
                                            'Belum diatur'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span>Jam Tenang (Quiet Hours)</span>
                                    <span className="font-medium text-foreground tabular-nums">
                                        {whatsapp_status.quiet_hours}
                                    </span>
                                </div>
                            </div>

                            <div className="mt-4">
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="w-full"
                                >
                                    <Link href={profileEdit()}>
                                        Atur Timezone & WhatsApp
                                    </Link>
                                </Button>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
