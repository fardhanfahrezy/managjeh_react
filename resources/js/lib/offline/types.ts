import type { ProjectStatus, TaskPriority, TaskStatus } from '@/types/models';

export type SyncEntityType = 'project' | 'task' | 'calendar_event';

export type LocalSyncState = 'synced' | 'pending' | 'conflict' | 'error';

export type OutboxAction = 'upsert' | 'delete';

export type OutboxStatus = 'pending' | 'in_flight' | 'failed' | 'conflict';

export interface BaseSyncRecord {
    uuid: string;
    server_id: number | null;
    user_id: number;
    version: number;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    _sync_status: LocalSyncState;
}

export interface LocalProjectRecord extends BaseSyncRecord {
    name: string;
    description: string | null;
    status: ProjectStatus;
    start_date: string | null;
    due_date: string | null;
    completed_at: string | null;
}

export interface LocalTaskRecord extends BaseSyncRecord {
    project_uuid: string | null;
    project_id: number | null;
    parent_uuid: string | null;
    parent_id: number | null;
    title: string;
    description: string | null;
    status: TaskStatus;
    priority: TaskPriority;
    due_at: string | null;
    completed_at: string | null;
    sort_order: number;
}

export interface LocalCalendarEventRecord extends BaseSyncRecord {
    project_uuid: string | null;
    project_id: number | null;
    task_uuid: string | null;
    task_id: number | null;
    parent_event_uuid: string | null;
    parent_event_id: number | null;
    recurrence_id: string | null;
    title: string;
    description: string | null;
    starts_at: string | null;
    ends_at: string | null;
    start_date: string | null;
    end_date: string | null;
    is_all_day: boolean;
    is_cancelled: boolean;
    recurrence_rule: string | null;
    recurrence_until: string | null;
    recurrence_exceptions: string[];
}

export interface OutboxRecord {
    local_id?: number;
    operation_id: string;
    user_id: number;
    entity_type: SyncEntityType;
    entity_uuid: string;
    action: OutboxAction;
    base_version: number;
    changed_fields: string[];
    base_snapshot: Record<string, unknown> | null;
    payload: Record<string, unknown>;
    status: OutboxStatus;
    retry_count: number;
    next_retry_at: string | null;
    last_error: string | null;
    server_conflict_record: Record<string, unknown> | null;
    conflict_reason: string | null;
    created_at: string;
}

export interface SyncMetaRecord {
    key: string;
    value: string | null;
    updated_at: string;
}

export interface CachedSnapshotRecord {
    key: string;
    payload: unknown;
    updated_at: string;
}

/**
 * Non-sensitive offline session lease stored locally (`D-21`).
 * Never stores passwords, OTPs, recovery codes, passkey secrets, or API tokens.
 */
export interface AuthLeaseRecord {
    user_id: number;
    name: string;
    email: string;
    timezone: string;
    whatsapp_enabled: boolean;
    whatsapp_verified: boolean;
    whatsapp_number_masked: string | null;
    quiet_hours: string;
    lease_expires_at: string;
    last_verified_at: string;
}
