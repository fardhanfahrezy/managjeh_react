import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Plus,
    RotateCcw,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import TaskController from '@/actions/App/Http/Controllers/TaskController';
import InputError from '@/components/input-error';
import { useOffline } from '@/components/offline/offline-provider';
import {
    getTaskUrgencyBucket,
    TASK_PRIORITY_LABELS,
    TASK_STATUS_LABELS,
    type UrgencyBucket,
} from '@/components/tasks/task-constants';
import { TaskFormDialog } from '@/components/tasks/task-form-dialog';
import { TaskRowItem } from '@/components/tasks/task-row-item';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index as tasksIndex } from '@/routes/tasks';
import type {
    Auth,
    ProjectSummary,
    Task,
    TaskPriority,
    TaskStatus,
} from '@/types';

type TasksPageProps = {
    tasks: Task[];
    projects: ProjectSummary[];
    filters: {
        status: string;
        priority: string;
        project_id: string;
        due_date: string;
    };
};

type PageSharedProps = {
    auth: Auth;
};

const GROUP_META: Record<
    Exclude<UrgencyBucket, 'closed'>,
    { title: string; accentClass: string }
> = {
    overdue: {
        title: 'Terlambat',
        accentClass: 'text-red-600 dark:text-red-400',
    },
    today: {
        title: 'Hari Ini',
        accentClass: 'text-amber-700 dark:text-amber-400',
    },
    upcoming: {
        title: 'Mendatang',
        accentClass: 'text-foreground',
    },
    no_due: {
        title: 'Tanpa Deadline',
        accentClass: 'text-muted-foreground',
    },
};

