import { Head } from '@inertiajs/react';
import { RefreshCw, WifiOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { OfflineProvider } from '@/components/offline/offline-provider';
import { OfflineRouteRenderer } from '@/components/offline/offline-route-renderer';
import { Button } from '@/components/ui/button';
import { useNetworkStatus } from '@/hooks/use-network-status';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { getActiveAuthLease } from '@/lib/offline/db';
import type { AuthLeaseRecord } from '@/lib/offline/types';

export default function OfflineShell() {
    const { isOnline } = useNetworkStatus();
    const [loading, setLoading] = useState(true);
    const [lease, setLease] = useState<AuthLeaseRecord | null>(null);
    const [currentPath, setCurrentPath] = useState('/dashboard');

    useEffect(() => {
        if (typeof window !== 'undefined') {
            const path =
                window.location.pathname === '/offline-shell'
                    ? '/dashboard'
                    : `${window.location.pathname}${window.location.search}`;
            setCurrentPath(path);
        }

        void getActiveAuthLease()
            .then((found) => {
                setLease(found);
            })
            .finally(() => {
                setLoading(false);
            });
    }, []);

    if (loading) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-[#fafaf9] p-6 text-sm text-stone-600 dark:bg-stone-950 dark:text-stone-400">
                <Head title="Memuat Mode Offline..." />
                Menyiapkan ruang kerja lokal Managjeh...
            </div>
        );
    }

    if (lease) {
        return (
            <OfflineProvider fallbackLease={lease}>
                <AppSidebarLayout>
                    <OfflineRouteRenderer path={currentPath} />
                </AppSidebarLayout>
            </OfflineProvider>
        );
    }

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-[#fafaf9] p-6 text-stone-900 dark:bg-stone-950 dark:text-stone-100">
            <Head title="Mode Offline" />

            <div className="w-full max-w-md rounded-xl border border-stone-200 bg-white p-6 shadow-xs dark:border-stone-800 dark:bg-stone-900">
                <div className="mb-4 flex size-11 items-center justify-center rounded-lg border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300">
                    <WifiOff className="size-5" aria-hidden="true" />
                </div>

                <h1 className="text-lg font-semibold tracking-tight">
                    {isOnline
                        ? 'Koneksi kembali tersedia'
                        : 'Masuk Saat Online Terlebih Dahulu'}
                </h1>

                <p className="mt-2 text-sm leading-relaxed text-stone-600 dark:text-stone-400">
                    {isOnline
                        ? 'Perangkat Anda sudah terhubung ke internet. Muat ulang atau masuk untuk mulai menggunakan Managjeh.'
                        : 'Belum ada sesi offline aktif di perangkat ini. Hubungkan ke internet dan login sekali agar data Dashboard, Tasks, Projects, dan Calendar tersedia saat offline.'}
                </p>

                <div className="mt-6 flex items-center gap-3">
                    <Button
                        type="button"
                        onClick={() => {
                            window.location.href = isOnline
                                ? '/login'
                                : window.location.href;
                        }}
                    >
                        <RefreshCw className="mr-2 size-4" aria-hidden="true" />
                        {isOnline ? 'Buka Halaman Login' : 'Muat Ulang'}
                    </Button>
                </div>
            </div>
        </div>
    );
}
