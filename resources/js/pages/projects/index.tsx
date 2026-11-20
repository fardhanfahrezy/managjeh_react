import { Head, Link, router, useForm } from '@inertiajs/react';
import { Calendar, FolderKanban, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';
import { useOffline } from '@/components/offline/offline-provider';
import {
    formatDateDisplay,
    PROJECT_STATUS_BADGE_CLASS,
    PROJECT_STATUS_LABELS,
} from '@/components/tasks/task-constants';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { cn } from '@/lib/utils';
import {
    index as projectsIndex,
    show as projectsShow,
} from '@/routes/projects';
import type { Project, ProjectStatus } from '@/types';

type ProjectsIndexProps = {
    projects: Project[];
    filters: {
        status: string;
    };
};

export default function ProjectsIndex({
    projects: serverProjects,
    filters: serverFilters,
}: ProjectsIndexProps) {
    const {
        repositories,
        hydratedFromServer,
        isOnline,
        dataVersion,
        notifyLocalMutation,
    } = useOffline();

    const [statusFilter, setStatusFilter] = useState(serverFilters.status);
    const [localProjects, setLocalProjects] = useState<Project[] | null>(null);
    const [localSaving, setLocalSaving] = useState(false);

    useEffect(() => {
        setStatusFilter(serverFilters.status);
    }, [serverFilters.status]);

    useEffect(() => {
        if (!repositories || (!hydratedFromServer && isOnline)) {
            return;
        }

        let cancelled = false;
        void repositories.projects
            .listWithProgress((statusFilter || '') as ProjectStatus | '')
            .then((list) => {
                if (!cancelled) {
                    setLocalProjects(list);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [repositories, hydratedFromServer, isOnline, dataVersion, statusFilter]);

    const projects = localProjects ?? serverProjects;
    const filters = { status: statusFilter };

    const [formOpen, setFormOpen] = useState(false);
    const [editingProject, setEditingProject] = useState<Project | null>(null);
    const [deletingProject, setDeletingProject] = useState<Project | null>(
        null,
    );

    const form = useForm<{
        name: string;
        description: string;
        status: ProjectStatus;
        start_date: string;
        due_date: string;
    }>({
        name: '',
        description: '',
        status: 'active',
        start_date: '',
        due_date: '',
    });

    useEffect(() => {
        if (!formOpen) {
            return;
        }

        if (editingProject) {
            form.setData({
                name: editingProject.name,
                description: editingProject.description ?? '',
                status: editingProject.status,
                start_date: editingProject.start_date ?? '',
                due_date: editingProject.due_date ?? '',
            });
        } else {
            form.setData({
                name: '',
                description: '',
                status: 'active',
                start_date: '',
                due_date: '',
            });
        }
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [formOpen, editingProject?.id]);

    const handleFilterStatus = (status: string) => {
        const nextStatus = status !== 'all' ? status : '';
        setStatusFilter(nextStatus);

        if (!isOnline || hydratedFromServer) {
            return;
        }

        const query = nextStatus ? { status: nextStatus } : undefined;
        router.get(
            projectsIndex.url(query ? { query } : undefined),
            {},
            { preserveState: true },
        );
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (repositories) {
            setLocalSaving(true);
            try {
                if (editingProject) {
                    const uuid =
                        editingProject.uuid ??
                        (
                            await repositories.projects.getByServerId(
                                editingProject.id,
                            )
                        )?.uuid;
                    if (uuid) {
                        await repositories.projects.updateProject(uuid, {
                            name: form.data.name,
                            description: form.data.description || null,
                            status: form.data.status,
                            start_date: form.data.start_date || null,
                            due_date: form.data.due_date || null,
                        });
                        notifyLocalMutation();
                        toast.success(
                            isOnline
                                ? 'Project berhasil diperbarui.'
                                : 'Perubahan project disimpan di perangkat.',
                        );
                        setFormOpen(false);
                        setLocalSaving(false);
                        return;
                    }
                } else {
                    await repositories.projects.createProject({
                        name: form.data.name,
                        description: form.data.description || null,
                        status: form.data.status,
                        start_date: form.data.start_date || null,
                        due_date: form.data.due_date || null,
                    });
                    notifyLocalMutation();
                    toast.success(
                        isOnline
                            ? 'Project berhasil dibuat.'
                            : 'Project tersimpan di perangkat (offline).',
                    );
                    setFormOpen(false);
                    setLocalSaving(false);
                    return;
                }
            } catch (err) {
                toast.error(
                    err instanceof Error
                        ? err.message
                        : 'Gagal menyimpan project.',
                );
                setLocalSaving(false);
                return;
            }
            setLocalSaving(false);
        }

        form.transform((data) => ({
            ...data,
            description:
                data.description.trim() !== '' ? data.description : null,
            start_date: data.start_date !== '' ? data.start_date : null,
            due_date: data.due_date !== '' ? data.due_date : null,
        }));

        if (editingProject) {
            form.patch(ProjectController.update.url(editingProject.id), {
                preserveScroll: true,
                onSuccess: () => setFormOpen(false),
            });
        } else {
            form.post(ProjectController.store.url(), {
                preserveScroll: true,
                onSuccess: () => setFormOpen(false),
            });
        }
    };

    const handleConfirmDelete = async () => {
        if (!deletingProject) {
            return;
        }

        if (repositories) {
            const uuid =
                deletingProject.uuid ??
                (await repositories.projects.getByServerId(deletingProject.id))
                    ?.uuid;
            if (uuid) {
                await repositories.projects.deleteLocal(uuid);
                notifyLocalMutation();
                setDeletingProject(null);
                toast.success(
                    isOnline
                        ? 'Project berhasil dihapus.'
                        : 'Project dihapus dari perangkat (akan disinkronkan saat online).',
                );
                return;
            }
        }

        router.delete(ProjectController.destroy.url(deletingProject.id), {
            preserveScroll: true,
            onSuccess: () => setDeletingProject(null),
        });
    };

    return (
        <>
            <Head title="Projects" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Projects
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Kelompokkan tugas kuliah dan organisasi dalam satu
                            progres yang terukur.
                        </p>
                    </div>

                    <Button
                        onClick={() => {
                            setEditingProject(null);
                            setFormOpen(true);
                        }}
                    >
                        <Plus className="size-4" />
                        Tambah Project
                    </Button>
                </header>

                <div className="flex items-center gap-2">
                    <Select
                        value={filters.status !== '' ? filters.status : 'all'}
                        onValueChange={handleFilterStatus}
                    >
                        <SelectTrigger
                            aria-label="Filter status project"
                            className="h-9 w-[170px]"
                        >
                            <SelectValue placeholder="Semua Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua Status</SelectItem>
                            {(
                                Object.keys(
                                    PROJECT_STATUS_LABELS,
                                ) as ProjectStatus[]
                            ).map((statusKey) => (
                                <SelectItem key={statusKey} value={statusKey}>
                                    {PROJECT_STATUS_LABELS[statusKey]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {projects.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-border bg-card px-6 py-14 text-center">
                        <FolderKanban className="mb-3 size-9 stroke-[1.5] text-muted-foreground" />
                        <h2 className="text-base font-semibold">
                            Belum ada project
                        </h2>
                        <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                            Buat project pertama untuk mengelompokkan tugas
                            kuliah, skripsi, atau kepanitiaan.
                        </p>
                        <Button
                            className="mt-4"
                            onClick={() => {
                                setEditingProject(null);
                                setFormOpen(true);
                            }}
                        >
                            <Plus className="size-4" />
                            Buat Project Pertama
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {projects.map((project) => (
                            <Card key={project.id} className="justify-between">
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-2">
                                        <CardTitle className="text-base leading-snug">
                                            <Link
                                                href={projectsShow(project.id)}
                                                className="hover:underline"
                                            >
                                                {project.name}
                                            </Link>
                                        </CardTitle>
                                        <Badge
                                            variant="outline"
                                            className={cn(
                                                'shrink-0 border text-[11px]',
                                                PROJECT_STATUS_BADGE_CLASS[
                                                    project.status
                                                ],
                                            )}
                                        >
                                            {
                                                PROJECT_STATUS_LABELS[
                                                    project.status
                                                ]
                                            }
                                        </Badge>
                                    </div>
                                    {project.description && (
                                        <CardDescription className="line-clamp-2">
                                            {project.description}
                                        </CardDescription>
                                    )}
                                </CardHeader>

                                <CardContent className="space-y-3">
                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="text-muted-foreground">
                                                Progres
                                            </span>
                                            <span className="font-medium tabular-nums">
                                                {project.progress}% (
                                                {project.completed_tasks_count ??
                                                    0}
                                                /
                                                {project.non_cancelled_tasks_count ??
                                                    0}
                                                )
                                            </span>
                                        </div>
                                        <div className="h-2 w-full overflow-hidden rounded-full bg-secondary">
                                            <div
                                                className="h-full rounded-full bg-primary transition-all"
                                                style={{
                                                    width: `${project.progress}%`,
                                                }}
                                            />
                                        </div>
                                    </div>

                                    {(project.start_date ||
                                        project.due_date) && (
                                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground tabular-nums">
                                            <Calendar className="size-3.5" />
                                            <span>
                                                {formatDateDisplay(
                                                    project.start_date,
                                                )}{' '}
                                                &ndash;{' '}
                                                {formatDateDisplay(
                                                    project.due_date,
                                                )}
                                            </span>
                                        </div>
                                    )}
                                </CardContent>

                                <CardFooter className="justify-between border-t border-border pt-4">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={projectsShow(project.id)}>
                                            Lihat Tugas
                                        </Link>
                                    </Button>

                                    <div className="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8"
                                            onClick={() => {
                                                setEditingProject(project);
                                                setFormOpen(true);
                                            }}
                                            aria-label={`Edit project ${project.name}`}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:text-destructive"
                                            onClick={() =>
                                                setDeletingProject(project)
                                            }
                                            aria-label={`Hapus project ${project.name}`}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editingProject
                                ? 'Edit Project'
                                : 'Tambah Project Baru'}
                        </DialogTitle>
                        <DialogDescription>
                            Atur nama, status, dan rentang waktu target project.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="project-name">Nama Project</Label>
                            <Input
                                id="project-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="Contoh: Skripsi Semester 8"
                                required
                                maxLength={150}
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="project-description">
                                Deskripsi
                            </Label>
                            <textarea
                                id="project-description"
                                rows={3}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Tujuan atau ruang lingkup project..."
                                className="flex w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <InputError message={form.errors.description} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="project-status">Status</Label>
                            <Select
                                value={form.data.status}
                                onValueChange={(value) =>
                                    form.setData(
                                        'status',
                                        value as ProjectStatus,
                                    )
                                }
                            >
                                <SelectTrigger id="project-status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(
                                        Object.keys(
                                            PROJECT_STATUS_LABELS,
                                        ) as ProjectStatus[]
                                    ).map((statusKey) => (
                                        <SelectItem
                                            key={statusKey}
                                            value={statusKey}
                                        >
                                            {PROJECT_STATUS_LABELS[statusKey]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.status} />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="project-start-date">
                                    Tanggal Mulai
                                </Label>
                                <Input
                                    id="project-start-date"
                                    type="date"
                                    value={form.data.start_date}
                                    onChange={(e) =>
                                        form.setData(
                                            'start_date',
                                            e.target.value,
                                        )
                                    }
                                    className="tabular-nums"
                                />
                                <InputError message={form.errors.start_date} />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="project-due-date">
                                    Target Selesai
                                </Label>
                                <Input
                                    id="project-due-date"
                                    type="date"
                                    value={form.data.due_date}
                                    onChange={(e) =>
                                        form.setData('due_date', e.target.value)
                                    }
                                    className="tabular-nums"
                                />
                                <InputError message={form.errors.due_date} />
                            </div>
                        </div>

                        <DialogFooter className="pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setFormOpen(false)}
                            >
                                Batal
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing || localSaving}
                            >
                                {form.processing || localSaving
                                    ? 'Menyimpan...'
                                    : editingProject
                                      ? 'Simpan Perubahan'
                                      : 'Buat Project'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deletingProject !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeletingProject(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus project ini?</DialogTitle>
                        <DialogDescription>
                            Project{' '}
                            <span className="font-medium text-foreground">
                                {deletingProject?.name}
                            </span>{' '}
                            akan dihapus (soft delete). Tugas di dalamnya tetap
                            tersimpan.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeletingProject(null)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleConfirmDelete}
                        >
                            Hapus Project
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Projects',
            href: projectsIndex(),
        },
    ],
};
