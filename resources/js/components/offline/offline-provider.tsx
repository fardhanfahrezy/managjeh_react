import { router, usePage } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { toast } from 'sonner';
import { ConflictResolutionDialog } from '@/components/offline/conflict-resolution-dialog';
import { LogoutOutboxDialog } from '@/components/offline/logout-outbox-dialog';
import { OfflineLeaseExpiredScreen } from '@/components/offline/offline-lease-expired-screen';
import { preloadOfflinePageModules } from '@/components/offline/offline-route-renderer';
import type { SyncIndicatorState } from '@/components/offline/sync-status-badge';
import { useNetworkStatus } from '@/hooks/use-network-status';
import {
    deleteUserDb,
    enforceSingleActiveAccount,
    getActiveAuthLease,
    getUserDb,
    isAuthLeaseValid,
    isIndexedDbAvailable,
    saveAuthLease,
    type ManagjehUserDb,
} from '@/lib/offline/db';
import {
    CalendarRepository,
    ProjectRepository,
    TaskRepository,
} from '@/lib/offline/repositories/domain-repositories';
import { SyncCoordinator } from '@/lib/offline/sync-coordinator';
import type { AuthLeaseRecord, OutboxRecord } from '@/lib/offline/types';
import {
    onServiceWorkerSyncTrigger,
    requestBackgroundSync,
} from '@/lib/pwa/register-sw';
import { logout } from '@/routes';
import type { Auth } from '@/types';

const OFFLINE_NAVIGABLE_PREFIXES = [
    '/dashboard',
    '/tasks',
    '/projects',
    '/calendar',
    '/ai-planner',
    '/ai-chat',
];

