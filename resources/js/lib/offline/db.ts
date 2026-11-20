import Dexie, { type Table } from 'dexie';
import type {
    AuthLeaseRecord,
    CachedSnapshotRecord,
    LocalCalendarEventRecord,
    LocalProjectRecord,
    LocalTaskRecord,
    OutboxRecord,
    SyncMetaRecord,
} from '@/lib/offline/types';

const REGISTRY_DB_NAME = 'managjeh_offline_registry';
const USER_DB_PREFIX = 'managjeh_offline_u';
const DEFAULT_LEASE_DAYS = 7;

export class ManagjehRegistryDb extends Dexie {
    leases!: Table<AuthLeaseRecord, number>;
    meta!: Table<SyncMetaRecord, string>;

    constructor() {
        super(REGISTRY_DB_NAME);
        this.version(1).stores({
            leases: 'user_id, last_verified_at',
            meta: 'key',
        });
    }
}

export class ManagjehUserDb extends Dexie {
    projects!: Table<LocalProjectRecord, string>;
    tasks!: Table<LocalTaskRecord, string>;
    calendar_events!: Table<LocalCalendarEventRecord, string>;
    outbox!: Table<OutboxRecord, number>;
    sync_meta!: Table<SyncMetaRecord, string>;
    cached_snapshots!: Table<CachedSnapshotRecord, string>;

    readonly userId: number;

    constructor(userId: number) {
        super(`${USER_DB_PREFIX}${userId}`);
        this.userId = userId;

        this.version(1).stores({
            projects:
                'uuid, server_id, status, due_date, updated_at, deleted_at, _sync_status',
            tasks: 'uuid, server_id, project_uuid, parent_uuid, status, priority, due_at, updated_at, deleted_at, _sync_status',
            calendar_events:
                'uuid, server_id, project_uuid, task_uuid, parent_event_uuid, recurrence_id, starts_at, start_date, updated_at, deleted_at, _sync_status',
            outbox: '++local_id, &operation_id, entity_type, entity_uuid, status, next_retry_at, created_at',
            sync_meta: 'key',
            cached_snapshots: 'key, updated_at',
        });
    }
}

let registryDbInstance: ManagjehRegistryDb | null = null;
const userDbPool = new Map<number, ManagjehUserDb>();

export function isIndexedDbAvailable(): boolean {
    return typeof window !== 'undefined' && 'indexedDB' in window;
}

export function getRegistryDb(): ManagjehRegistryDb {
    if (!registryDbInstance) {
        registryDbInstance = new ManagjehRegistryDb();
    }
    return registryDbInstance;
}

export function getUserDb(userId: number): ManagjehUserDb {
    const existing = userDbPool.get(userId);
    if (existing) {
        return existing;
    }

    const db = new ManagjehUserDb(userId);
    userDbPool.set(userId, db);
    return db;
}

export async function deleteUserDb(userId: number): Promise<void> {
    if (!isIndexedDbAvailable()) {
        return;
    }

    const existing = userDbPool.get(userId);
    if (existing) {
        existing.close();
        userDbPool.delete(userId);
    }

    await Dexie.delete(`${USER_DB_PREFIX}${userId}`);

    const registry = getRegistryDb();
    await registry.leases.delete(userId);
    const activeMeta = await registry.meta.get('active_user_id');
    if (activeMeta?.value === String(userId)) {
        await registry.meta.delete('active_user_id');
    }
}

/**
 * Multi-account isolation (`D-22`): when a different user logs in on the same device,
 * purge any previous account's local IndexedDB database and lease record.
 */
export async function enforceSingleActiveAccount(
    currentUserId: number,
): Promise<void> {
    if (!isIndexedDbAvailable()) {
        return;
    }

    const registry = getRegistryDb();
    const allLeases = await registry.leases.toArray();
    for (const lease of allLeases) {
        if (lease.user_id !== currentUserId) {
            await deleteUserDb(lease.user_id);
        }
    }

    await registry.meta.put({
        key: 'active_user_id',
        value: String(currentUserId),
        updated_at: new Date().toISOString(),
    });
}

export async function saveAuthLease(
    input: Omit<AuthLeaseRecord, 'lease_expires_at' | 'last_verified_at'> & {
        leaseTtlDays?: number;
        serverTime?: string;
    },
): Promise<AuthLeaseRecord> {
    const now = input.serverTime ? new Date(input.serverTime) : new Date();
    const ttlDays = input.leaseTtlDays ?? DEFAULT_LEASE_DAYS;
    const expiresAt = new Date(
        now.getTime() + ttlDays * 24 * 60 * 60 * 1000,
    ).toISOString();

    const lease: AuthLeaseRecord = {
        user_id: input.user_id,
        name: input.name,
        email: input.email,
        timezone: input.timezone || 'Asia/Jakarta',
        whatsapp_enabled: input.whatsapp_enabled,
        whatsapp_verified: input.whatsapp_verified,
        whatsapp_number_masked: input.whatsapp_number_masked,
        quiet_hours: input.quiet_hours,
        lease_expires_at: expiresAt,
        last_verified_at: now.toISOString(),
    };

    if (!isIndexedDbAvailable()) {
        return lease;
    }

    const registry = getRegistryDb();
    await registry.leases.put(lease);
    await registry.meta.put({
        key: 'active_user_id',
        value: String(lease.user_id),
        updated_at: now.toISOString(),
    });

    return lease;
}

export async function getActiveAuthLease(): Promise<AuthLeaseRecord | null> {
    if (!isIndexedDbAvailable()) {
        return null;
    }

    const registry = getRegistryDb();
    const activeMeta = await registry.meta.get('active_user_id');
    if (activeMeta?.value) {
        const userId = Number.parseInt(activeMeta.value, 10);
        if (!Number.isNaN(userId)) {
            const found = await registry.leases.get(userId);
            if (found) {
                return found;
            }
        }
    }

    const leases = await registry.leases.toArray();
    return leases[0] ?? null;
}

export function isAuthLeaseValid(
    lease: AuthLeaseRecord | null,
    now: Date = new Date(),
): boolean {
    if (!lease) {
        return false;
    }

    const expiresMs = new Date(lease.lease_expires_at).getTime();
    return !Number.isNaN(expiresMs) && expiresMs > now.getTime();
}
