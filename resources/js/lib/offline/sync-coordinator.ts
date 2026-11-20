import {
    getUserDb,
    saveAuthLease,
    type ManagjehUserDb,
} from '@/lib/offline/db';
import {
    CalendarRepository,
    ProjectRepository,
    TaskRepository,
} from '@/lib/offline/repositories/domain-repositories';
import { generateUuid } from '@/lib/offline/timezone';
import type {
    AuthLeaseRecord,
    LocalCalendarEventRecord,
    LocalProjectRecord,
    LocalTaskRecord,
    OutboxRecord,
    SyncEntityType,
} from '@/lib/offline/types';
import { requestBackgroundSync } from '@/lib/pwa/register-sw';
import {
    pull as syncPullRoute,
    push as syncPushRoute,
    status as syncStatusRoute,
} from '@/routes/sync';

const BACKOFF_SECONDS = [5, 15, 60, 300];
const BATCH_LIMIT = 50;

export interface SyncStatusResponse {
    user_id: number;
    name: string;
    email: string;
    timezone: string;
    whatsapp_enabled: boolean;
    whatsapp_verified: boolean;
    whatsapp_number_masked: string | null;
    quiet_hours: string;
    server_time: string;
    lease_ttl_days: number;
}

interface PushOperationResult {
    operation_id: string;
    entity_type: SyncEntityType;
    entity_uuid: string;
    status: 'applied' | 'merged' | 'conflict' | 'rejected';
    record: Record<string, unknown> | null;
    server_record: Record<string, unknown> | null;
    conflict_reason: string | null;
    conflicting_fields: string[];
    error: string | null;
}

interface PushResponse {
    results: PushOperationResult[];
    server_time: string;
}

interface PullResponse {
    projects: Array<Omit<LocalProjectRecord, '_sync_status'>>;
    tasks: Array<Omit<LocalTaskRecord, '_sync_status'>>;
    calendar_events: Array<Omit<LocalCalendarEventRecord, '_sync_status'>>;
    tombstones: Array<{
        entity_type: SyncEntityType;
        entity_uuid: string;
        deleted_at: string;
    }>;
    ai_planner_latest: unknown;
    server_time: string;
    has_more: boolean;
}

export interface SyncCycleOutcome {
    ok: boolean;
    sessionExpired: boolean;
    pushedCount: number;
    pulledCount: number;
    conflictCount: number;
    error: string | null;
    lease: AuthLeaseRecord | null;
}

function getXsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : '';
}

function computeNextRetryIso(retryCount: number): string {
    const idx = Math.min(
        Math.max(0, retryCount - 1),
        BACKOFF_SECONDS.length - 1,
    );
    const delayMs = BACKOFF_SECONDS[idx] * 1000;
    return new Date(Date.now() + delayMs).toISOString();
}

export class SyncCoordinator {
    private readonly db: ManagjehUserDb;
    private readonly projects: ProjectRepository;
    private readonly tasks: TaskRepository;
    private readonly calendar: CalendarRepository;
    private inFlightPromise: Promise<SyncCycleOutcome> | null = null;
    private retryTimer: number | null = null;
    private onSyncEvent?: () => void;

    constructor(userId: number, onSyncEvent?: () => void) {
        this.db = getUserDb(userId);
        this.projects = new ProjectRepository(this.db);
        this.tasks = new TaskRepository(this.db);
        this.calendar = new CalendarRepository(this.db);
        this.onSyncEvent = onSyncEvent;
    }

    dispose(): void {
        if (this.retryTimer !== null && typeof window !== 'undefined') {
            window.clearTimeout(this.retryTimer);
            this.retryTimer = null;
        }
    }

    async syncNow(forceRetry: boolean = false): Promise<SyncCycleOutcome> {
        if (this.inFlightPromise) {
            return this.inFlightPromise;
        }

        if (typeof navigator !== 'undefined' && !navigator.onLine) {
            return {
                ok: false,
                sessionExpired: false,
                pushedCount: 0,
                pulledCount: 0,
                conflictCount: await this.countConflicts(),
                error: null,
                lease: null,
            };
        }

        this.inFlightPromise = this.runSyncCycle(forceRetry).finally(() => {
            this.inFlightPromise = null;
            this.onSyncEvent?.();
        });

        return this.inFlightPromise;
    }

