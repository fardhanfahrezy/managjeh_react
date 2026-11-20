import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CalendarPlus,
    CheckCircle2,
    Clock,
    ListPlus,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { index as calendarIndex } from '@/routes/calendar';
import { index as tasksIndex } from '@/routes/tasks';
import type { AiActionProposal } from '@/types/models';

type Props = {
    messageId: number;
    proposals: AiActionProposal[];
    submitting: boolean;
    onDecide: (
        messageId: number,
        approvedIds: number[],
        rejectAll: boolean,
    ) => Promise<void>;
};

const TOOL_LABELS: Record<string, string> = {
    create_task: 'Buat Tugas',
    update_task: 'Ubah Tugas',
    create_schedule: 'Buat Jadwal',
    update_schedule: 'Ubah Jadwal',
};

const PRIORITY_LABELS: Record<string, string> = {
    urgent: 'Mendesak',
    high: 'Tinggi',
    medium: 'Sedang',
    low: 'Rendah',
};

const STATUS_BADGE: Record<
    AiActionProposal['status'],
    { label: string; className: string }
> = {
    pending: {
        label: 'Menunggu persetujuan',
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

function describeProposal(proposal: AiActionProposal): {
    title: string;
    meta: string[];
} {
    const payload = proposal.payload;
    const title =
        (typeof payload.title === 'string' && payload.title) ||
        (typeof payload.current_title === 'string' && payload.current_title) ||
        proposal.result_summary ||
        TOOL_LABELS[proposal.tool_name] ||
        proposal.tool_name;

    const meta: string[] = [];

    if (typeof payload.due_at === 'string' && payload.due_at !== '') {
        meta.push(`Tenggat: ${payload.due_at}`);
    }
    if (
        typeof payload.starts_at === 'string' &&
        typeof payload.ends_at === 'string'
    ) {
        meta.push(`${payload.starts_at} – ${payload.ends_at}`);
    } else if (typeof payload.start_date === 'string') {
        meta.push(`Tanggal: ${payload.start_date}`);
    }
    if (typeof payload.priority === 'string') {
        meta.push(
            `Prioritas: ${PRIORITY_LABELS[payload.priority] ?? payload.priority}`,
        );
    }
    if (typeof payload.status === 'string') {
        meta.push(`Status: ${payload.status}`);
    }
    if (
        typeof payload.recurrence_rule === 'string' &&
        payload.recurrence_rule !== ''
    ) {
        meta.push(`Berulang: ${payload.recurrence_rule}`);
    }

    return { title, meta };
}

export function ActionPlanCard({
    messageId,
    proposals,
    submitting,
    onDecide,
}: Props) {
    // Non-conflicting pending items are selected by default; conflicting schedules require explicit opt-in.
    const [selectedIds, setSelectedIds] = useState<number[]>(() =>
        proposals
            .filter(
                (item) =>
                    item.status === 'pending' &&
                    !item.is_expired &&
                    item.conflict_preview.length === 0,
            )
            .map((item) => item.id),
    );

    const activePending = proposals.filter(
        (item) => item.status === 'pending' && !item.is_expired,
    );
    const hasActionable = activePending.length > 0;
    const validSelectedIds = selectedIds.filter((id) =>
        activePending.some((item) => item.id === id),
    );

    const toggleItem = (id: number, checked: boolean) => {
        setSelectedIds((prev) =>
            checked
                ? Array.from(new Set([...prev, id]))
                : prev.filter((existing) => existing !== id),
        );
    };

    return (
        <div className="mt-3 rounded-xl border border-border bg-card">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold text-foreground">
                        Usulan Rencana Tindakan
                    </span>
                    <Badge variant="outline" className="text-xs tabular-nums">
                        {proposals.length} aksi
                    </Badge>
                </div>
                {hasActionable && proposals[0]?.expires_at_local && (
                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground tabular-nums">
                        <Clock className="size-3.5" />
                        Berlaku hingga {proposals[0].expires_at_local}
                    </span>
                )}
            </div>

            <div className="divide-y divide-border">
                {proposals.map((proposal) => {
                    const isPending =
                        proposal.status === 'pending' && !proposal.is_expired;
                    const isChecked = validSelectedIds.includes(proposal.id);
                    const hasConflicts = proposal.conflict_preview.length > 0;
                    const isSchedule = proposal.tool_name.includes('schedule');
                    const { title, meta } = describeProposal(proposal);
                    const badge = STATUS_BADGE[proposal.status];

                    return (
                        <div
                            key={proposal.id}
                            className={cn(
                                'flex items-start gap-3 px-4 py-3 transition-colors',
                                isPending &&
                                    hasConflicts &&
                                    'bg-amber-50/40 dark:bg-amber-950/20',
                            )}
                        >
                            {isPending ? (
                                <Checkbox
                                    id={`proposal-${proposal.id}`}
                                    checked={isChecked}
                                    disabled={submitting}
                                    onCheckedChange={(checked) =>
                                        toggleItem(
                                            proposal.id,
                                            checked === true,
                                        )
                                    }
                                    className="mt-0.5"
                                    aria-label={`Pilih usulan ${title}`}
                                />
                            ) : (
                                <div className="mt-0.5 shrink-0">
                                    {proposal.status === 'executed' ? (
                                        <CheckCircle2 className="size-4 text-primary" />
                                    ) : (
                                        <XCircle className="size-4 text-muted-foreground" />
                                    )}
                                </div>
                            )}

                            <div className="min-w-0 flex-1 space-y-1.5">
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
                                        {TOOL_LABELS[proposal.tool_name] ??
                                            proposal.tool_name}
                                    </Badge>

                                    {!isPending && (
                                        <Badge
                                            variant="outline"
                                            className={cn(
                                                'text-[11px]',
                                                badge.className,
                                            )}
                                        >
                                            {badge.label}
                                        </Badge>
                                    )}
                                </div>

                                <label
                                    htmlFor={
                                        isPending
                                            ? `proposal-${proposal.id}`
                                            : undefined
                                    }
                                    className={cn(
                                        'block text-sm font-medium text-foreground',
                                        isPending && 'cursor-pointer',
                                    )}
                                >
                                    {title}
                                </label>

                                {meta.length > 0 && (
                                    <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground tabular-nums">
                                        {meta.map((entry, idx) => (
                                            <span key={idx}>{entry}</span>
                                        ))}
                                    </div>
                                )}

                                {hasConflicts && (
                                    <div
                                        role="note"
                                        className="mt-1.5 rounded-md border border-amber-300/80 bg-amber-50/80 px-2.5 py-2 text-xs text-amber-900 dark:border-amber-800/70 dark:bg-amber-950/40 dark:text-amber-200"
                                    >
                                        <div className="flex items-center gap-1.5 font-medium">
                                            <AlertTriangle className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                                            <span>
                                                Bentrok dengan jadwal eksisting
                                                {isPending
                                                    ? ' — centang manual jika tetap ingin disimpan'
                                                    : ''}
                                            </span>
                                        </div>
                                        <ul className="mt-1 space-y-0.5 pl-5 tabular-nums">
                                            {proposal.conflict_preview.map(
                                                (conflict) => (
                                                    <li
                                                        key={`${conflict.id}-${conflict.starts_at_local ?? ''}`}
                                                    >
                                                        {conflict.title}
                                                        {conflict.starts_at_local &&
                                                            conflict.ends_at_local &&
                                                            ` (${conflict.starts_at_local.slice(11, 16)}–${conflict.ends_at_local.slice(11, 16)})`}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                )}

                                {!isPending && proposal.result_summary && (
                                    <p className="text-xs text-muted-foreground">
                                        {proposal.result_summary}
                                    </p>
                                )}

                                {proposal.status === 'executed' && (
                                    <div className="pt-0.5">
                                        <Link
                                            href={
                                                proposal.target_type ===
                                                'calendar_event'
                                                    ? calendarIndex()
                                                    : tasksIndex()
                                            }
                                            className="inline-flex items-center gap-1 text-xs font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            <span>
                                                {proposal.target_type ===
                                                'calendar_event'
                                                    ? 'Lihat di Kalender'
                                                    : 'Lihat di Tugas'}
                                            </span>
                                            <ArrowUpRight className="size-3.5" />
                                        </Link>
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            {hasActionable && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border bg-muted/30 px-4 py-3">
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {validSelectedIds.length} dari {activePending.length}{' '}
                        usulan dipilih
                    </span>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={submitting}
                            onClick={() => void onDecide(messageId, [], true)}
                        >
                            Tolak Semua
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            disabled={
                                submitting || validSelectedIds.length === 0
                            }
                            onClick={() =>
                                void onDecide(
                                    messageId,
                                    validSelectedIds,
                                    false,
                                )
                            }
                        >
                            {submitting
                                ? 'Menerapkan...'
                                : `Setujui Terpilih (${validSelectedIds.length})`}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
