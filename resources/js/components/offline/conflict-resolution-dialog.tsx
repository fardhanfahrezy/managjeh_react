import { AlertTriangle, Check, Server } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { OutboxRecord } from '@/lib/offline/types';

const ENTITY_LABELS: Record<string, string> = {
    project: 'Proyek',
    task: 'Tugas',
    calendar_event: 'Agenda Kalender',
};

const FIELD_LABELS: Record<string, string> = {
    name: 'Nama',
    title: 'Judul',
    description: 'Deskripsi',
    status: 'Status',
    priority: 'Prioritas',
    due_at: 'Tenggat Waktu',
    due_date: 'Target Selesai',
    start_date: 'Tanggal Mulai',
    end_date: 'Tanggal Selesai',
    starts_at: 'Mulai',
    ends_at: 'Selesai',
    deleted_at: 'Penghapusan',
};

function formatFieldValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (typeof value === 'boolean') {
        return value ? 'Ya' : 'Tidak';
    }
    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }
    if (Array.isArray(value)) {
        return value.length === 0 ? '—' : value.join(', ');
    }
    return JSON.stringify(value);
}

interface ConflictResolutionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    conflicts: OutboxRecord[];
    onResolve: (
        item: OutboxRecord,
        choice: 'keep_local' | 'use_server',
    ) => Promise<void>;
}

export function ConflictResolutionDialog({
    open,
    onOpenChange,
    conflicts,
    onResolve,
}: ConflictResolutionDialogProps) {
    const [busyId, setBusyId] = useState<string | null>(null);
    const [errorMsg, setErrorMsg] = useState<string | null>(null);

    const handleChoice = async (
        item: OutboxRecord,
        choice: 'keep_local' | 'use_server',
    ) => {
        setBusyId(item.operation_id);
        setErrorMsg(null);
        try {
            await onResolve(item, choice);
        } catch (err) {
            setErrorMsg(
                err instanceof Error
                    ? err.message
                    : 'Gagal menyelesaikan konflik.',
            );
        } finally {
            setBusyId(null);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <AlertTriangle
                            className="size-5 text-red-600"
                            aria-hidden="true"
                        />
                        Selesaikan Konflik Sinkronisasi ({conflicts.length})
                    </DialogTitle>
                    <DialogDescription>
                        Data di bawah ini berubah di perangkat lain sebelum
                        perubahan offline Anda sempat dikirim. Pilih versi yang
                        ingin dipertahankan.
                    </DialogDescription>
                </DialogHeader>

                {errorMsg && (
                    <div
                        role="alert"
                        className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200"
                    >
                        {errorMsg}
                    </div>
                )}

                {conflicts.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        Semua konflik telah terselesaikan.
                    </p>
                ) : (
                    <div className="space-y-4">
                        {conflicts.map((item) => {
                            const entityLabel =
                                ENTITY_LABELS[item.entity_type] ??
                                item.entity_type;
                            const isBusy = busyId === item.operation_id;
                            const rawTitle =
                                item.payload.title ??
                                item.payload.name ??
                                item.server_conflict_record?.title ??
                                item.server_conflict_record?.name;
                            const title =
                                typeof rawTitle === 'string' && rawTitle !== ''
                                    ? rawTitle
                                    : item.entity_uuid.slice(0, 8);

                            const fields =
                                item.changed_fields.length > 0
                                    ? item.changed_fields
                                    : Object.keys(item.payload);

                            return (
                                <div
                                    key={item.operation_id}
                                    className="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <Badge
                                                variant="outline"
                                                className="mb-1 text-[11px]"
                                            >
                                                {entityLabel}
                                            </Badge>
                                            <h3 className="text-sm font-semibold text-foreground">
                                                {title}
                                            </h3>
                                        </div>
                                        <span className="text-xs text-red-600 dark:text-red-400">
                                            {item.conflict_reason ===
                                            'deleted_on_server'
                                                ? 'Dihapus di server'
                                                : item.conflict_reason ===
                                                    'modified_on_server'
                                                  ? 'Diubah di server sebelum dihapus'
                                                  : 'Bentrok perubahan field'}
                                        </span>
                                    </div>

                                    <div className="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                                        <div className="rounded-lg border border-emerald-200/80 bg-emerald-50/40 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                                            <p className="mb-1.5 text-[11px] font-semibold tracking-wide text-emerald-800 uppercase dark:text-emerald-300">
                                                Versi Perangkat Ini
                                            </p>
                                            {item.action === 'delete' ? (
                                                <p className="text-xs text-red-700 dark:text-red-300">
                                                    Menghapus data ini
                                                </p>
                                            ) : (
                                                <dl className="space-y-1 text-xs">
                                                    {fields.map((field) => (
                                                        <div key={field}>
                                                            <dt className="text-muted-foreground">
                                                                {FIELD_LABELS[
                                                                    field
                                                                ] ?? field}
                                                            </dt>
                                                            <dd className="font-medium text-foreground">
                                                                {formatFieldValue(
                                                                    item
                                                                        .payload[
                                                                        field
                                                                    ],
                                                                )}
                                                            </dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                            )}
                                        </div>

                                        <div className="rounded-lg border border-stone-200 bg-stone-50/70 p-3 dark:border-stone-800 dark:bg-stone-950/40">
                                            <p className="mb-1.5 text-[11px] font-semibold tracking-wide text-stone-700 uppercase dark:text-stone-300">
                                                Versi Server
                                            </p>
                                            {!item.server_conflict_record ||
                                            item.server_conflict_record
                                                .deleted_at ? (
                                                <p className="text-xs text-muted-foreground">
                                                    Data ini sudah dihapus di
                                                    server.
                                                </p>
                                            ) : (
                                                <dl className="space-y-1 text-xs">
                                                    {fields.map((field) => (
                                                        <div key={field}>
                                                            <dt className="text-muted-foreground">
                                                                {FIELD_LABELS[
                                                                    field
                                                                ] ?? field}
                                                            </dt>
                                                            <dd className="font-medium text-foreground">
                                                                {formatFieldValue(
                                                                    item
                                                                        .server_conflict_record?.[
                                                                        field
                                                                    ],
                                                                )}
                                                            </dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                            )}
                                        </div>
                                    </div>

                                    <div className="mt-3 flex flex-wrap justify-end gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={isBusy}
                                            onClick={() =>
                                                void handleChoice(
                                                    item,
                                                    'use_server',
                                                )
                                            }
                                        >
                                            <Server
                                                className="mr-1.5 size-3.5"
                                                aria-hidden="true"
                                            />
                                            Gunakan Versi Server
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            disabled={isBusy}
                                            onClick={() =>
                                                void handleChoice(
                                                    item,
                                                    'keep_local',
                                                )
                                            }
                                        >
                                            <Check
                                                className="mr-1.5 size-3.5"
                                                aria-hidden="true"
                                            />
                                            Pertahankan Milik Saya
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
