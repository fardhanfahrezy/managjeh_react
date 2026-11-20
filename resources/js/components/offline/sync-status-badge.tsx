import {
    AlertTriangle,
    CheckCircle2,
    CloudOff,
    CloudUpload,
    Lock,
    RefreshCw,
    WifiOff,
} from 'lucide-react';
import { useNetworkStatus } from '@/hooks/use-network-status';
import { cn } from '@/lib/utils';

export type SyncIndicatorState =
    | 'offline'
    | 'pending'
    | 'syncing'
    | 'synced'
    | 'conflict'
    | 'error'
    | 'session_expired';

export interface SyncStatusBadgeProps {
    state?: SyncIndicatorState;
    pendingCount?: number;
    conflictCount?: number;
    lastError?: string | null;
    onClick?: () => void;
    className?: string;
}

export function SyncStatusBadge({
    state,
    pendingCount = 0,
    conflictCount = 0,
    lastError = null,
    onClick,
    className,
}: SyncStatusBadgeProps) {
    const { isOnline } = useNetworkStatus();
    const resolvedState: SyncIndicatorState =
        state ?? (isOnline ? 'synced' : 'offline');

    const config = (() => {
        switch (resolvedState) {
            case 'offline':
                return {
                    icon: WifiOff,
                    label:
                        pendingCount > 0
                            ? `Offline · ${pendingCount} tersimpan lokal`
                            : 'Offline · Tersimpan di perangkat',
                    shortLabel:
                        pendingCount > 0
                            ? `Offline (${pendingCount})`
                            : 'Offline',
                    tone: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200',
                    spin: false,
                };
            case 'pending':
                return {
                    icon: CloudUpload,
                    label: `Menunggu sinkronisasi (${pendingCount})`,
                    shortLabel: `Tertunda (${pendingCount})`,
                    tone: 'border-amber-200 bg-amber-50/80 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200',
                    spin: false,
                };
            case 'syncing':
                return {
                    icon: RefreshCw,
                    label: 'Menyinkronkan...',
                    shortLabel: 'Sinkronisasi...',
                    tone: 'border-emerald-200 bg-emerald-50/70 text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200',
                    spin: true,
                };
            case 'conflict':
                return {
                    icon: AlertTriangle,
                    label: `${conflictCount} konflik perlu diselesaikan`,
                    shortLabel: `${conflictCount} konflik`,
                    tone: 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100/80 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200',
                    spin: false,
                };
            case 'error':
                return {
                    icon: CloudOff,
                    label: 'Sinkronisasi gagal',
                    shortLabel: 'Gagal sync',
                    tone: 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100/80 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200',
                    spin: false,
                };
            case 'session_expired':
                return {
                    icon: Lock,
                    label: 'Sesi berakhir · Login ulang',
                    shortLabel: 'Login ulang',
                    tone: 'border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100/80 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200',
                    spin: false,
                };
            case 'synced':
            default:
                return {
                    icon: CheckCircle2,
                    label: 'Tersinkronisasi',
                    shortLabel: 'Tersinkron',
                    tone: 'border-stone-200 bg-white text-stone-600 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-300',
                    spin: false,
                };
        }
    })();

    const Icon = config.icon;
    const isInteractive = Boolean(onClick);
    const titleText = lastError
        ? `${config.label}: ${lastError}`
        : config.label;

    const sharedClasses = cn(
        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium tabular-nums transition-colors',
        config.tone,
        isInteractive &&
            'cursor-pointer focus-visible:ring-2 focus-visible:ring-emerald-600/40 focus-visible:outline-none',
        className,
    );

    if (isInteractive) {
        return (
            <button
                type="button"
                onClick={onClick}
                title={titleText}
                aria-label={titleText}
                aria-live="polite"
                data-sync-state={resolvedState}
                className={sharedClasses}
            >
                <Icon
                    className={cn(
                        'size-3.5 shrink-0',
                        config.spin && 'animate-spin',
                    )}
                    aria-hidden="true"
                />
                <span className="hidden sm:inline">{config.label}</span>
                <span className="sm:hidden" aria-hidden="true">
                    {config.shortLabel}
                </span>
            </button>
        );
    }

    return (
        <span
            role="status"
            aria-live="polite"
            aria-label={config.label}
            title={titleText}
            data-sync-state={resolvedState}
            className={sharedClasses}
        >
            <Icon
                className={cn(
                    'size-3.5 shrink-0',
                    config.spin && 'animate-spin',
                )}
                aria-hidden="true"
            />
            <span className="hidden sm:inline">{config.label}</span>
            <span className="sm:hidden" aria-hidden="true">
                {config.shortLabel}
            </span>
        </span>
    );
}
