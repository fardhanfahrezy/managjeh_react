import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Calendar, CheckCircle2, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import TaskController from '@/actions/App/Http/Controllers/TaskController';
import {
    formatDateDisplay,
    PROJECT_STATUS_BADGE_CLASS,
    PROJECT_STATUS_LABELS,
} from '@/components/tasks/task-constants';
import { TaskFormDialog } from '@/components/tasks/task-form-dialog';
import { TaskRowItem } from '@/components/tasks/task-row-item';
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
import { cn } from '@/lib/utils';
import { index as projectsIndex } from '@/routes/projects';
import type { Auth, Project, ProjectSummary, Task } from '@/types';

type ProjectShowProps = {
    project: Project;
    projects: ProjectSummary[];
};

type PageSharedProps = {
    auth: Auth;
};

export default function ProjectShow({ project, projects }: ProjectShowProps) {
    const { auth } = usePage<PageSharedProps>().props;
    const timezone = auth.user.timezone || 'Asia/Jakarta';

    const [taskDialogOpen, setTaskDialogOpen] = useState(false);
    const [editingTask, setEditingTask] = useState<Task | null>(null);
    const [deletingTask, setDeletingTask] = useState<Task | null>(null);
    const [confirmDeleteProject, setConfirmDeleteProject] = useState(false);

    const tasks = project.tasks ?? [];

    const handleConfirmDeleteTask = () => {
        if (!deletingTask) {
            return;
        }

        router.delete(TaskController.destroy.url(deletingTask.id), {
            preserveScroll: true,
            onSuccess: () => setDeletingTask(null),
        });
    };

    const handleConfirmDeleteProject = () => {
        router.delete(ProjectController.destroy.url(project.id));
    };

    return (
        <>
            <Head title={project.name} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <div>
                    <Link
                        href={projectsIndex()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Kembali ke Projects
                    </Link>
                </div>

                <section className="space-y-4 rounded-xl border border-border bg-card p-6 shadow-xs">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1.5">
                            <div className="flex flex-wrap items-center gap-2.5">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {project.name}
                                </h1>
                                <Badge
                                    variant="outline"
                                    className={cn(
                                        'border text-xs',
                                        PROJECT_STATUS_BADGE_CLASS[
                                            project.status
                                        ],
                                    )}
                                >
                                    {PROJECT_STATUS_LABELS[project.status]}
                                </Badge>
                            </div>

                            {project.description && (
                                <p className="max-w-2xl text-sm leading-relaxed text-muted-foreground">
                                    {project.description}
                                </p>
                            )}

                            {(project.start_date || project.due_date) && (
                                <div className="flex items-center gap-1.5 pt-1 text-xs text-muted-foreground tabular-nums">
                                    <Calendar className="size-3.5" />
                                    <span>
                                        {formatDateDisplay(project.start_date)}{' '}
                                        &ndash;{' '}
                                        {formatDateDisplay(project.due_date)}
                                    </span>
                                </div>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            <Button
                                onClick={() => {
                                    setEditingTask(null);
                                    setTaskDialogOpen(true);
                                }}
                            >
                                <Plus className="size-4" />
                                Tambah Tugas
                            </Button>

                            <Button
                                variant="outline"
                                size="icon"
                                className="text-muted-foreground hover:text-destructive"
                                onClick={() => setConfirmDeleteProject(true)}
                                aria-label={`Hapus project ${project.name}`}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    </div>

                    <div className="space-y-1.5 pt-2">
                        <div className="flex items-center justify-between text-xs">
                            <span className="text-muted-foreground">
                                Progres Penyelesaian
                            </span>
                            <span className="font-medium tabular-nums">
                                {project.progress}% (
                                {project.completed_tasks_count ?? 0}/
                                {project.non_cancelled_tasks_count ?? 0} tugas)
                            </span>
                        </div>
                        <div className="h-2 w-full overflow-hidden rounded-full bg-secondary">
                            <div
                                className="h-full rounded-full bg-primary transition-all"
                                style={{ width: `${project.progress}%` }}
                            />
                        </div>
                    </div>
                </section>

                <section className="space-y-3">
                    <div className="flex items-center justify-between px-1">
                        <h2 className="text-base font-semibold">
                            Daftar Tugas ({tasks.length})
                        </h2>
                    </div>

                    {tasks.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-xl border border-border bg-card px-6 py-12 text-center">
                            <CheckCircle2 className="mb-3 size-8 stroke-[1.5] text-muted-foreground" />
                            <h3 className="text-sm font-semibold">
                                Belum ada tugas di project ini
                            </h3>
                            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                                Tambahkan tugas pertama agar progres project
                                mulai terhitung otomatis.
                            </p>
                            <Button
                                className="mt-4"
                                onClick={() => {
                                    setEditingTask(null);
                                    setTaskDialogOpen(true);
                                }}
                            >
                                <Plus className="size-4" />
                                Tambah Tugas ke Project
                            </Button>
                        </div>
                    ) : (
                        <div className="divide-y divide-border overflow-hidden rounded-xl border border-border shadow-xs">
                            {tasks.map((task) => (
                                <TaskRowItem
                                    key={task.id}
                                    task={task}
                                    timezone={timezone}
                                    showProjectBadge={false}
                                    onEdit={(selected) => {
                                        setEditingTask(selected);
                                        setTaskDialogOpen(true);
                                    }}
                                    onDeleteRequest={setDeletingTask}
                                />
                            ))}
                        </div>
                    )}
                </section>
            </div>

            <TaskFormDialog
                open={taskDialogOpen}
                onOpenChange={setTaskDialogOpen}
                task={editingTask}
                projects={projects}
                defaultProjectId={project.id}
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
                            onClick={handleConfirmDeleteTask}
                        >
                            Hapus Tugas
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={confirmDeleteProject}
                onOpenChange={setConfirmDeleteProject}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus project ini?</DialogTitle>
                        <DialogDescription>
                            Project{' '}
                            <span className="font-medium text-foreground">
                                {project.name}
                            </span>{' '}
                            akan dihapus (soft delete). Tugas di dalamnya tetap
                            tersimpan tanpa kehilangan histori.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmDeleteProject(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleConfirmDeleteProject}
                        >
                            Hapus Project
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