export default function TasksIndex({
    tasks: serverTasks,
    projects: serverProjects,
    filters: serverFilters,
}: TasksPageProps) {
    const pageProps = usePage<Partial<PageSharedProps>>().props;
    const {
        repositories,
        lease,
        hydratedFromServer,
        isOnline,
        dataVersion,
        notifyLocalMutation,
    } = useOffline();
    const timezone =
        pageProps.auth?.user?.timezone || lease?.timezone || 'Asia/Jakarta';

    const [activeFilters, setActiveFilters] = useState(serverFilters);
    const [localTasks, setLocalTasks] = useState<Task[] | null>(null);
    const [localProjects, setLocalProjects] = useState<ProjectSummary[] | null>(
        null,
    );

    useEffect(() => {
        setActiveFilters(serverFilters);
    }, [serverFilters]);

    const filters = activeFilters;

    useEffect(() => {
        if (!repositories || (!hydratedFromServer && isOnline)) {
            return;
        }

        let cancelled = false;
        async function loadLocal() {
            const projList = await repositories!.projects.listWithProgress();
            const summaries: ProjectSummary[] = projList.map((p) => ({
                id: p.id,
                uuid: p.uuid,
                name: p.name,
                status: p.status,
            }));

            let projectUuidFilter = '';
            if (filters.project_id) {
                const match = summaries.find(
                    (p) => String(p.id) === filters.project_id,
                );
                projectUuidFilter = match?.uuid ?? '';
            }

            const taskList = await repositories!.tasks.listRootTasksForUi(
                timezone,
                {
                    status: (filters.status || '') as TaskStatus | '',
                    priority: (filters.priority || '') as TaskPriority | '',
                    projectUuid: projectUuidFilter,
                },
            );

            if (!cancelled) {
                setLocalProjects(summaries);
                setLocalTasks(taskList);
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
        timezone,
        filters.status,
        filters.priority,
        filters.project_id,
    ]);

    const tasks = localTasks ?? serverTasks;
    const projects = localProjects ?? serverProjects;

    const [formOpen, setFormOpen] = useState(false);
    const [editingTask, setEditingTask] = useState<Task | null>(null);
    const [deletingTask, setDeletingTask] = useState<Task | null>(null);
    const [showClosed, setShowClosed] = useState(
        filters.status === 'completed' || filters.status === 'cancelled',
    );

    // Keep editingTask synced with latest subtasks from IndexedDB while dialog is open.
    const liveEditingTask = useMemo(() => {
        if (!editingTask) {
            return null;
        }
        return (
            tasks.find(
                (t) =>
                    (editingTask.uuid && t.uuid === editingTask.uuid) ||
                    t.id === editingTask.id,
            ) ?? editingTask
        );
    }, [editingTask, tasks]);

    const quickAdd = useForm<{
        title: string;
        project_id: string;
        due_at: string;
    }>({
        title: '',
        project_id: filters.project_id || '',
        due_at: '',
    });

    const hasActiveFilters =
        filters.status !== '' ||
        filters.priority !== '' ||
        filters.project_id !== '' ||
        filters.due_date !== '';

    const groupedTasks = useMemo(() => {
        const groups: Record<UrgencyBucket, Task[]> = {
            overdue: [],
            today: [],
            upcoming: [],
            no_due: [],
            closed: [],
        };

        for (const task of tasks) {
            groups[getTaskUrgencyBucket(task, timezone)].push(task);
        }

        return groups;
    }, [tasks, timezone]);

    const activeCount =
        groupedTasks.overdue.length +
        groupedTasks.today.length +
        groupedTasks.upcoming.length +
        groupedTasks.no_due.length;

    const applyFilter = (key: string, value: string) => {
        const nextFilters = {
            ...filters,
            [key]: value === 'all' ? '' : value,
        };

        setActiveFilters(nextFilters);

        if (!isOnline || hydratedFromServer) {
            return;
        }

        const query: Record<string, string> = {};
        if (nextFilters.status) {
            query.status = nextFilters.status;
        }
        if (nextFilters.priority) {
            query.priority = nextFilters.priority;
        }
        if (nextFilters.project_id) {
            query.project_id = nextFilters.project_id;
        }
        if (nextFilters.due_date) {
            query.due_date = nextFilters.due_date;
        }

        router.get(tasksIndex.url({ query }), {}, { preserveState: true });
    };

    const resetFilters = () => {
        setActiveFilters({
            status: '',
            priority: '',
            project_id: '',
            due_date: '',
        });

        if (!isOnline || hydratedFromServer) {
            return;
        }

        router.get(tasksIndex.url(), {}, { preserveState: true });
    };

    const handleQuickAdd = async (e: React.FormEvent) => {
        e.preventDefault();
        if (quickAdd.data.title.trim() === '') {
            return;
        }

        if (repositories) {
            try {
                let projectUuid: string | null = null;
                if (quickAdd.data.project_id !== '') {
                    const projId = Number(quickAdd.data.project_id);
                    const matched = projects.find((p) => p.id === projId);
                    projectUuid =
                        matched?.uuid ??
                        (await repositories.projects.getByServerId(projId))
                            ?.uuid ??
                        null;
                }

                await repositories.tasks.createTask(
                    {
                        title: quickAdd.data.title.trim(),
                        project_uuid: projectUuid,
                        due_at_local: quickAdd.data.due_at || null,
                    },
                    timezone,
                );
                quickAdd.reset('title', 'due_at');
                notifyLocalMutation();
                toast.success(
                    isOnline
                        ? 'Tugas berhasil ditambahkan.'
                        : 'Tugas tersimpan di perangkat (offline).',
                );
                return;
            } catch (err) {
                toast.error(
                    err instanceof Error
                        ? err.message
                        : 'Gagal menambahkan tugas.',
                );
                return;
            }
        }

        quickAdd.transform((data) => ({
            title: data.title.trim(),
            project_id: data.project_id !== '' ? Number(data.project_id) : null,
            due_at: data.due_at !== '' ? data.due_at : null,
        }));

        quickAdd.post(TaskController.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                quickAdd.reset('title', 'due_at');
            },
        });
    };

    const handleConfirmDelete = async () => {
        if (!deletingTask) {
            return;
        }

        if (repositories) {
            const uuid =
                deletingTask.uuid ??
                (await repositories.tasks.getByServerId(deletingTask.id))?.uuid;
            if (uuid) {
                await repositories.tasks.deleteLocal(uuid);
                notifyLocalMutation();
                setDeletingTask(null);
                toast.success(
                    isOnline
                        ? 'Tugas berhasil dihapus.'
                        : 'Tugas dihapus dari perangkat (akan disinkronkan saat online).',
                );
                return;
            }
        }

        router.delete(TaskController.destroy.url(deletingTask.id), {
            preserveScroll: true,
            onSuccess: () => setDeletingTask(null),
        });
    };

    const openCreateDialog = () => {
        setEditingTask(null);
        setFormOpen(true);
    };

    const openEditDialog = (task: Task) => {
        setEditingTask(task);
        setFormOpen(true);
    };

    return (
        <>
            <Head title="Tasks" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Tasks
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            <span className="tabular-nums">{activeCount}</span>{' '}
                            tugas aktif &middot; Zona waktu{' '}
                            <span className="font-medium">{timezone}</span>
                        </p>
                    </div>

                    <Button onClick={openCreateDialog}>
                        <Plus className="size-4" />
                        Tambah Tugas
                    </Button>
                </header>

                {/* Quick-Add Bar */}
                <form
                    onSubmit={handleQuickAdd}
                    className="rounded-xl border border-border bg-card p-3 shadow-xs"
                >
                    <div className="flex flex-col gap-2.5 md:flex-row md:items-center">
                        <div className="flex-1">
                            <Input
                                value={quickAdd.data.title}
                                onChange={(e) =>
                                    quickAdd.setData('title', e.target.value)
                                }
                                placeholder="Tambah tugas cepat... (tekan Enter)"
                                aria-label="Judul tugas cepat"
                                maxLength={200}
                            />
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                type="datetime-local"
                                value={quickAdd.data.due_at}
                                onChange={(e) =>
                                    quickAdd.setData('due_at', e.target.value)
                                }
                                aria-label="Deadline cepat"
                                className="w-full tabular-nums sm:w-auto"
                            />

                            <Select
                                value={
                                    quickAdd.data.project_id !== ''
                                        ? quickAdd.data.project_id
                                        : 'none'
                                }
                                onValueChange={(value) =>
                                    quickAdd.setData(
                                        'project_id',
                                        value === 'none' ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger
                                    aria-label="Pilih project cepat"
                                    className="w-full sm:w-[160px]"
                                >
                                    <SelectValue placeholder="Project" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Tanpa Project
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

                            <Button
                                type="submit"
                                disabled={
                                    quickAdd.processing ||
                                    quickAdd.data.title.trim() === ''
                                }
                            >
                                Simpan
                            </Button>
                        </div>
                    </div>
                    <InputError
                        className="mt-1.5"
                        message={
                            quickAdd.errors.title || quickAdd.errors.due_at
                        }
                    />
                </form>

                {/* Filter Bar */}
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters.status !== '' ? filters.status : 'all'}
                        onValueChange={(value) => applyFilter('status', value)}
                    >
                        <SelectTrigger
                            aria-label="Filter status"
                            className="h-9 w-[160px]"
                        >
                            <SelectValue placeholder="Semua Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua Status</SelectItem>
                            {(
                                Object.keys(TASK_STATUS_LABELS) as TaskStatus[]
                            ).map((statusKey) => (
                                <SelectItem key={statusKey} value={statusKey}>
                                    {TASK_STATUS_LABELS[statusKey]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={
                            filters.priority !== '' ? filters.priority : 'all'
                        }
                        onValueChange={(value) =>
                            applyFilter('priority', value)
                        }
                    >
                        <SelectTrigger
                            aria-label="Filter prioritas"
                            className="h-9 w-[150px]"
                        >
                            <SelectValue placeholder="Semua Prioritas" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua Prioritas</SelectItem>
                            {(
                                Object.keys(
                                    TASK_PRIORITY_LABELS,
                                ) as TaskPriority[]
                            ).map((priorityKey) => (
                                <SelectItem
                                    key={priorityKey}
                                    value={priorityKey}
                                >
                                    {TASK_PRIORITY_LABELS[priorityKey]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={
                            filters.project_id !== ''
                                ? filters.project_id
                                : 'all'
                        }
                        onValueChange={(value) =>
                            applyFilter('project_id', value)
                        }
                    >
                        <SelectTrigger
                            aria-label="Filter project"
                            className="h-9 w-[180px]"
                        >
                            <SelectValue placeholder="Semua Project" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua Project</SelectItem>
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

                    {hasActiveFilters && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={resetFilters}
                        >
                            <RotateCcw className="size-3.5" />
                            Reset Filter
                        </Button>
                    )}
                </div>

                {/* Empty state */}
                {tasks.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-border bg-card px-6 py-14 text-center">
                        <CheckCircle2 className="mb-3 size-9 stroke-[1.5] text-muted-foreground" />
                        <h2 className="text-base font-semibold">
                            {hasActiveFilters
                                ? 'Tidak ada tugas yang cocok'
                                : 'Belum ada tugas'}
                        </h2>
                        <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                            {hasActiveFilters
                                ? 'Coba ubah atau reset filter untuk melihat daftar tugas lainnya.'
                                : 'Mulai dengan mencatat tugas pertama Anda lewat bilah cepat di atas.'}
                        </p>
                        {hasActiveFilters ? (
                            <Button
                                variant="outline"
                                className="mt-4"
                                onClick={resetFilters}
                            >
                                Reset Filter
                            </Button>
                        ) : (
                            <Button className="mt-4" onClick={openCreateDialog}>
                                <Plus className="size-4" />
                                Tambah Tugas Pertama
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="space-y-6">
                        {(
                            ['overdue', 'today', 'upcoming', 'no_due'] as const
                        ).map((bucket) => {
                            const items = groupedTasks[bucket];
                            if (items.length === 0) {
                                return null;
                            }

                            const meta = GROUP_META[bucket];

                            return (
                                <section key={bucket} className="space-y-2">
                                    <div className="flex items-center justify-between px-1">
                                        <h2
                                            className={`text-sm font-semibold ${meta.accentClass}`}
                                        >
                                            {meta.title}
                                        </h2>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {items.length}
                                        </span>
                                    </div>

                                    <div className="divide-y divide-border overflow-hidden rounded-xl border border-border shadow-xs">
                                        {items.map((task) => (
                                            <TaskRowItem
                                                key={task.id}
                                                task={task}
                                                timezone={timezone}
                                                onEdit={openEditDialog}
                                                onDeleteRequest={
                                                    setDeletingTask
                                                }
                                            />
                                        ))}
                                    </div>
                                </section>
                            );
                        })}

                        {groupedTasks.closed.length > 0 && (
                            <section className="space-y-2">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setShowClosed((prev) => !prev)
                                    }
                                    className="flex w-full items-center justify-between px-1 py-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    <span className="inline-flex items-center gap-1.5">
                                        {showClosed ? (
                                            <ChevronDown className="size-4" />
                                        ) : (
                                            <ChevronRight className="size-4" />
                                        )}
                                        Selesai &amp; Dibatalkan
                                    </span>
                                    <span className="text-xs tabular-nums">
                                        {groupedTasks.closed.length}
                                    </span>
                                </button>

                                {showClosed && (
                                    <div className="divide-y divide-border overflow-hidden rounded-xl border border-border shadow-xs">
                                        {groupedTasks.closed.map((task) => (
                                            <TaskRowItem
                                                key={task.id}
                                                task={task}
                                                timezone={timezone}
                                                onEdit={openEditDialog}
                                                onDeleteRequest={
                                                    setDeletingTask
                                                }
                                            />
                                        ))}
                                    </div>
                                )}
                            </section>
                        )}
                    </div>
                )}
            </div>

            <TaskFormDialog
                open={formOpen}
                onOpenChange={setFormOpen}
                task={liveEditingTask}
                projects={projects}
                defaultProjectId={
                    filters.project_id ? Number(filters.project_id) : null
                }
            />

            <Dialog
                open={deletingTask !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeletingTask(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus tugas ini?</DialogTitle>
                        <DialogDescription>
                            Tugas{' '}
                            <span className="font-medium text-foreground">
                                {deletingTask?.title}
                            </span>{' '}
                            akan dipindahkan ke arsip terhapus (soft delete).
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeletingTask(null)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleConfirmDelete}
                        >
                            Hapus Tugas
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

TasksIndex.layout = {
    breadcrumbs: [
        {
            title: 'Tasks',
            href: tasksIndex(),
        },
    ],
};
