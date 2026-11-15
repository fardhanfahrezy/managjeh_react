import { router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import TaskController from '@/actions/App/Http/Controllers/TaskController';
import InputError from '@/components/input-error';
import {
    TASK_PRIORITY_LABELS,
    TASK_STATUS_LABELS,
} from '@/components/tasks/task-constants';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { ProjectSummary, Task, TaskPriority, TaskStatus } from '@/types';

type TaskFormDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    task: Task | null;
    projects: ProjectSummary[];
    defaultProjectId?: number | null;
};

export function TaskFormDialog({
    open,
    onOpenChange,
    task,
    projects,
    defaultProjectId = null,
}: TaskFormDialogProps) {
    const isEditing = task !== null;
    const [subtaskTitle, setSubtaskTitle] = useState('');
    const [submittingSubtask, setSubmittingSubtask] = useState(false);

    const form = useForm<{
        title: string;
        description: string;
        status: TaskStatus;
        priority: TaskPriority;
        project_id: string;
        due_at: string;
    }>({
        title: '',
        description: '',
        status: 'todo',
        priority: 'medium',
        project_id: defaultProjectId ? String(defaultProjectId) : '',
        due_at: '',
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        if (task) {
            form.setData({
                title: task.title,
                description: task.description ?? '',
                status: task.status,
                priority: task.priority,
                project_id: task.project_id ? String(task.project_id) : '',
                due_at: task.due_at_local ?? '',
            });
        } else {
            form.setData({
                title: '',
                description: '',
                status: 'todo',
                priority: 'medium',
                project_id: defaultProjectId ? String(defaultProjectId) : '',
                due_at: '',
            });
        }
        form.clearErrors();
        setSubtaskTitle('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, task?.id, defaultProjectId]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        const payload = {
            ...form.data,
            project_id:
                form.data.project_id !== ''
                    ? Number(form.data.project_id)
                    : null,
            due_at: form.data.due_at !== '' ? form.data.due_at : null,
        };

        form.transform(() => payload);

        if (isEditing && task) {
            form.patch(TaskController.update.url(task.id), {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });
        } else {
            form.post(TaskController.store.url(), {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });
        }
    };

    const handleAddSubtask = (e: React.FormEvent) => {
        e.preventDefault();
        if (!task || subtaskTitle.trim() === '') {
            return;
        }

        setSubmittingSubtask(true);
        router.post(
            TaskController.store.url(),
            {
                title: subtaskTitle.trim(),
                parent_id: task.id,
                project_id: task.project_id,
                status: 'todo',
                priority: task.priority,
            },
            {
                preserveScroll: true,
                onSuccess: () => setSubtaskTitle(''),
                onFinish: () => setSubmittingSubtask(false),
            },
        );
    };

    const handleToggleSubtask = (subtask: Task) => {
        const nextStatus: TaskStatus =
            subtask.status === 'completed' ? 'todo' : 'completed';

        router.patch(
            TaskController.update.url(subtask.id),
            { status: nextStatus },
            { preserveScroll: true },
        );
    };

    const handleDeleteSubtask = (subtaskId: number) => {
        router.delete(TaskController.destroy.url(subtaskId), {
            preserveScroll: true,
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isEditing
                            ? 'Detail & Edit Tugas'
                            : 'Tambah Tugas Baru'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEditing
                            ? 'Perbarui informasi tugas, tenggat waktu, atau kelola subtugas.'
                            : 'Catat tugas baru dan hubungkan dengan project jika diperlukan.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="task-title">Judul Tugas</Label>
                        <Input
                            id="task-title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder="Contoh: Revisi Bab 3 Skripsi"
                            required
                            maxLength={200}
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="task-description">Deskripsi</Label>
                        <textarea
                            id="task-description"
                            rows={3}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            placeholder="Catatan singkat, referensi, atau langkah pengerjaan..."
                            className="flex w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50"
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="task-status">Status</Label>
                            <Select
                                value={form.data.status}
                                onValueChange={(value) =>
                                    form.setData('status', value as TaskStatus)
                                }
                            >
                                <SelectTrigger id="task-status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(
                                        Object.keys(
                                            TASK_STATUS_LABELS,
                                        ) as TaskStatus[]
                                    ).map((statusKey) => (
                                        <SelectItem
                                            key={statusKey}
                                            value={statusKey}
                                        >
                                            {TASK_STATUS_LABELS[statusKey]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.status} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="task-priority">Prioritas</Label>
                            <Select
                                value={form.data.priority}
                                onValueChange={(value) =>
                                    form.setData(
                                        'priority',
                                        value as TaskPriority,
                                    )
                                }
                            >
                                <SelectTrigger id="task-priority">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
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
                            <InputError message={form.errors.priority} />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="task-project">Project</Label>
                            <Select
                                value={
                                    form.data.project_id !== ''
                                        ? form.data.project_id
                                        : 'none'
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'project_id',
                                        value === 'none' ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger id="task-project">
                                    <SelectValue placeholder="Tanpa Project" />
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
                            <InputError message={form.errors.project_id} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="task-due-at">
                                Deadline (Waktu Lokal)
                            </Label>
                            <Input
                                id="task-due-at"
                                type="datetime-local"
                                value={form.data.due_at}
                                onChange={(e) =>
                                    form.setData('due_at', e.target.value)
                                }
                                className="tabular-nums"
                            />
                            <InputError message={form.errors.due_at} />
                        </div>
                    </div>

                    <DialogFooter className="pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Menyimpan...'
                                : isEditing
                                  ? 'Simpan Perubahan'
                                  : 'Tambah Tugas'}
                        </Button>
                    </DialogFooter>
                </form>

                {isEditing && task && task.parent_id === null && (
                    <div className="mt-2 border-t border-border pt-4">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-sm font-semibold">
                                Subtugas ({task.subtasks?.length ?? 0})
                            </h3>
                            <span className="text-xs text-muted-foreground">
                                Maks. 1 tingkat
                            </span>
                        </div>

                        {task.subtasks && task.subtasks.length > 0 && (
                            <ul className="mb-3 space-y-1.5">
                                {task.subtasks.map((sub) => (
                                    <li
                                        key={sub.id}
                                        className="flex items-center justify-between gap-2 rounded-md border border-border bg-secondary/40 px-3 py-2"
                                    >
                                        <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5">
                                            <Checkbox
                                                checked={
                                                    sub.status === 'completed'
                                                }
                                                onCheckedChange={() =>
                                                    handleToggleSubtask(sub)
                                                }
                                                aria-label={`Tandai subtugas ${sub.title} selesai`}
                                            />
                                            <span
                                                className={
                                                    sub.status === 'completed'
                                                        ? 'truncate text-sm text-muted-foreground line-through'
                                                        : 'truncate text-sm'
                                                }
                                            >
                                                {sub.title}
                                            </span>
                                        </label>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                                            onClick={() =>
                                                handleDeleteSubtask(sub.id)
                                            }
                                            aria-label={`Hapus subtugas ${sub.title}`}
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <form
                            onSubmit={handleAddSubtask}
                            className="flex items-center gap-2"
                        >
                            <Input
                                value={subtaskTitle}
                                onChange={(e) =>
                                    setSubtaskTitle(e.target.value)
                                }
                                placeholder="Tambah subtugas baru..."
                                maxLength={200}
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={
                                    submittingSubtask ||
                                    subtaskTitle.trim() === ''
                                }
                            >
                                <Plus className="size-4" />
                                Tambah
                            </Button>
                        </form>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
