import { router } from '@inertiajs/react';
import {
    CalendarClock,
    ChevronDown,
    ChevronRight,
    FolderKanban,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import TaskController from '@/actions/App/Http/Controllers/TaskController';
import {
    formatDueAtDisplay,
    getTaskUrgencyBucket,
    TASK_PRIORITY_BADGE_CLASS,
    TASK_PRIORITY_LABELS,
    TASK_STATUS_LABELS,
} from '@/components/tasks/task-constants';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { Task, TaskPriority, TaskStatus } from '@/types';

type TaskRowItemProps = {
    task: Task;
    timezone: string;
    onEdit: (task: Task) => void;
    onDeleteRequest: (task: Task) => void;
    showProjectBadge?: boolean;
};

export function TaskRowItem({
    task,
    timezone,
    onEdit,
    onDeleteRequest,
    showProjectBadge = true,
}: TaskRowItemProps) {
    const [expanded, setExpanded] = useState(false);
    const [updating, setUpdating] = useState(false);

    const subtasks = task.subtasks ?? [];
    const completedSubtasks = subtasks.filter(
        (sub) => sub.status === 'completed',
    ).length;
    const isCompleted = task.status === 'completed';
    const isCancelled = task.status === 'cancelled';
    const urgency = getTaskUrgencyBucket(task, timezone);

    const patchTask = (
        taskId: number,
        data: Partial<{ status: TaskStatus; priority: TaskPriority }>,
    ) => {
        setUpdating(true);
        router.patch(TaskController.update.url(taskId), data, {
            preserveScroll: true,
            onFinish: () => setUpdating(false),
        });
    };

    return (
        <div
            className={cn(
                'border-border bg-card transition-colors',
                updating && 'opacity-70',
            )}
        >
            <div className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 flex-1 items-start gap-3">
                    <Checkbox
                        checked={isCompleted}
                        onCheckedChange={(checked) =>
                            patchTask(task.id, {
                                status: checked ? 'completed' : 'todo',
                            })
                        }
                        aria-label={`Tandai ${task.title} selesai`}
                        className="mt-0.5"
                    />

                    <div className="min-w-0 flex-1 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={() => onEdit(task)}
                                className={cn(
                                    'text-left text-sm font-medium hover:underline focus-visible:underline focus-visible:outline-none',
                                    (isCompleted || isCancelled) &&
                                        'text-muted-foreground line-through',
                                )}
                            >
                                {task.title}
                            </button>

                            {subtasks.length > 0 && (
                                <button
                                    type="button"
                                    onClick={() => setExpanded((prev) => !prev)}
                                    className="inline-flex items-center gap-1 rounded border-border bg-secondary/70 px-1.5 py-0.5 text-xs text-muted-foreground tabular-nums transition-colors hover:text-foreground"
                                >
                                    {expanded ? (
                                        <ChevronDown className="size-3" />
                                    ) : (
                                        <ChevronRight className="size-3" />
                                    )}
                                    {completedSubtasks}/{subtasks.length}
                                </button>
                            )}
                        </div>

                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            {showProjectBadge && task.project && (
                                <span className="inline-flex items-center gap-1 text-muted-foreground">
                                    <FolderKanban className="size-3.5" />
                                    {task.project.name}
                                </span>
                            )}

                            {task.due_at && (
                                <span
                                    className={cn(
                                        'inline-flex items-center gap-1 tabular-nums',
                                        urgency === 'overdue'
                                            ? 'font-medium text-red-600 dark:text-red-400'
                                            : urgency === 'today'
                                              ? 'font-medium text-amber-700 dark:text-amber-400'
                                              : 'text-muted-foreground',
                                    )}
                                >
                                    <CalendarClock className="size-3.5" />
                                    {formatDueAtDisplay(task.due_at, timezone)}
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2 pl-7 sm:pl-0">
                    <Select
                        value={task.priority}
                        onValueChange={(value) =>
                            patchTask(task.id, {
                                priority: value as TaskPriority,
                            })
                        }
                    >
                        <SelectTrigger
                            aria-label={`Prioritas ${task.title}`}
                            className="h-8 w-[112px] text-xs"
                        >
                            <Badge
                                variant="outline"
                                className={cn(
                                    'border px-1.5 py-0 text-[11px] font-medium',
                                    TASK_PRIORITY_BADGE_CLASS[task.priority],
                                )}
                            >
                                {TASK_PRIORITY_LABELS[task.priority]}
                            </Badge>
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

                    <Select
                        value={task.status}
                        onValueChange={(value) =>
                            patchTask(task.id, {
                                status: value as TaskStatus,
                            })
                        }
                    >
                        <SelectTrigger
                            aria-label={`Status ${task.title}`}
                            className="h-8 w-[148px] text-xs"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(
                                Object.keys(TASK_STATUS_LABELS) as TaskStatus[]
                            ).map((statusKey) => (
                                <SelectItem key={statusKey} value={statusKey}>
                                    {TASK_STATUS_LABELS[statusKey]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 text-muted-foreground hover:text-destructive"
                        onClick={() => onDeleteRequest(task)}
                        aria-label={`Hapus tugas ${task.title}`}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            </div>

            {expanded && subtasks.length > 0 && (
                <div className="border-t border-border bg-secondary/30 px-4 py-2.5 pl-11">
                    <ul className="space-y-1.5">
                        {subtasks.map((sub) => (
                            <li
                                key={sub.id}
                                className="flex items-center justify-between gap-2 text-sm"
                            >
                                <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5">
                                    <Checkbox
                                        checked={sub.status === 'completed'}
                                        onCheckedChange={(checked) =>
                                            patchTask(sub.id, {
                                                status: checked
                                                    ? 'completed'
                                                    : 'todo',
                                            })
                                        }
                                        aria-label={`Tandai subtugas ${sub.title} selesai`}
                                    />
                                    <span
                                        className={cn(
                                            'truncate',
                                            sub.status === 'completed' &&
                                                'text-muted-foreground line-through',
                                        )}
                                    >
                                        {sub.title}
                                    </span>
                                </label>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                                    onClick={() => onDeleteRequest(sub)}
                                    aria-label={`Hapus subtugas ${sub.title}`}
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
