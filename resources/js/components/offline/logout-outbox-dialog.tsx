import { AlertTriangle, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface LogoutOutboxDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    pendingCount: number;
    isOnline: boolean;
    onSyncAndLogout: () => Promise<void>;
    onForceLogoutDiscard: () => Promise<void>;
}

export function LogoutOutboxDialog({
    open,
    onOpenChange,
    pendingCount,
    isOnline,
    onSyncAndLogout,
    onForceLogoutDiscard,
}: LogoutOutboxDialogProps) {
    const [busy, setBusy] = useState<'sync' | 'discard' | null>(null);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <AlertTriangle
                            className="size-5 text-amber-600"
                            aria-hidden="true"
                        />
                        Perubahan Lokal Belum Tersinkronisasi
                    </DialogTitle>
                    <DialogDescription>
                        Masih ada{' '}
                        <span className="font-semibold text-foreground tabular-nums">
                            {pendingCount}
                        </span>{' '}
                        perubahan offline yang belum terkirim ke server. Demi
                        keamanan akun, keluar dari aplikasi akan menghapus data
                        lokal di perangkat ini.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="flex-col gap-2 sm:flex-col">
                    <Button
                        type="button"
                        disabled={!isOnline || busy !== null}
                        onClick={async () => {
                            setBusy('sync');
                            try {
                                await onSyncAndLogout();
                            } finally {
                                setBusy(null);
                            }
                        }}
                        className="w-full"
                    >
                        <RefreshCw
                            className={`mr-2 size-4 ${busy === 'sync' ? 'animate-spin' : ''}`}
                            aria-hidden="true"
                        />
                        {isOnline
                            ? 'Coba Sinkronkan Dulu & Keluar'
                            : 'Menunggu Koneksi Internet...'}
                    </Button>

                    <Button
                        type="button"
                        variant="destructive"
                        disabled={busy !== null}
                        onClick={async () => {
                            setBusy('discard');
                            try {
                                await onForceLogoutDiscard();
                            } finally {
                                setBusy(null);
                            }
                        }}
                        className="w-full"
                    >
                        <Trash2 className="mr-2 size-4" aria-hidden="true" />
                        Keluar &amp; Hapus Perubahan Lokal
                    </Button>

                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy !== null}
                        onClick={() => onOpenChange(false)}
                        className="w-full"
                    >
                        Batal
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