function isOfflineNavigablePath(pathname: string): boolean {
    return OFFLINE_NAVIGABLE_PREFIXES.some(
        (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
    );
}

export interface OfflineRepositories {
    projects: ProjectRepository;
    tasks: TaskRepository;
    calendar: CalendarRepository;
}

export interface OfflineContextValue {
    isOnline: boolean;
    isReady: boolean;
    hydratedFromServer: boolean;
    isLeaseExpired: boolean;
    sessionExpired: boolean;
    lease: AuthLeaseRecord | null;
    db: ManagjehUserDb | null;
    repositories: OfflineRepositories | null;
    syncState: SyncIndicatorState;
    pendingCount: number;
    conflictCount: number;
    conflicts: OutboxRecord[];
    lastError: string | null;
    lastSyncedAt: string | null;
    dataVersion: number;
    offlineRoute: string | null;
    syncNow: (forceRetry?: boolean) => Promise<void>;
    notifyLocalMutation: () => void;
    resolveConflict: (
        item: OutboxRecord,
        choice: 'keep_local' | 'use_server',
    ) => Promise<void>;
    openConflictDialog: () => void;
    requestLogout: () => Promise<void>;
    navigateOffline: (href: string) => void;
}

const OfflineContext = createContext<OfflineContextValue | null>(null);

export function useOffline(): OfflineContextValue {
    const ctx = useContext(OfflineContext);
    if (!ctx) {
        return {
            isOnline: true,
            isReady: false,
            hydratedFromServer: false,
            isLeaseExpired: false,
            sessionExpired: false,
            lease: null,
            db: null,
            repositories: null,
            syncState: 'synced',
            pendingCount: 0,
            conflictCount: 0,
            conflicts: [],
            lastError: null,
            lastSyncedAt: null,
            dataVersion: 0,
            offlineRoute: null,
            syncNow: async () => {},
            notifyLocalMutation: () => {},
            resolveConflict: async () => {},
            openConflictDialog: () => {},
            requestLogout: async () => {},
            navigateOffline: () => {},
        };
    }
    return ctx;
}

type SharedPageProps = {
    auth?: Auth | { user: null };
};

export function OfflineProvider({
    children,
    fallbackLease = null,
}: {
    children: ReactNode;
    fallbackLease?: AuthLeaseRecord | null;
}) {
    const page = usePage<SharedPageProps>();
    const authUser = page.props.auth?.user ?? null;
    const { isOnline } = useNetworkStatus();

    const [lease, setLease] = useState<AuthLeaseRecord | null>(fallbackLease);
    const [isReady, setIsReady] = useState(false);
    const [hydratedFromServer, setHydratedFromServer] = useState(false);
    const [isSyncing, setIsSyncing] = useState(false);
    const [sessionExpired, setSessionExpired] = useState(false);
    const [pendingCount, setPendingCount] = useState(0);
    const [failedCount, setFailedCount] = useState(0);
    const [conflicts, setConflicts] = useState<OutboxRecord[]>([]);
    const [lastError, setLastError] = useState<string | null>(null);
    const [lastSyncedAt, setLastSyncedAt] = useState<string | null>(null);
    const [dataVersion, setDataVersion] = useState(0);
    const [conflictDialogOpen, setConflictDialogOpen] = useState(false);
    const [logoutDialogOpen, setLogoutDialogOpen] = useState(false);
    const [offlineRoute, setOfflineRoute] = useState<string | null>(null);

    const coordinatorRef = useRef<SyncCoordinator | null>(null);
    const debounceTimerRef = useRef<number | null>(null);

    const activeUserId = authUser?.id ?? lease?.user_id ?? null;

    const db = useMemo(() => {
        if (!activeUserId || !isIndexedDbAvailable()) {
            return null;
        }
        return getUserDb(activeUserId);
    }, [activeUserId]);

    const repositories = useMemo<OfflineRepositories | null>(() => {
        if (!db) {
            return null;
        }
        return {
            projects: new ProjectRepository(db),
            tasks: new TaskRepository(db),
            calendar: new CalendarRepository(db),
        };
    }, [db]);

    const refreshOutboxStats = useCallback(async () => {
        if (!db) {
            return;
        }

        const [allOutbox, hydratedMeta, lastSyncedMeta, lastErrorMeta] =
            await Promise.all([
                db.outbox.toArray(),
                db.sync_meta.get('initial_hydrated'),
                db.sync_meta.get('last_synced_at'),
                db.sync_meta.get('last_error'),
            ]);

        const conflictItems = allOutbox.filter(
            (item) => item.status === 'conflict',
        );
        const failedItems = allOutbox.filter(
            (item) => item.status === 'failed',
        );
        const pendingItems = allOutbox.filter(
            (item) => item.status !== 'conflict',
        );

        setConflicts(conflictItems);
        setFailedCount(failedItems.length);
        setPendingCount(pendingItems.length);
        setHydratedFromServer(hydratedMeta?.value === 'true');
        setLastSyncedAt(lastSyncedMeta?.value ?? null);
        setLastError(
            failedItems[0]?.last_error ?? lastErrorMeta?.value ?? null,
        );
        setDataVersion((prev) => prev + 1);
    }, [db]);

    const syncNow = useCallback(
        async (forceRetry: boolean = false) => {
            const coordinator = coordinatorRef.current;
            if (!coordinator || !navigator.onLine) {
                return;
            }

            setIsSyncing(true);
            try {
                const outcome = await coordinator.syncNow(forceRetry);
                if (outcome.lease) {
                    setLease(outcome.lease);
                }
                if (outcome.sessionExpired) {
                    setSessionExpired(true);
                } else if (outcome.ok) {
                    setSessionExpired(false);
                    if (forceRetry && outcome.pushedCount > 0) {
                        toast.success(
                            `${outcome.pushedCount} perubahan berhasil disinkronkan.`,
                        );
                    }
                } else if (forceRetry && outcome.error) {
                    toast.error(`Sinkronisasi gagal: ${outcome.error}`);
                }
                await refreshOutboxStats();
            } finally {
                setIsSyncing(false);
            }
        },
        [refreshOutboxStats],
    );

    // Initialize per-user DB, enforce multi-account isolation (`D-22`), and boot initial sync.
    useEffect(() => {
        let cancelled = false;

        async function init() {
            if (!isIndexedDbAvailable()) {
                setIsReady(true);
                return;
            }

            if (authUser) {
                await enforceSingleActiveAccount(authUser.id);
                const savedLease = await saveAuthLease({
                    user_id: authUser.id,
                    name: authUser.name,
                    email: authUser.email,
                    timezone: authUser.timezone || 'Asia/Jakarta',
                    whatsapp_enabled: Boolean(authUser.whatsapp_enabled),
                    whatsapp_verified: authUser.whatsapp_verified_at !== null,
                    whatsapp_number_masked: authUser.whatsapp_number
                        ? `****${authUser.whatsapp_number.slice(-4)}`
                        : null,
                    quiet_hours: `${(authUser.reminder_quiet_start ?? '00:00').slice(0, 5)}–${(authUser.reminder_quiet_end ?? '05:00').slice(0, 5)}`,
                });
                if (!cancelled) {
                    setLease(savedLease);
                }
            } else if (!fallbackLease) {
                const existing = await getActiveAuthLease();
                if (!cancelled) {
                    setLease(existing);
                }
            }

            if (!cancelled) {
                setIsReady(true);
            }
        }

        void init();

        return () => {
            cancelled = true;
        };
    }, [authUser, fallbackLease]);

    // Instantiate SyncCoordinator and attach automatic triggers (boot, online, focus/visibility, SW Background Sync).
    useEffect(() => {
        if (!activeUserId || !isReady || !isIndexedDbAvailable()) {
            return;
        }

        const coordinator = new SyncCoordinator(activeUserId, () => {
            void refreshOutboxStats();
        });
        coordinatorRef.current = coordinator;

        void refreshOutboxStats().then(() => {
            if (navigator.onLine) {
                preloadOfflinePageModules();
                void syncNow();
            }
        });

        const handleOnline = () => {
            void syncNow();
        };

        const handleVisibility = () => {
            if (document.visibilityState === 'visible' && navigator.onLine) {
                void syncNow();
            }
        };

        window.addEventListener('online', handleOnline);
        document.addEventListener('visibilitychange', handleVisibility);
        const unsubscribeSw = onServiceWorkerSyncTrigger(() => {
            void syncNow();
        });

        return () => {
            coordinator.dispose();
            coordinatorRef.current = null;
            window.removeEventListener('online', handleOnline);
            document.removeEventListener('visibilitychange', handleVisibility);
            unsubscribeSw();
        };
    }, [activeUserId, isReady, refreshOutboxStats, syncNow]);

    // Clear offline client route once back online and a normal Inertia navigation occurs.
    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const removeBeforeListener = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (visit.method !== 'get') {
                return;
            }

            const pathname = visit.url.pathname;
            if (!navigator.onLine && isOfflineNavigablePath(pathname)) {
                event.preventDefault();
                const fullPath = `${visit.url.pathname}${visit.url.search}`;
                window.history.pushState({ offline: true }, '', fullPath);
                setOfflineRoute(fullPath);
                return;
            }

            if (navigator.onLine && offlineRoute !== null) {
                setOfflineRoute(null);
            }
        });

        const handlePopState = () => {
            if (!navigator.onLine) {
                const path = `${window.location.pathname}${window.location.search}`;
                if (isOfflineNavigablePath(window.location.pathname)) {
                    setOfflineRoute(path);
                }
            } else {
                setOfflineRoute(null);
            }
        };

        window.addEventListener('popstate', handlePopState);

        return () => {
            removeBeforeListener();
            window.removeEventListener('popstate', handlePopState);
        };
    }, [offlineRoute]);

    const notifyLocalMutation = useCallback(() => {
        void refreshOutboxStats();
        void requestBackgroundSync();

        if (typeof window === 'undefined' || !navigator.onLine) {
            return;
        }

        if (debounceTimerRef.current !== null) {
            window.clearTimeout(debounceTimerRef.current);
        }

        debounceTimerRef.current = window.setTimeout(() => {
            debounceTimerRef.current = null;
            void syncNow();
        }, 400);
    }, [refreshOutboxStats, syncNow]);

    const resolveConflict = useCallback(
        async (item: OutboxRecord, choice: 'keep_local' | 'use_server') => {
            const coordinator = coordinatorRef.current;
            if (!coordinator) {
                return;
            }
            await coordinator.resolveConflict(item, choice);
            await refreshOutboxStats();
            toast.success('Konflik sinkronisasi berhasil diselesaikan.');
        },
        [refreshOutboxStats],
    );

    const performFinalLogout = useCallback(async () => {
        if (activeUserId) {
            await deleteUserDb(activeUserId);
        }
        router.flushAll();
        router.post(logout.url());
    }, [activeUserId]);

    const requestLogout = useCallback(async () => {
        if (!db) {
            router.flushAll();
            router.post(logout.url());
            return;
        }

        const outboxCount = await db.outbox.count();
        if (outboxCount === 0) {
            await performFinalLogout();
            return;
        }

        setPendingCount(outboxCount);
        setLogoutDialogOpen(true);
    }, [db, performFinalLogout]);

    const navigateOffline = useCallback((href: string) => {
        if (typeof window === 'undefined') {
            return;
        }

        if (navigator.onLine) {
            setOfflineRoute(null);
            router.visit(href);
            return;
        }

        window.history.pushState({ offline: true }, '', href);
        setOfflineRoute(href);
    }, []);

    const isLeaseExpired =
        !isOnline && lease !== null && !isAuthLeaseValid(lease);

    const syncState: SyncIndicatorState = useMemo(() => {
        if (sessionExpired) {
            return 'session_expired';
        }
        if (conflicts.length > 0) {
            return 'conflict';
        }
        if (!isOnline) {
            return 'offline';
        }
        if (isSyncing) {
            return 'syncing';
        }
        if (failedCount > 0 || (lastError && pendingCount > 0)) {
            return 'error';
        }
        if (pendingCount > 0) {
            return 'pending';
        }
        return 'synced';
    }, [
        sessionExpired,
        conflicts.length,
        isOnline,
        isSyncing,
        failedCount,
        lastError,
        pendingCount,
    ]);

    const value: OfflineContextValue = {
        isOnline,
        isReady,
        hydratedFromServer,
        isLeaseExpired,
        sessionExpired,
        lease,
        db,
        repositories,
        syncState,
        pendingCount,
        conflictCount: conflicts.length,
        conflicts,
        lastError,
        lastSyncedAt,
        dataVersion,
        offlineRoute,
        syncNow,
        notifyLocalMutation,
        resolveConflict,
        openConflictDialog: () => setConflictDialogOpen(true),
        requestLogout,
        navigateOffline,
    };

    if (isLeaseExpired) {
        return (
            <OfflineContext.Provider value={value}>
                <OfflineLeaseExpiredScreen
                    pendingCount={pendingCount + conflicts.length}
                    isOnline={isOnline}
                />
            </OfflineContext.Provider>
        );
    }

    return (
        <OfflineContext.Provider value={value}>
            {children}

            <ConflictResolutionDialog
                open={conflictDialogOpen}
                onOpenChange={setConflictDialogOpen}
                conflicts={conflicts}
                onResolve={resolveConflict}
            />

            <LogoutOutboxDialog
                open={logoutDialogOpen}
                onOpenChange={setLogoutDialogOpen}
                pendingCount={pendingCount + conflicts.length}
                isOnline={isOnline}
                onSyncAndLogout={async () => {
                    await syncNow();
                    const remaining = db ? await db.outbox.count() : 0;
                    if (remaining === 0) {
                        setLogoutDialogOpen(false);
                        await performFinalLogout();
                    } else {
                        toast.error(
                            `Masih ada ${remaining} perubahan yang belum tersinkronisasi.`,
                        );
                    }
                }}
                onForceLogoutDiscard={async () => {
                    setLogoutDialogOpen(false);
                    await performFinalLogout();
                }}
            />
        </OfflineContext.Provider>
    );
}