    async resolveConflict(
        outboxItem: OutboxRecord,
        choice: 'keep_local' | 'use_server',
    ): Promise<void> {
        if (outboxItem.local_id === undefined) {
            return;
        }

        if (choice === 'use_server') {
            await this.db.transaction(
                'rw',
                this.db.projects,
                this.db.tasks,
                this.db.calendar_events,
                this.db.outbox,
                async () => {
                    await this.db.outbox.delete(outboxItem.local_id!);

                    const serverRec = outboxItem.server_conflict_record;
                    if (!serverRec) {
                        await this.deleteEntityLocally(
                            outboxItem.entity_type,
                            outboxItem.entity_uuid,
                        );
                    } else {
                        await this.upsertEntityFromServer(
                            outboxItem.entity_type,
                            serverRec,
                        );
                    }
                },
            );

            this.onSyncEvent?.();
            return;
        }

        // choice === 'keep_local': push with new operation_id and force_overwrite=true
        const newOperationId = generateUuid();
        const serverVersion =
            typeof outboxItem.server_conflict_record?.version === 'number'
                ? outboxItem.server_conflict_record.version
                : outboxItem.base_version;

        const forcePayload = {
            operations: [
                {
                    operation_id: newOperationId,
                    entity_type: outboxItem.entity_type,
                    entity_uuid: outboxItem.entity_uuid,
                    action: outboxItem.action,
                    base_version: serverVersion,
                    changed_fields: outboxItem.changed_fields,
                    base_snapshot: null,
                    payload: outboxItem.payload,
                    force_overwrite: true,
                },
            ],
        };

        const response = await fetch(syncPushRoute.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': getXsrfToken(),
            },
            body: JSON.stringify(forcePayload),
        });

        if (!response.ok) {
            throw new Error(
                `Gagal menerapkan versi lokal (HTTP ${response.status}).`,
            );
        }

        const data = (await response.json()) as PushResponse;
        const result = data.results[0];

        if (
            result &&
            (result.status === 'applied' || result.status === 'merged')
        ) {
            await this.db.transaction(
                'rw',
                this.db.projects,
                this.db.tasks,
                this.db.calendar_events,
                this.db.outbox,
                async () => {
                    await this.db.outbox.delete(outboxItem.local_id!);
                    if (result.record) {
                        await this.upsertEntityFromServer(
                            outboxItem.entity_type,
                            result.record,
                        );
                    }
                },
            );
            this.onSyncEvent?.();
            return;
        }

        throw new Error(
            result?.error ?? 'Konflik tidak dapat diselesaikan saat ini.',
        );
    }

    private async runSyncCycle(
        forceRetry: boolean = false,
    ): Promise<SyncCycleOutcome> {
        let lease: AuthLeaseRecord | null = null;
        let pushedCount = 0;
        let pulledCount = 0;

        try {
            // 1. Renew offline lease & verify session status first.
            const statusResp = await fetch(syncStatusRoute.url(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (statusResp.status === 401 || statusResp.status === 419) {
                return {
                    ok: false,
                    sessionExpired: true,
                    pushedCount: 0,
                    pulledCount: 0,
                    conflictCount: await this.countConflicts(),
                    error: 'Sesi login berakhir. Silakan login kembali agar sinkronisasi dilanjutkan.',
                    lease: null,
                };
            }

            if (statusResp.ok) {
                const statusData =
                    (await statusResp.json()) as SyncStatusResponse;
                if (statusData.user_id !== this.db.userId) {
                    return {
                        ok: false,
                        sessionExpired: true,
                        pushedCount: 0,
                        pulledCount: 0,
                        conflictCount: await this.countConflicts(),
                        error: 'Akun aktif pada server berbeda.',
                        lease: null,
                    };
                }

                lease = await saveAuthLease({
                    user_id: statusData.user_id,
                    name: statusData.name,
                    email: statusData.email,
                    timezone: statusData.timezone,
                    whatsapp_enabled: statusData.whatsapp_enabled,
                    whatsapp_verified: statusData.whatsapp_verified,
                    whatsapp_number_masked: statusData.whatsapp_number_masked,
                    quiet_hours: statusData.quiet_hours,
                    leaseTtlDays: statusData.lease_ttl_days,
                    serverTime: statusData.server_time,
                });
            }

            // 2. Push pending Outbox entries in batches.
            const pushResult = await this.pushPendingOutbox(forceRetry);
            if (pushResult.sessionExpired) {
                return {
                    ok: false,
                    sessionExpired: true,
                    pushedCount: pushResult.pushedCount,
                    pulledCount: 0,
                    conflictCount: await this.countConflicts(),
                    error: 'Sesi login berakhir. Perubahan lokal Anda tetap tersimpan aman.',
                    lease,
                };
            }
            pushedCount = pushResult.pushedCount;

            // 3. Pull latest changes & tombstones incrementally.
            const pullResult = await this.pullIncremental();
            if (pullResult.sessionExpired) {
                return {
                    ok: false,
                    sessionExpired: true,
                    pushedCount,
                    pulledCount: pullResult.pulledCount,
                    conflictCount: await this.countConflicts(),
                    error: 'Sesi login berakhir.',
                    lease,
                };
            }
            pulledCount = pullResult.pulledCount;

            const remainingFailed = await this.db.outbox
                .where('status')
                .equals('failed')
                .first();

            await this.db.sync_meta.put({
                key: 'last_synced_at',
                value: new Date().toISOString(),
                updated_at: new Date().toISOString(),
            });
            await this.db.sync_meta.put({
                key: 'last_error',
                value: remainingFailed?.last_error ?? null,
                updated_at: new Date().toISOString(),
            });

            return {
                ok: !remainingFailed,
                sessionExpired: false,
                pushedCount,
                pulledCount,
                conflictCount: await this.countConflicts(),
                error: remainingFailed?.last_error ?? null,
                lease,
            };
        } catch (err) {
            const message =
                err instanceof Error
                    ? err.message
                    : 'Gagal terhubung ke server untuk sinkronisasi.';

            await this.db.sync_meta.put({
                key: 'last_error',
                value: message,
                updated_at: new Date().toISOString(),
            });

            this.scheduleRetry(1);
            void requestBackgroundSync();

            return {
                ok: false,
                sessionExpired: false,
                pushedCount,
                pulledCount,
                conflictCount: await this.countConflicts(),
                error: message,
                lease,
            };
        }
    }

    private async pushPendingOutbox(forceRetry: boolean = false): Promise<{
        pushedCount: number;
        sessionExpired: boolean;
    }> {
        let pushedCount = 0;

        // Recover any orphaned `in_flight` operations left behind by a prior tab close/reload.
        await this.db.outbox
            .where('status')
            .equals('in_flight')
            .modify({ status: 'pending' });

        while (true) {
            const nowIso = new Date().toISOString();
            const candidates = (await this.db.outbox.toArray())
                .filter(
                    (item) =>
                        item.status === 'pending' ||
                        (item.status === 'failed' &&
                            (forceRetry ||
                                !item.next_retry_at ||
                                item.next_retry_at <= nowIso)),
                )
                .slice(0, BATCH_LIMIT);

            if (candidates.length === 0) {
                break;
            }

            // Regenerate operation_id for previously failed operations so server-side retries run fresh.
            for (const item of candidates) {
                if (item.status === 'failed' && item.local_id !== undefined) {
                    const freshOpId = generateUuid();
                    item.operation_id = freshOpId;
                    await this.db.outbox.update(item.local_id, {
                        operation_id: freshOpId,
                    });
                }
            }

            const ids = candidates
                .map((item) => item.local_id)
                .filter((id): id is number => id !== undefined);

            await this.db.outbox
                .where('local_id')
                .anyOf(ids)
                .modify({ status: 'in_flight' });

            let response: Response;
            try {
                response = await fetch(syncPushRoute.url(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': getXsrfToken(),
                    },
                    body: JSON.stringify({
                        operations: candidates.map((item) => ({
                            operation_id: item.operation_id,
                            entity_type: item.entity_type,
                            entity_uuid: item.entity_uuid,
                            action: item.action,
                            base_version: item.base_version,
                            changed_fields: item.changed_fields,
                            base_snapshot: item.base_snapshot,
                            payload: item.payload,
                        })),
                    }),
                });
            } catch (networkErr) {
                await this.markCandidatesFailed(
                    candidates,
                    'Koneksi terputus saat mengirim perubahan.',
                );
                throw networkErr;
            }

            if (response.status === 401 || response.status === 419) {
                // Keep Outbox safely pending so user never loses work after re-login.
                await this.db.outbox
                    .where('local_id')
                    .anyOf(ids)
                    .modify({ status: 'pending' });
                return { pushedCount, sessionExpired: true };
            }

            if (!response.ok) {
                await this.markCandidatesFailed(
                    candidates,
                    `Server mengembalikan HTTP ${response.status}.`,
                );
                throw new Error(
                    `Sinkronisasi push gagal (HTTP ${response.status}).`,
                );
            }

            const data = (await response.json()) as PushResponse;
            const byOpId = new Map<string, PushOperationResult>();
            for (const res of data.results) {
                byOpId.set(res.operation_id, res);
            }

            for (const item of candidates) {
                if (item.local_id === undefined) {
                    continue;
                }

                const res = byOpId.get(item.operation_id);
                if (!res) {
                    continue;
                }

                if (res.status === 'applied' || res.status === 'merged') {
                    pushedCount++;
                    await this.db.transaction(
                        'rw',
                        this.db.projects,
                        this.db.tasks,
                        this.db.calendar_events,
                        this.db.outbox,
                        async () => {
                            await this.db.outbox.delete(item.local_id!);
                            if (res.record) {
                                await this.upsertEntityFromServer(
                                    item.entity_type,
                                    res.record,
                                );
                            }
                        },
                    );
                } else if (res.status === 'conflict') {
                    await this.db.outbox.update(item.local_id, {
                        status: 'conflict',
                        server_conflict_record: res.server_record,
                        conflict_reason:
                            res.conflict_reason ?? 'version_mismatch',
                        last_error:
                            res.conflict_reason === 'deleted_on_server'
                                ? 'Data ini telah dihapus di perangkat lain.'
                                : 'Data ini juga diubah di perangkat lain.',
                    });
                    await this.markEntityStatus(
                        item.entity_type,
                        item.entity_uuid,
                        'conflict',
                    );
                } else {
                    await this.db.outbox.update(item.local_id, {
                        status: 'failed',
                        retry_count: item.retry_count + 1,
                        next_retry_at: computeNextRetryIso(
                            item.retry_count + 1,
                        ),
                        last_error:
                            res.error ??
                            'Operasi ditolak oleh validasi server.',
                    });
                    await this.markEntityStatus(
                        item.entity_type,
                        item.entity_uuid,
                        'error',
                    );
                }
            }

            if (candidates.length < BATCH_LIMIT) {
                break;
            }
        }

        return { pushedCount, sessionExpired: false };
    }

    private async pullIncremental(): Promise<{
        pulledCount: number;
        sessionExpired: boolean;
    }> {
        let pulledCount = 0;
        const cursorMeta = await this.db.sync_meta.get('cursor');
        let since = cursorMeta?.value ?? null;

        // Loop at most 5 pages in a single cycle to prevent infinite loops.
        for (let page = 0; page < 5; page++) {
            const url = syncPullRoute.url({
                query: since ? { since, limit: 500 } : { limit: 500 },
            });

            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (response.status === 401 || response.status === 419) {
                return { pulledCount, sessionExpired: true };
            }

            if (!response.ok) {
                throw new Error(
                    `Sinkronisasi pull gagal (HTTP ${response.status}).`,
                );
            }

            const data = (await response.json()) as PullResponse;

            for (const project of data.projects) {
                await this.projects.applyServerRecord({
                    ...project,
                    _sync_status: 'synced',
                });
                pulledCount++;
            }

            for (const task of data.tasks) {
                await this.tasks.applyServerRecord({
                    ...task,
                    _sync_status: 'synced',
                });
                pulledCount++;
            }

            for (const event of data.calendar_events) {
                await this.calendar.applyServerRecord({
                    ...event,
                    _sync_status: 'synced',
                });
                pulledCount++;
            }

            for (const tombstone of data.tombstones) {
                if (tombstone.entity_type === 'project') {
                    await this.projects.applyTombstone(tombstone.entity_uuid);
                } else if (tombstone.entity_type === 'task') {
                    await this.tasks.applyTombstone(tombstone.entity_uuid);
                } else if (tombstone.entity_type === 'calendar_event') {
                    await this.calendar.applyTombstone(tombstone.entity_uuid);
                }
            }

            if (data.ai_planner_latest) {
                await this.db.cached_snapshots.put({
                    key: 'ai_planner_latest',
                    payload: data.ai_planner_latest,
                    updated_at: data.server_time,
                });
            }

            since = data.server_time;
            await this.db.sync_meta.put({
                key: 'cursor',
                value: data.server_time,
                updated_at: data.server_time,
            });
            await this.db.sync_meta.put({
                key: 'initial_hydrated',
                value: 'true',
                updated_at: data.server_time,
            });

            if (!data.has_more) {
                break;
            }
        }

        return { pulledCount, sessionExpired: false };
    }

    private async markCandidatesFailed(
        candidates: OutboxRecord[],
        errorMessage: string,
    ): Promise<void> {
        let maxRetry = 1;
        for (const item of candidates) {
            if (item.local_id === undefined) {
                continue;
            }
            const nextRetryCount = item.retry_count + 1;
            maxRetry = Math.max(maxRetry, nextRetryCount);
            await this.db.outbox.update(item.local_id, {
                status: 'failed',
                retry_count: nextRetryCount,
                next_retry_at: computeNextRetryIso(nextRetryCount),
                last_error: errorMessage,
            });
        }
        this.scheduleRetry(maxRetry);
    }

    private scheduleRetry(retryCount: number): void {
        if (typeof window === 'undefined') {
            return;
        }
        if (this.retryTimer !== null) {
            window.clearTimeout(this.retryTimer);
        }
        const idx = Math.min(
            Math.max(0, retryCount - 1),
            BACKOFF_SECONDS.length - 1,
        );
        this.retryTimer = window.setTimeout(() => {
            this.retryTimer = null;
            if (navigator.onLine) {
                void this.syncNow();
            }
        }, BACKOFF_SECONDS[idx] * 1000);
    }

    private async upsertEntityFromServer(
        entityType: SyncEntityType,
        record: Record<string, unknown>,
    ): Promise<void> {
        if (entityType === 'project') {
            await this.db.projects.put({
                ...(record as unknown as Omit<
                    LocalProjectRecord,
                    '_sync_status'
                >),
                _sync_status: 'synced',
            });
        } else if (entityType === 'task') {
            await this.db.tasks.put({
                ...(record as unknown as Omit<LocalTaskRecord, '_sync_status'>),
                _sync_status: 'synced',
            });
        } else if (entityType === 'calendar_event') {
            await this.db.calendar_events.put({
                ...(record as unknown as Omit<
                    LocalCalendarEventRecord,
                    '_sync_status'
                >),
                _sync_status: 'synced',
            });
        }
    }

    private async deleteEntityLocally(
        entityType: SyncEntityType,
        entityUuid: string,
    ): Promise<void> {
        if (entityType === 'project') {
            await this.db.projects.delete(entityUuid);
        } else if (entityType === 'task') {
            await this.db.tasks.delete(entityUuid);
        } else if (entityType === 'calendar_event') {
            await this.db.calendar_events.delete(entityUuid);
        }
    }

    private async markEntityStatus(
        entityType: SyncEntityType,
        entityUuid: string,
        status: 'conflict' | 'error',
    ): Promise<void> {
        if (entityType === 'project') {
            await this.db.projects.update(entityUuid, { _sync_status: status });
        } else if (entityType === 'task') {
            await this.db.tasks.update(entityUuid, { _sync_status: status });
        } else if (entityType === 'calendar_event') {
            await this.db.calendar_events.update(entityUuid, {
                _sync_status: status,
            });
        }
    }

    private async countConflicts(): Promise<number> {
        return this.db.outbox.where('status').equals('conflict').count();
    }
}
