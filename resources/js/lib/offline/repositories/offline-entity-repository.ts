import type { Table } from 'dexie';
import type { ManagjehUserDb } from '@/lib/offline/db';
import { generateUuid } from '@/lib/offline/timezone';
import type {
    BaseSyncRecord,
    OutboxRecord,
    SyncEntityType,
} from '@/lib/offline/types';

function valuesEqual(a: unknown, b: unknown): boolean {
    return JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
}

/**
 * Generic Local-First Repository with atomic Entity + Outbox transactions (`D-20`, `D-24`).
 * Designed to be reused directly by MVP 3 modules (Habits, Finance, Learning, Career, Journal).
 */
export class OfflineEntityRepository<T extends BaseSyncRecord> {
    constructor(
        protected readonly db: ManagjehUserDb,
        protected readonly table: Table<T, string>,
        protected readonly entityType: SyncEntityType,
        protected readonly trackedFields: readonly (keyof T & string)[],
    ) {}

    async listActive(): Promise<T[]> {
        const all = await this.table.toArray();
        return all.filter((record) => record.deleted_at === null);
    }

    async listAll(): Promise<T[]> {
        return this.table.toArray();
    }

    async getByUuid(uuid: string): Promise<T | undefined> {
        return this.table.get(uuid);
    }

    async getByServerId(serverId: number): Promise<T | undefined> {
        return this.table.where('server_id').equals(serverId).first();
    }

    protected extractTrackedSnapshot(record: T): Record<string, unknown> {
        const snapshot: Record<string, unknown> = {};
        for (const field of this.trackedFields) {
            snapshot[field] = record[field] ?? null;
        }
        return snapshot;
    }

    async createLocal(
        record: T,
        outboxPayload?: Record<string, unknown>,
    ): Promise<T> {
        const now = new Date().toISOString();
        const persisted: T = {
            ...record,
            uuid: record.uuid || generateUuid(),
            version: 0,
            created_at: record.created_at || now,
            updated_at: now,
            deleted_at: null,
            _sync_status: 'pending',
        };

        const payload = outboxPayload ?? this.extractTrackedSnapshot(persisted);
        const changedFields = Object.keys(payload);

        await this.db.transaction(
            'rw',
            this.table,
            this.db.outbox,
            async () => {
                await this.table.put(persisted);

                const entry: OutboxRecord = {
                    operation_id: generateUuid(),
                    user_id: this.db.userId,
                    entity_type: this.entityType,
                    entity_uuid: persisted.uuid,
                    action: 'upsert',
                    base_version: 0,
                    changed_fields: changedFields,
                    base_snapshot: null,
                    payload,
                    status: 'pending',
                    retry_count: 0,
                    next_retry_at: null,
                    last_error: null,
                    server_conflict_record: null,
                    conflict_reason: null,
                    created_at: now,
                };

                await this.db.outbox.add(entry);
            },
        );

        return persisted;
    }

    async updateLocal(
        uuid: string,
        patch: Partial<T>,
        payloadBuilder?: (updated: T) => Record<string, unknown>,
    ): Promise<T> {
        const now = new Date().toISOString();

        return this.db.transaction(
            'rw',
            this.table,
            this.db.outbox,
            async () => {
                const existing = await this.table.get(uuid);
                if (!existing) {
                    throw new Error(
                        `Record ${this.entityType}:${uuid} tidak ditemukan di penyimpanan lokal.`,
                    );
                }

                const updated: T = {
                    ...existing,
                    ...patch,
                    uuid: existing.uuid,
                    user_id: existing.user_id,
                    updated_at: now,
                    _sync_status: 'pending',
                };

                const nextChangedFields: string[] = [];
                for (const field of this.trackedFields) {
                    if (!valuesEqual(existing[field], updated[field])) {
                        nextChangedFields.push(field);
                    }
                }

                await this.table.put(updated);

                if (nextChangedFields.length === 0) {
                    return updated;
                }

                const payload = payloadBuilder
                    ? payloadBuilder(updated)
                    : this.extractTrackedSnapshot(updated);

                // Coalesce with any pending (not in_flight / not conflict) upsert for this entity
                // so rapid offline edits form a single clean operation preserving original base_version.
                const pendingUpsert = await this.db.outbox
                    .where('entity_uuid')
                    .equals(uuid)
                    .filter(
                        (item) =>
                            item.entity_type === this.entityType &&
                            item.action === 'upsert' &&
                            (item.status === 'pending' ||
                                item.status === 'failed'),
                    )
                    .last();

                if (pendingUpsert?.local_id !== undefined) {
                    const mergedFields = Array.from(
                        new Set([
                            ...pendingUpsert.changed_fields,
                            ...nextChangedFields,
                        ]),
                    );

                    await this.db.outbox.update(pendingUpsert.local_id, {
                        operation_id: generateUuid(),
                        changed_fields: mergedFields,
                        payload,
                        status: 'pending',
                        retry_count: 0,
                        next_retry_at: null,
                        last_error: null,
                    });
                } else {
                    const entry: OutboxRecord = {
                        operation_id: generateUuid(),
                        user_id: this.db.userId,
                        entity_type: this.entityType,
                        entity_uuid: uuid,
                        action: 'upsert',
                        base_version: existing.version,
                        changed_fields: nextChangedFields,
                        base_snapshot: this.extractTrackedSnapshot(existing),
                        payload,
                        status: 'pending',
                        retry_count: 0,
                        next_retry_at: null,
                        last_error: null,
                        server_conflict_record: null,
                        conflict_reason: null,
                        created_at: now,
                    };

                    await this.db.outbox.add(entry);
                }

                return updated;
            },
        );
    }

