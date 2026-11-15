export type ProjectStatus = 'active' | 'completed' | 'archived';
export type TaskStatus = 'todo' | 'in_progress' | 'completed' | 'cancelled';
export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';

export type ProjectSummary = {
    id: number;
    name: string;
    status: ProjectStatus;
};

export type Task = {
    id: number;
    user_id: number;
    project_id: number | null;
    parent_id: number | null;
    title: string;
    description: string | null;
    status: TaskStatus;
    priority: TaskPriority;
    due_at: string | null;
    due_at_local?: string | null;
    completed_at: string | null;
    sort_order: number;
    project?: ProjectSummary | null;
    subtasks?: Task[];
    created_at: string;
    updated_at: string;
};

export type Project = {
    id: number;
    user_id: number;
    name: string;
    description: string | null;
    status: ProjectStatus;
    start_date: string | null;
    due_date: string | null;
    completed_at: string | null;
    progress: number;
    completed_tasks_count?: number;
    non_cancelled_tasks_count?: number;
    tasks?: Task[];
    created_at: string;
    updated_at: string;
};

export type CalendarView = 'day' | 'week' | 'month';

export type CalendarEvent = {
    id: number;
    series_id: number;
    occurrence_key: string;
    user_id: number;
    project_id: number | null;
    task_id: number | null;
    parent_event_id: number | null;
    recurrence_id: string | null;
    is_recurring: boolean;
    is_override: boolean;
    title: string;
    description: string | null;
    starts_at: string | null;
    ends_at: string | null;
    starts_at_local: string | null;
    ends_at_local: string | null;
    start_date: string | null;
    end_date: string | null;
    is_all_day: boolean;
    is_cancelled: boolean;
    recurrence_rule: string | null;
    recurrence_until: string | null;
    project?: { id: number; name: string } | null;
    task?: { id: number; title: string; status: TaskStatus } | null;
    has_conflict: boolean;
};

export type TaskDeadlineItem = {
    id: number;
    title: string;
    status: TaskStatus;
    priority: TaskPriority;
    due_at: string | null;
    due_at_local: string | null;
    due_date_local: string | null;
    project: { id: number; name: string } | null;
};

export type ConflictWarning = {
    id: number;
    title: string;
    starts_at_local: string | null;
    ends_at_local: string | null;
};

export type TaskOption = {
    id: number;
    title: string;
    status: TaskStatus;
};
