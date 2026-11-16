import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BellPlus,
    Check,
    Clock,
    RefreshCw,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import AiPlannerController from '@/actions/App/Http/Controllers/AiPlannerController';
import { formatDateDisplay } from '@/components/tasks/task-constants';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useRateLimitCooldown } from '@/hooks/use-rate-limit-cooldown';
import { index as aiPlannerIndex } from '@/routes/ai-planner';
import { index as tasksIndex } from '@/routes/tasks';
import type {
    AiPlannerRecommendedReminder,
    AiPlannerRun,
} from '@/types/models';

type Props = {
    planning_date: string;
    timezone: string;
    latest_run: AiPlannerRun | null;
    recent_runs: AiPlannerRun[];
};

const PROVIDER_LABELS: Record<string, string> = {
    gemini: 'Gemini',
    grok: 'Grok',
    openrouter: 'OpenRouter',
    fallback: 'Fallback Deterministik',
};

export default function AiPlannerIndex({
    planning_date,
    timezone,
    latest_run,
    recent_runs,
}: Props) {
    const [generating, setGenerating] = useState(false);
    const [approvingKey, setApprovingKey] = useState<string | null>(null);
    const [approvedKeys, setApprovedKeys] = useState<Record<string, boolean>>(
        {},
    );
    const generateCooldown = useRateLimitCooldown();

    const handleGenerate = () => {
        if (generateCooldown.isCoolingDown) {
            return;
        }

        setGenerating(true);
        router.post(
            AiPlannerController.generate.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => generateCooldown.clearRateLimit(),
                onHttpException: generateCooldown.handleHttpException,
                onFinish: () => setGenerating(false),
            },
        );
    };

    const handleApproveReminder = (item: AiPlannerRecommendedReminder) => {
        const key = `${item.task_id}-${item.remind_at_local}`;
        setApprovingKey(key);

        router.post(
            AiPlannerController.approveReminder.url(),
            {
                task_id: item.task_id,
                remind_at_local: item.remind_at_local,
                reason: item.reason,
            },
            {
                preserveScroll: true,
                onSuccess: () =>
                    setApprovedKeys((prev) => ({
                        ...prev,
                        [key]: true,
                    })),
                onFinish: () => setApprovingKey(null),
            },
        );
    };

    const result = latest_run?.result ?? null;
    const priorities = result?.priorities ?? [];
    const recommendedReminders = result?.recommended_reminders ?? [];
    const warnings = result?.warnings ?? [];

    return (
        <>
            <Head title="AI Planner" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <Sparkles className="size-5 text-primary" />
                            <h1 className="text-xl font-semibold tracking-tight text-foreground">
                                AI Planner Harian
                            </h1>
                            {latest_run && (
                                <Badge
                                    variant="outline"
                                    className="text-[11px]"
                                >
                                    {PROVIDER_LABELS[latest_run.provider] ??
                                        latest_run.provider}
                                </Badge>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground tabular-nums">
                            Tanggal perencanaan:{' '}
                            {formatDateDisplay(planning_date)} ({timezone})
                        </p>
                    </div>

                    <div className="flex flex-col items-start gap-1.5 sm:items-end">
                        <Button
                            type="button"
                            disabled={
                                generating || generateCooldown.isCoolingDown
                            }
                            onClick={handleGenerate}
                        >
                            <RefreshCw
                                className={`size-4 ${generating ? 'animate-spin' : ''}`}
                            />
                            {generating
                                ? 'Menyusun Rencana...'
                                : generateCooldown.isCoolingDown
                                  ? `Tunggu ${generateCooldown.cooldownSeconds} dtk`
                                  : latest_run
                                    ? 'Perbarui Rencana Hari Ini'
                                    : 'Generate Rencana Hari Ini'}
                        </Button>
                        {generateCooldown.rateLimitMessage && (
                            <p
                                role="status"
                                aria-live="polite"
                                className="text-xs font-medium text-amber-700 tabular-nums dark:text-amber-300"
                            >
                                {generateCooldown.rateLimitMessage}
                            </p>
                        )}
                    </div>
                </div>

                {/* Warnings Banner */}
                {warnings.length > 0 && (
                    <div className="space-y-2 rounded-xl border border-amber-300 bg-amber-50/70 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                        <div className="flex items-center gap-2 font-medium">
                            <AlertTriangle className="size-4 text-amber-600" />
                            <span>Peringatan Jadwal & Beban Tugas</span>
                        </div>
                        <ul className="list-disc space-y-1 pl-5 text-xs text-amber-900 dark:text-amber-200">
                            {warnings.map((warning, idx) => (
                                <li key={`${warning.type}-${idx}`}>
                                    {warning.message}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {!latest_run ? (
                    <div className="rounded-xl border border-dashed border-border bg-card p-10 text-center">
                        <Sparkles className="mx-auto size-8 text-primary/70" />
                        <h2 className="mt-3 text-base font-semibold text-foreground">
                            Belum ada rencana AI untuk hari ini
                        </h2>
                        <p className="mx-auto mt-1 max-w-md text-xs text-muted-foreground">
                            AI Planner akan menganalisis tugas aktif, deadline,
                            dan slot kosong di kalender Anda untuk menyarankan 3
                            prioritas utama tanpa mengubah data Anda secara
                            otomatis.
                        </p>
                        <Button
                            type="button"
                            className="mt-4"
                            disabled={generating}
                            onClick={handleGenerate}
                        >
                            <Sparkles className="size-4" />
                            Generate Rencana Sekarang
                        </Button>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-12">
                        {/* Left: Summary & Top 3 Priorities */}
                        <div className="space-y-5 lg:col-span-7">
                            <section className="rounded-xl border border-border bg-card p-5">
                                <h2 className="text-base font-semibold text-foreground">
                                    Ringkasan Hari Ini
                                </h2>
                                <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                                    {result?.summary ??
                                        'Rencana berhasil disusun.'}
                                </p>
                            </section>

                            <section className="rounded-xl border border-border bg-card p-5">
                                <div className="mb-4 flex items-center justify-between">
                                    <h2 className="text-base font-semibold text-foreground">
                                        3 Prioritas Utama
                                    </h2>
                                    <Link
                                        href={tasksIndex()}
                                        className="text-xs font-medium text-primary hover:underline"
                                    >
                                        Kelola di Tasks
                                    </Link>
                                </div>

                                {priorities.length === 0 ? (
                                    <p className="rounded-lg border border-dashed border-border bg-muted/20 p-5 text-center text-xs text-muted-foreground">
                                        Tidak ada tugas aktif yang perlu
                                        diprioritaskan hari ini.
                                    </p>
                                ) : (
                                    <div className="space-y-3">
                                        {priorities.map((item, idx) => (
                                            <div
                                                key={`${item.task_id}-${idx}`}
                                                className="rounded-lg border border-border bg-background p-4"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex items-center gap-2.5">
                                                        <span className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground tabular-nums">
                                                            {idx + 1}
                                                        </span>
                                                        <h3 className="text-sm font-semibold text-foreground">
                                                            {item.task_title ??
                                                                `Task #${item.task_id}`}
                                                        </h3>
                                                    </div>
                                                    <Badge
                                                        variant="outline"
                                                        className="shrink-0 gap-1 tabular-nums"
                                                    >
                                                        <Clock className="size-3" />
                                                        {item.estimated_minutes}{' '}
                                                        mnt
                                                    </Badge>
                                                </div>

                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    <strong className="font-medium text-foreground">
                                                        Alasan:
                                                    </strong>{' '}
                                                    {item.reason}
                                                </p>
                                                <p className="mt-1 text-xs text-primary">
                                                    <strong>
                                                        Langkah konkret:
                                                    </strong>{' '}
                                                    {item.suggested_action}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>
                        </div>

                        {/* Right: Recommended Reminders & Run History */}
                        <div className="space-y-5 lg:col-span-5">
                            <section className="rounded-xl border border-border bg-card p-5">
                                <h2 className="text-base font-semibold text-foreground">
                                    Rekomendasi Pengingat Cerdas
                                </h2>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Pengingat hanya dibuat setelah Anda
                                    menyetujuinya (tunduk pada Quiet Hours).
                                </p>

                                {recommendedReminders.length === 0 ? (
                                    <p className="mt-4 rounded-lg border border-dashed border-border bg-muted/20 p-4 text-center text-xs text-muted-foreground">
                                        Tidak ada rekomendasi pengingat tambahan
                                        saat ini.
                                    </p>
                                ) : (
                                    <div className="mt-4 space-y-2.5">
                                        {recommendedReminders.map(
                                            (item, idx) => {
                                                const key = `${item.task_id}-${item.remind_at_local}`;
                                                const isApproved =
                                                    approvedKeys[key] ?? false;

                                                return (
                                                    <div
                                                        key={`${key}-${idx}`}
                                                        className="rounded-lg border border-border p-3"
                                                    >
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div>
                                                                <p className="text-sm font-medium text-foreground">
                                                                    {item.task_title ??
                                                                        `Task #${item.task_id}`}
                                                                </p>
                                                                <p className="mt-0.5 text-xs text-muted-foreground tabular-nums">
                                                                    {
                                                                        item.remind_at_local
                                                                    }
                                                                </p>
                                                            </div>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant={
                                                                    isApproved
                                                                        ? 'outline'
                                                                        : 'default'
                                                                }
                                                                disabled={
                                                                    isApproved ||
                                                                    approvingKey ===
                                                                        key
                                                                }
                                                                onClick={() =>
                                                                    handleApproveReminder(
                                                                        item,
                                                                    )
                                                                }
                                                            >
                                                                {isApproved ? (
                                                                    <>
                                                                        <Check className="size-3.5" />
                                                                        Disetujui
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <BellPlus className="size-3.5" />
                                                                        Setujui
                                                                    </>
                                                                )}
                                                            </Button>
                                                        </div>
                                                        <p className="mt-1.5 text-xs text-muted-foreground">
                                                            {item.reason}
                                                        </p>
                                                    </div>
                                                );
                                            },
                                        )}
                                    </div>
                                )}
                            </section>

                            <section className="rounded-xl border border-border bg-card p-5">
                                <h2 className="text-base font-semibold text-foreground">
                                    Riwayat Eksekusi Terbaru
                                </h2>
                                <div className="mt-3 divide-y divide-border rounded-lg border border-border">
                                    {recent_runs.map((run) => (
                                        <div
                                            key={run.id}
                                            className="flex items-center justify-between gap-2 px-3 py-2.5 text-xs"
                                        >
                                            <div>
                                                <p className="font-medium text-foreground">
                                                    {PROVIDER_LABELS[
                                                        run.provider
                                                    ] ?? run.provider}
                                                </p>
                                                <p className="text-[11px] text-muted-foreground tabular-nums">
                                                    {run.planning_date} ·{' '}
                                                    {run.trigger_type}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={
                                                    run.status === 'success'
                                                        ? 'outline'
                                                        : 'destructive'
                                                }
                                                className="text-[10px] uppercase"
                                            >
                                                {run.status}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

AiPlannerIndex.layout = {
    breadcrumbs: [
        {
            title: 'AI Planner',
            href: aiPlannerIndex(),
        },
    ],
};