    async deleteLocal(uuid: string): Promise<void> {
        const now = new Date().toISOString();

        await this.db.transaction(
            'rw',
            this.table,
            this.db.outbox,
            async () => {
                const existing = await this.table.get(uuid);
                if (!existing) {
                    return;
                }

                // If this record was created offline and never synced to the server yet,
                // remove its pending outbox entries and hard-delete locally.
                if (existing.version === 0 && existing.server_id === null) {
                    const pendingIds = await this.db.outbox
                        .where('entity_uuid')
                        .equals(uuid)
                        .filter((item) => item.entity_type === this.entityType)
                        .primaryKeys();

                    if (pendingIds.length > 0) {
                        await this.db.outbox.bulkDelete(pendingIds);
                    }

                    await this.table.delete(uuid);
                    return;
                }

                // Remove un-sent pending upserts before enqueueing delete so we don't resurrect or conflict unnecessarily.
                const pendingUpsertIds = await this.db.outbox
                    .where('entity_uuid')
                    .equals(uuid)
                    .filter(
                        (item) =>
                            item.entity_type === this.entityType &&
                            item.action === 'upsert' &&
                            (item.status === 'pending' ||
                                item.status === 'failed'),
                    )
                    .primaryKeys();

                if (pendingUpsertIds.length > 0) {
                    await this.db.outbox.bulkDelete(pendingUpsertIds);
                }

                await this.table.put({
                    ...existing,
                    deleted_at: now,
                    updated_at: now,
                    _sync_status: 'pending',
                });

                const entry: OutboxRecord = {
                    operation_id: generateUuid(),
                    user_id: this.db.userId,
                    entity_type: this.entityType,
                    entity_uuid: uuid,
                    action: 'delete',
                    base_version: existing.version,
                    changed_fields: ['deleted_at'],
                    base_snapshot: this.extractTrackedSnapshot(existing),
                    payload: {},
                    status: 'pending',
                    retry_count: 0,
                    next_retry_at: null,
                    last_error: null,
                    server_conflict_record: null,
                    conflict_reason: null,
                    created_at: now,
                };

                await this.db.outbox.add(entry);
            },
        );
    }

    /**
     * Merges a pulled server record into IndexedDB while preserving any un-synced local mutations.
     */
    async applyServerRecord(record: T): Promise<void> {
        await this.db.transaction(
            'rw',
            this.table,
            this.db.outbox,
            async () => {
                const hasPendingLocal =
                    (await this.db.outbox
                        .where('entity_uuid')
                        .equals(record.uuid)
                        .filter((item) => item.entity_type === this.entityType)
                        .count()) > 0;

                if (hasPendingLocal) {
                    return;
                }

                await this.table.put({
                    ...record,
                    _sync_status: 'synced',
                });
            },
        );
    }

    async applyTombstone(entityUuid: string): Promise<void> {
        await this.db.transaction(
            'rw',
            this.table,
            this.db.outbox,
            async () => {
                const hasConflict =
                    (await this.db.outbox
                        .where('entity_uuid')
                        .equals(entityUuid)
                        .filter(
                            (item) =>
                                item.entity_type === this.entityType &&
                                item.status === 'conflict',
                        )
                        .count()) > 0;

                if (hasConflict) {
                    return;
                }

                await this.table.delete(entityUuid);
            },
        );
    }
}
