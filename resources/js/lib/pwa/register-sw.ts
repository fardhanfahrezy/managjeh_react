import { toast } from 'sonner';

interface BackgroundSyncManager {
    register: (tag: string) => Promise<void>;
}

interface SyncCapableRegistration extends ServiceWorkerRegistration {
    sync?: BackgroundSyncManager;
}

const OUTBOX_SYNC_TAG = 'managjeh-outbox-sync';

let registered = false;

export function registerServiceWorker(): void {
    if (
        registered ||
        typeof window === 'undefined' ||
        !('serviceWorker' in navigator)
    ) {
        return;
    }

    registered = true;

    let refreshing = false;
    let userApprovedUpdate = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!userApprovedUpdate || refreshing) {
            return;
        }
        refreshing = true;
        window.location.reload();
    });

    const promptUpdate = (worker: ServiceWorker) => {
        toast('Pembaruan aplikasi tersedia', {
            description:
                'Data lokal Anda tetap aman. Muat ulang untuk menggunakan versi terbaru.',
            duration: Infinity,
            action: {
                label: 'Muat Ulang',
                onClick: () => {
                    userApprovedUpdate = true;
                    worker.postMessage({ type: 'SKIP_WAITING' });
                },
            },
        });
    };

    const doRegister = () => {
        void navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .then((registration) => {
                if (
                    registration.waiting &&
                    navigator.serviceWorker.controller
                ) {
                    promptUpdate(registration.waiting);
                }

                registration.addEventListener('updatefound', () => {
                    const installing = registration.installing;
                    if (!installing) {
                        return;
                    }

                    installing.addEventListener('statechange', () => {
                        if (
                            installing.state === 'installed' &&
                            navigator.serviceWorker.controller
                        ) {
                            promptUpdate(installing);
                        }
                    });
                });

                const activeWorker = registration.active;
                if (activeWorker && navigator.onLine) {
                    activeWorker.postMessage({ type: 'WARM_OFFLINE_SHELL' });
                }
            })
            .catch(() => {
                // Service worker registration may fail on unsupported contexts; app still works normally.
            });
    };

    if (document.readyState === 'complete') {
        doRegister();
    } else {
        window.addEventListener('load', doRegister, { once: true });
    }
}

export async function requestBackgroundSync(): Promise<boolean> {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return false;
    }

    try {
        const registration = (await navigator.serviceWorker
            .ready) as SyncCapableRegistration;
        if (registration.sync) {
            await registration.sync.register(OUTBOX_SYNC_TAG);
            return true;
        }
    } catch {
        // Background Sync API is optional; online/focus triggers act as primary fallback.
    }

    return false;
}

export function onServiceWorkerSyncTrigger(callback: () => void): () => void {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return () => {};
    }

    const listener = (event: MessageEvent<unknown>) => {
        const data = event.data as { type?: string } | null;
        if (data?.type === 'TRIGGER_OUTBOX_SYNC') {
            callback();
        }
    };

    navigator.serviceWorker.addEventListener('message', listener);

    return () => {
        navigator.serviceWorker.removeEventListener('message', listener);
    };
}
