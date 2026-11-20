import { Lock, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface OfflineLeaseExpiredScreenProps {
    pendingCount: number;
    isOnline: boolean;
}

export function OfflineLeaseExpiredScreen({
    pendingCount,
    isOnline,
}: OfflineLeaseExpiredScreenProps) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-[#fafaf9] p-6 text-stone-900 dark:bg-stone-950 dark:text-stone-100">
            <div className="w-full max-w-md rounded-xl border border-stone-200 bg-white p-6 shadow-xs dark:border-stone-800 dark:bg-stone-900">
                <div className="mb-4 flex size-11 items-center justify-center rounded-lg border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300">
                    <Lock className="size-5" aria-hidden="true" />
                </div>

                <h1 className="text-lg font-semibold tracking-tight">
                    Akses Offline Perlu Verifikasi Ulang
                </h1>

                <p className="mt-2 text-sm leading-relaxed text-stone-600 dark:text-stone-400">
                    Masa berlaku sesi offline (7 hari) telah habis. Hubungkan
                    perangkat ke internet dan masuk kembali untuk melanjutkan.
                </p>

                {pendingCount > 0 && (
                    <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-3.5 py-2.5 text-xs text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-200">
                        <strong className="tabular-nums">{pendingCount}</strong>{' '}
                        perubahan offline Anda tetap tersimpan aman di perangkat
                        ini dan akan otomatis disinkronkan setelah Anda login
                        kembali dengan akun yang sama.
                    </div>
                )}

                <div className="mt-6 flex items-center gap-3">
                    <Button
                        type="button"
                        disabled={!isOnline}
                        onClick={() => {
                            window.location.href = '/login';
                        }}
                    >
                        <RefreshCw className="mr-2 size-4" aria-hidden="true" />
                        {isOnline
                            ? 'Masuk Kembali Sekarang'
                            : 'Menunggu Koneksi Internet...'}
                    </Button>
                </div>
            </div>
        </div>
    );
}
