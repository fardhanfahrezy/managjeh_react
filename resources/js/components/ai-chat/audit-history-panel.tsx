import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarPlus,
    CheckCircle2,
    Clock,
    History,
    ListPlus,
    XCircle,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { index as calendarIndex } from '@/routes/calendar';
import { index as tasksIndex } from '@/routes/tasks';
import type { AiActionProposal } from '@/types/models';

const TOOL_LABELS: Record<string, string> = {
    create_task: 'Buat Tugas',
    update_task: 'Ubah Tugas',
    create_schedule: 'Buat Jadwal',
    update_schedule: 'Ubah Jadwal',
};

const STATUS_META: Record<
    AiActionProposal['status'],
    { label: string; className: string }
> = {
    pending: {
        label: 'Menunggu',
        className: 'border-border bg-secondary text-foreground',
    },
    executed: {
        label: 'Dieksekusi',
        className:
            'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/50 dark:text-emerald-300',
    },
    rejected: {
        label: 'Ditolak',
        className: 'border-border bg-muted text-muted-foreground',
    },
    expired: {
        label: 'Kedaluwarsa',
        className:
            'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/50 dark:text-amber-300',
    },
    failed: {
        label: 'Gagal',
        className:
            'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/50 dark:text-red-300',
    },
};

export function AuditHistoryPanel({ audits }: { audits: AiActionProposal[] }) {
    if (audits.length === 0) {
        return (
            <div className="mx-auto flex max-w-md flex-col items-center justify-center py-16 text-center">
                <div className="mb-3 flex size-10 items-center justify-center rounded-lg border border-border bg-card">
                    <History className="size-5 text-muted-foreground" />
                </div>
                <h3 className="text-sm font-semibold text-foreground">
                    Belum ada riwayat aksi AI
                </h3>
                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                    Setiap usulan pembuatan atau perubahan tugas dan jadwal yang
                    Anda setujui, tolak, atau kedaluwarsa akan tercatat di sini.
                </p>
            </div>
        );
    }

    return (
        <div className="mx-auto w-full max-w-3xl space-y-3">
            <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">
                    Menampilkan {audits.length} aktivitas usulan aksi AI terbaru
                    pada akun Anda.
                </p>
            </div>

            <div className="divide-y divide-border rounded-xl border border-border bg-card">
                {audits.map((audit) => {
                    const isSchedule = audit.tool_name.includes('schedule');
                    const badge = STATUS_META[audit.status];
                    const title =
                        (typeof audit.payload.title === 'string' &&
                            audit.payload.title) ||
                        (typeof audit.payload.current_title === 'string' &&
                            audit.payload.current_title) ||
                        audit.result_summary ||
                        TOOL_LABELS[audit.tool_name] ||
                        audit.tool_name;

                    return (
                        <div
                            key={audit.id}
                            className="flex items-start gap-3 px-4 py-3.5"
                        >
                            <div className="mt-0.5 shrink-0">
                                {audit.status === 'executed' ? (
                                    <CheckCircle2 className="size-4 text-primary" />
                                ) : audit.status === 'pending' ? (
                                    <Clock className="size-4 text-muted-foreground" />
                                ) : (
                                    <XCircle className="size-4 text-muted-foreground" />
                                )}
                            </div>

                            <div className="min-w-0 flex-1 space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        variant="secondary"
                                        className="gap-1 text-[11px] font-medium"
                                    >
                                        {isSchedule ? (
                                            <CalendarPlus className="size-3" />
                                        ) : (
                                            <ListPlus className="size-3" />
                                        )}
                                        {TOOL_LABELS[audit.tool_name] ??
                                            audit.tool_name}
                                    </Badge>
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'text-[11px]',
                                            badge.className,
                                        )}
                                    >
                                        {badge.label}
                                    </Badge>
                                    <span className="ml-auto text-[11px] text-muted-foreground tabular-nums">
                                        {audit.executed_at_local ??
                                            `Berlaku s.d. ${audit.expires_at_local}`}
                                    </span>
                                </div>

                                <div className="text-sm font-medium text-foreground">
                                    {title}
                                </div>

                                {audit.result_summary && (
                                    <p className="text-xs text-muted-foreground">
                                        {audit.result_summary}
                                    </p>
                                )}

                                {audit.status === 'executed' && (
                                    <Link
                                        href={
                                            audit.target_type ===
                                            'calendar_event'
                                                ? calendarIndex()
                                                : tasksIndex()
                                        }
                                        className="inline-flex items-center gap-1 pt-0.5 text-xs font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        <span>
                                            {audit.target_type ===
                                            'calendar_event'
                                                ? 'Lihat di Kalender'
                                                : 'Lihat di Tugas'}
                                        </span>
                                        <ArrowUpRight className="size-3.5" />
                                    </Link>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
