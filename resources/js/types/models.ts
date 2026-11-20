export type ProjectStatus = 'active' | 'completed' | 'archived';
export type TaskStatus = 'todo' | 'in_progress' | 'completed' | 'cancelled';
export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';

export type ProjectSummary = {
    id: number;
    uuid?: string;
    name: string;
    status: ProjectStatus;
};

export type Task = {
    id: number;
    uuid?: string;
    version?: number;
    user_id: number;
    project_id: number | null;
    project_uuid?: string | null;
    parent_id: number | null;
    parent_uuid?: string | null;
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
    uuid?: string;
    version?: number;
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
    uuid?: string;
    series_id: number;
    series_uuid?: string;
    version?: number;
    occurrence_key: string;
    user_id: number;
    project_id: number | null;
    project_uuid?: string | null;
    task_id: number | null;
    task_uuid?: string | null;
    parent_event_id: number | null;
    parent_event_uuid?: string | null;
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
    project?: { id: number; uuid?: string; name: string } | null;
    task?: {
        id: number;
        uuid?: string;
        title: string;
        status: TaskStatus;
    } | null;
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

export type DashboardTaskItem = {
    id: number;
    uuid?: string;
    title: string;
    status: TaskStatus;
    priority: TaskPriority;
    due_at: string | null;
    due_at_local: string | null;
    is_overdue: boolean;
    project: ProjectSummary | null;
};

export type DashboardProjectItem = {
    id: number;
    uuid?: string;
    name: string;
    status: ProjectStatus;
    due_date: string | null;
    progress: number;
    completed_tasks_count: number;
    non_cancelled_tasks_count: number;
};

export type DashboardWhatsAppStatus = {
    enabled: boolean;
    verified: boolean;
    can_receive: boolean;
    number_masked: string | null;
    quiet_hours: string;
};

export type DashboardCompletedSummary = {
    today_count: number;
    last_7_days_count: number;
    active_count: number;
};

export type AiPlannerPriorityItem = {
    task_id: number;
    task_title?: string;
    reason: string;
    suggested_action: string;
    estimated_minutes: number;
};

export type DashboardData = {
    greeting: string;
    local_date: string;
    local_date_label: string;
    timezone: string;
    today_tasks: DashboardTaskItem[];
    upcoming_tasks: DashboardTaskItem[];
    today_events: CalendarEvent[];
    active_projects: DashboardProjectItem[];
    ai_priorities: {
        summary?: string;
        priorities: AiPlannerPriorityItem[];
        provider?: string;
    } | null;
    whatsapp_status: DashboardWhatsAppStatus;
    completed_summary: DashboardCompletedSummary;
};

export type AiPlannerRunStatus = 'pending' | 'success' | 'failed';

export type AiPlannerWarning = {
    type: string;
    message: string;
};

export type AiPlannerRecommendedReminder = {
    task_id: number;
    task_title?: string;
    remind_at_local: string;
    reason: string;
};

export type AiPlannerResult = {
    summary: string;
    priorities: AiPlannerPriorityItem[];
    recommended_reminders: AiPlannerRecommendedReminder[];
    warnings: AiPlannerWarning[];
};

export type AiPlannerRun = {
    id: number;
    planning_date: string;
    trigger_type: string;
    provider: string;
    model: string | null;
    status: AiPlannerRunStatus;
    result: AiPlannerResult | null;
    error_message: string | null;
    created_at: string | null;
};

export type AiTone = 'concise' | 'balanced' | 'detailed';

export type AiPreferences = {
    tone: AiTone;
    preferred_study_start: string;
    preferred_study_end: string;
    default_focus_minutes: number;
    custom_instruction: string | null;
};

export type NotificationPreferences = {
    daily_summary_enabled: boolean;
    daily_summary_time: string;
    weekly_summary_enabled: boolean;
    weekly_summary_day: number;
    weekly_summary_time: string;
    event_prep_enabled: boolean;
};

export type AiChatRole = 'user' | 'assistant';

export type AiActionProposalStatus =
    | 'pending'
    | 'executed'
    | 'rejected'
    | 'failed'
    | 'expired';

export type AiChatToolCallSummary = {
    tool: string;
    type: 'read' | 'mutation_proposed' | 'mutation_invalid';
    ok?: boolean;
    summary?: string;
    has_conflicts?: boolean;
    error?: string | null;
};

export type AiActionProposal = {
    id: number;
    conversation_id: number;
    ai_chat_message_id: number;
    tool_name: string;
    payload: Record<string, unknown>;
    conflict_preview: ConflictWarning[];
    status: AiActionProposalStatus;
    is_expired: boolean;
    expires_at: string;
    expires_at_local: string;
    decided_at: string | null;
    executed_at: string | null;
    executed_at_local: string | null;
    target_type: string | null;
    target_id: number | null;
    result_summary: string | null;
};

export type AiChatConversation = {
    id: number;
    title: string;
    last_message_at: string | null;
    last_message_at_local: string | null;
};

export type AiChatMessage = {
    id: number;
    conversation_id: number;
    role: AiChatRole;
    content: string;
    provider: string | null;
    model: string | null;
    tool_calls_summary: AiChatToolCallSummary[];
    proposals: AiActionProposal[];
    created_at: string | null;
    created_at_local: string | null;
};

export type AiChatSendResponse = {
    conversation: AiChatConversation;
    user_message: AiChatMessage;
    assistant_message: AiChatMessage;
};

export type AiProposalDecisionSummary = {
    executed: number;
    rejected: number;
    failed: number;
    expired: number;
};
