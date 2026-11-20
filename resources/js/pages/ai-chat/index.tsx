import { Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    ChevronDown,
    Database,
    History,
    MessageSquareText,
    PanelLeft,
    SendHorizontal,
    SlidersHorizontal,
    Sparkles,
    WifiOff,
} from 'lucide-react';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { toast } from 'sonner';
import AiChatController from '@/actions/App/Http/Controllers/AiChatController';
import { ActionPlanCard } from '@/components/ai-chat/action-plan-card';
import { AuditHistoryPanel } from '@/components/ai-chat/audit-history-panel';
import { ConversationSidebar } from '@/components/ai-chat/conversation-sidebar';
import { MarkdownLite } from '@/components/ai-chat/markdown-lite';
import { useOffline } from '@/components/offline/offline-provider';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useRateLimitCooldown } from '@/hooks/use-rate-limit-cooldown';
import { cn } from '@/lib/utils';
import { index as aiPlannerIndex } from '@/routes/ai-planner';
import { edit as editAiPreferences } from '@/routes/ai-preferences';
import type {
    AiActionProposal,
    AiChatConversation,
    AiChatMessage,
    AiChatSendResponse,
    AiChatToolCallSummary,
    AiPreferences,
    AiProposalDecisionSummary,
} from '@/types/models';

type Props = {
    conversations: AiChatConversation[];
    active_conversation: AiChatConversation | null;
    messages: AiChatMessage[];
    audits: AiActionProposal[];
    timezone: string;
    ai_preferences: AiPreferences;
};

type ActiveTab = 'chat' | 'audits';

const MAX_MESSAGE_LENGTH = 2000;

const STARTER_PROMPTS = [
    'Buatkan jadwal belajar minggu ini berdasarkan tugas dan jadwal kuliahku',
    'Apa saja deadline terdekatku 7 hari ke depan?',
    'Ringkas progres semua proyek aktifku saat ini',
    'Bagaimana jadwalku hari ini dan jam berapa aku senggang?',
];

const PROVIDER_LABELS: Record<string, string> = {
    gemini: 'Gemini',
    grok: 'Grok',
    openrouter: 'OpenRouter',
};

const TOOL_DISPLAY_NAMES: Record<string, string> = {
    get_my_tasks: 'Daftar Tugas',
    get_upcoming_tasks: 'Deadline Mendatang',
    get_today_schedule: 'Jadwal Hari Ini',
    get_schedule_range: 'Jadwal & Slot Kosong',
    get_my_projects: 'Daftar Proyek',
    get_daily_overview: 'Ringkasan Harian',
    create_task: 'Usulan Buat Tugas',
    update_task: 'Usulan Ubah Tugas',
    create_schedule: 'Usulan Buat Jadwal',
    update_schedule: 'Usulan Ubah Jadwal',
};

function getXsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : '';
}

function ToolCallsDisclosure({ calls }: { calls: AiChatToolCallSummary[] }) {
    const [open, setOpen] = useState(false);

    if (calls.length === 0) {
        return null;
    }

    const readCount = calls.filter((call) => call.type === 'read').length;
    const mutationCount = calls.filter(
        (call) => call.type === 'mutation_proposed',
    ).length;

    const labelParts: string[] = [];
    if (readCount > 0) {
        labelParts.push(`Memeriksa ${readCount} sumber data`);
    }
    if (mutationCount > 0) {
        labelParts.push(`menyiapkan ${mutationCount} usulan`);
    }
    const summaryLabel =
        labelParts.length > 0
            ? labelParts.join(' · ')
            : `Menggunakan ${calls.length} alat`;

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="mb-2">
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="inline-flex items-center gap-1.5 rounded-md border border-border bg-secondary/70 px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Database className="size-3.5 text-primary" />
                    <span className="tabular-nums">{summaryLabel}</span>
                    <ChevronDown
                        className={cn(
                            'size-3.5 transition-transform duration-150',
                            open && 'rotate-180',
                        )}
                    />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-1.5 rounded-lg border border-border bg-muted/40 px-3 py-2">
                <ul className="space-y-1 text-xs text-muted-foreground">
                    {calls.map((call, idx) => (
                        <li key={`${call.tool}-${idx}`} className="flex gap-2">
                            <span className="font-medium text-foreground">
                                {TOOL_DISPLAY_NAMES[call.tool] ?? call.tool}
                            </span>
                            {call.summary && (
                                <span className="truncate">
                                    — {call.summary}
                                </span>
                            )}
                            {call.error && (
                                <span className="text-destructive">
                                    — {call.error}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function AiChatIndex({
    conversations: initialConversations,
    active_conversation: initialActiveConversation,
    messages: initialMessages,
    audits: initialAudits,
    timezone,
}: Props) {
    const { db, isOnline } = useOffline();
    const [conversations, setConversations] =
        useState<AiChatConversation[]>(initialConversations);
    const [activeConversation, setActiveConversation] =
        useState<AiChatConversation | null>(initialActiveConversation);
    const [messages, setMessages] = useState<AiChatMessage[]>(initialMessages);
    const [audits, setAudits] = useState<AiActionProposal[]>(initialAudits);
    const [activeTab, setActiveTab] = useState<ActiveTab>('chat');
    const [mobileDrawerOpen, setMobileDrawerOpen] = useState(false);

    // Cache loaded conversations & messages for read-only offline viewing (`D-25`).
    useEffect(() => {
        if (!db) {
            return;
        }
        const now = new Date().toISOString();
        if (conversations.length > 0) {
            void db.cached_snapshots.put({
                key: 'ai_chat_conversations',
                payload: {
                    conversations,
                    activeConversation,
                    messages,
                },
                updated_at: now,
            });
            return;
        }

        if (!isOnline) {
            void db.cached_snapshots
                .get('ai_chat_conversations')
                .then((snap) => {
                    const cached = snap?.payload as
                        | {
                              conversations?: AiChatConversation[];
                              activeConversation?: AiChatConversation | null;
                              messages?: AiChatMessage[];
                          }
                        | undefined;
                    if (cached?.conversations) {
                        setConversations(cached.conversations);
                    }
                    if (cached?.activeConversation) {
                        setActiveConversation(cached.activeConversation);
                    }
                    if (cached?.messages) {
                        setMessages(cached.messages);
                    }
                });
        }
    }, [db, isOnline, conversations, activeConversation, messages]);

    const [input, setInput] = useState('');
    const [sending, setSending] = useState(false);
    const [loadingConversation, setLoadingConversation] = useState(false);
    const [decidingMessageId, setDecidingMessageId] = useState<number | null>(
        null,
    );
    const [serviceError, setServiceError] = useState<{
        message: string;
        fallbackUrl?: string;
    } | null>(null);

    const cooldown = useRateLimitCooldown();
    const bottomRef = useRef<HTMLDivElement | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);

    useEffect(() => {
        if (activeTab === 'chat') {
            bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
        }
    }, [messages.length, sending, activeTab]);

    const handleNewConversation = () => {
        setActiveConversation(null);
        setMessages([]);
        setServiceError(null);
        setActiveTab('chat');
        setMobileDrawerOpen(false);
        textareaRef.current?.focus();
    };

    const handleSelectConversation = async (conversationId: number) => {
        if (activeConversation?.id === conversationId && activeTab === 'chat') {
            setMobileDrawerOpen(false);
            return;
        }

        setLoadingConversation(true);
        setServiceError(null);
        setActiveTab('chat');
        setMobileDrawerOpen(false);

        try {
            const response = await fetch(
                AiChatController.show.url({ conversation: conversationId }),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            if (!response.ok) {
                toast.error('Gagal memuat percakapan.');
                return;
            }

            const data = (await response.json()) as {
                conversation: AiChatConversation;
                messages: AiChatMessage[];
            };

            setActiveConversation(data.conversation);
            setMessages(data.messages);
        } catch {
            toast.error('Tidak dapat terhubung ke server.');
        } finally {
            setLoadingConversation(false);
        }
    };

    const handleRenameConversation = async (
        conversationId: number,
        title: string,
    ) => {
        const response = await fetch(
            AiChatController.updateConversation.url({
                conversation: conversationId,
            }),
            {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                body: JSON.stringify({ title }),
            },
        );

        if (!response.ok) {
            toast.error('Gagal mengubah judul percakapan.');
            return;
        }

        const data = (await response.json()) as {
            conversation: AiChatConversation;
        };

        setConversations((prev) =>
            prev.map((item) =>
                item.id === conversationId ? data.conversation : item,
            ),
        );
        if (activeConversation?.id === conversationId) {
            setActiveConversation(data.conversation);
        }
        toast.success('Judul percakapan diperbarui.');
    };

    const handleDeleteConversation = async (conversationId: number) => {
        const response = await fetch(
            AiChatController.destroyConversation.url({
                conversation: conversationId,
            }),
            {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
            },
        );

        if (!response.ok) {
            toast.error('Gagal menghapus percakapan.');
            return;
        }

        setConversations((prev) =>
            prev.filter((item) => item.id !== conversationId),
        );
        setAudits((prev) =>
            prev.filter((item) => item.conversation_id !== conversationId),
        );

        if (activeConversation?.id === conversationId) {
            setActiveConversation(null);
            setMessages([]);
        }
        toast.success('Percakapan berhasil dihapus.');
    };

    const sendPrompt = async (rawText: string) => {
        const trimmed = rawText.trim();
        if (trimmed === '' || sending || cooldown.isCoolingDown) {
            return;
        }

        setSending(true);
        setServiceError(null);
        setActiveTab('chat');

        const optimisticId = -Date.now();
        const optimisticMessage: AiChatMessage = {
            id: optimisticId,
            conversation_id: activeConversation?.id ?? 0,
            role: 'user',
            content: trimmed,
            provider: null,
            model: null,
            tool_calls_summary: [],
            proposals: [],
            created_at: new Date().toISOString(),
            created_at_local: null,
        };

        setMessages((prev) => [...prev, optimisticMessage]);
        setInput('');

        try {
            const response = await fetch(AiChatController.storeMessage.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                body: JSON.stringify({
                    message: trimmed,
                    conversation_id: activeConversation?.id ?? null,
                }),
            });

            if (response.status === 429) {
                setMessages((prev) =>
                    prev.filter((item) => item.id !== optimisticId),
                );
                setInput(trimmed);
                cooldown.handleHttpException({
                    status: 429,
                    headers: {
                        'retry-after':
                            response.headers.get('Retry-After') ?? '60',
                    },
                });
                return;
            }

            if (response.status === 503) {
                const errData = (await response.json().catch(() => ({}))) as {
                    message?: string;
                    fallback_url?: string;
                };
                setMessages((prev) =>
                    prev.filter((item) => item.id !== optimisticId),
                );
                setInput(trimmed);
                setServiceError({
                    message:
                        errData.message ??
                        'Layanan AI Chat sedang tidak tersedia. Silakan coba lagi atau gunakan AI Planner.',
                    fallbackUrl: errData.fallback_url,
                });
                return;
            }

            if (!response.ok) {
                const errData = (await response.json().catch(() => ({}))) as {
                    message?: string;
                };
                setMessages((prev) =>
                    prev.filter((item) => item.id !== optimisticId),
                );
                setInput(trimmed);
                toast.error(
                    errData.message ??
                        'Pesan gagal diproses. Silakan coba lagi.',
                );
                return;
            }

            const data = (await response.json()) as AiChatSendResponse;
            cooldown.clearRateLimit();

            setActiveConversation(data.conversation);
            setConversations((prev) => [
                data.conversation,
                ...prev.filter((item) => item.id !== data.conversation.id),
            ]);
            setMessages((prev) => [
                ...prev.filter((item) => item.id !== optimisticId),
                data.user_message,
                data.assistant_message,
            ]);

            if (data.assistant_message.proposals.length > 0) {
                setAudits((prev) => [
                    ...data.assistant_message.proposals,
                    ...prev,
                ]);
            }
        } catch {
            setMessages((prev) =>
                prev.filter((item) => item.id !== optimisticId),
            );
            setInput(trimmed);
            setServiceError({
                message:
                    'Koneksi terputus saat menghubungi Asisten AI. Periksa jaringan Anda lalu coba lagi.',
            });
        } finally {
            setSending(false);
        }
    };

    const handleDecideProposals = async (
        messageId: number,
        approvedIds: number[],
        rejectAll: boolean,
    ) => {
        setDecidingMessageId(messageId);

        try {
            const response = await fetch(
                AiChatController.decideProposals.url({ message: messageId }),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': getXsrfToken(),
                    },
                    body: JSON.stringify({
                        approved_proposal_ids: approvedIds,
                        reject_all: rejectAll,
                    }),
                },
            );

            if (!response.ok) {
                toast.error('Gagal memproses keputusan usulan.');
                return;
            }

            const data = (await response.json()) as {
                proposals: AiActionProposal[];
                summary: AiProposalDecisionSummary;
                audits?: AiActionProposal[];
            };

            setMessages((prev) =>
                prev.map((msg) =>
                    msg.id === messageId
                        ? { ...msg, proposals: data.proposals }
                        : msg,
                ),
            );

            if (data.audits) {
                setAudits(data.audits);
            }

            if (rejectAll) {
                toast.info('Semua usulan pada pesan ini telah ditolak.');
            } else if (data.summary.executed > 0 && data.summary.failed === 0) {
                toast.success(
                    `${data.summary.executed} aksi berhasil diterapkan ke Managjeh.`,
                );
            } else if (data.summary.failed > 0) {
                toast.warning(
                    `${data.summary.executed} berhasil, ${data.summary.failed} gagal diterapkan.`,
                );
            } else if (data.summary.expired > 0) {
                toast.warning(
                    'Usulan telah melewati batas waktu 24 jam (kedaluwarsa).',
                );
            }
        } catch {
            toast.error('Terjadi gangguan jaringan saat menyimpan keputusan.');
        } finally {
            setDecidingMessageId(null);
        }
    };

    const handleTextareaKeyDown = (
        event: KeyboardEvent<HTMLTextAreaElement>,
    ) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void sendPrompt(input);
        }
    };

    const sidebarNode = (
        <ConversationSidebar
            conversations={conversations}
            activeConversationId={activeConversation?.id ?? null}
            onSelectConversation={(id) => void handleSelectConversation(id)}
            onNewConversation={handleNewConversation}
            onRenameConversation={handleRenameConversation}
            onDeleteConversation={handleDeleteConversation}
        />
    );

    return (
        <>
            <Head title="AI Chat" />

            <div className="flex flex-1 flex-col p-4 md:p-6">
                <div className="flex h-[calc(100vh-7.5rem)] min-h-[540px] overflow-hidden rounded-xl border border-border bg-card">
                    {/* Desktop conversation sidebar */}
                    <aside
                        aria-label="Riwayat percakapan AI"
                        className="hidden w-72 shrink-0 border-r border-border bg-muted/25 lg:block"
                    >
                        {sidebarNode}
                    </aside>

                    {/* Mobile conversation drawer */}
                    <Sheet
                        open={mobileDrawerOpen}
                        onOpenChange={setMobileDrawerOpen}
                    >
                        <SheetContent side="left" className="w-80 p-0">
                            <SheetHeader className="sr-only">
                                <SheetTitle>Riwayat Percakapan AI</SheetTitle>
                                <SheetDescription>
                                    Pilih, ubah nama, atau hapus percakapan AI
                                    Anda.
                                </SheetDescription>
                            </SheetHeader>
                            {sidebarNode}
                        </SheetContent>
                    </Sheet>

                    {/* Main chat / audit column */}
                    <div className="flex min-w-0 flex-1 flex-col">
                        {/* Header */}
                        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
                            <div className="flex min-w-0 items-center gap-2.5">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    className="size-8 shrink-0 lg:hidden"
                                    aria-label="Buka riwayat percakapan"
                                    onClick={() => setMobileDrawerOpen(true)}
                                >
                                    <PanelLeft className="size-4" />
                                </Button>

                                <div className="min-w-0">
                                    <h1 className="truncate text-base font-semibold tracking-tight text-foreground">
                                        {activeTab === 'audits'
                                            ? 'Riwayat Aksi AI'
                                            : (activeConversation?.title ??
                                              'Asisten AI Managjeh')}
                                    </h1>
                                    <p className="text-xs text-muted-foreground">
                                        Zona waktu aktif: {timezone}
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <div
                                    role="tablist"
                                    aria-label="Tampilan AI Chat"
                                    className="inline-flex rounded-lg border border-border bg-muted/40 p-0.5"
                                >
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected={activeTab === 'chat'}
                                        onClick={() => setActiveTab('chat')}
                                        className={cn(
                                            'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                            activeTab === 'chat'
                                                ? 'bg-card text-foreground shadow-xs'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        <MessageSquareText className="size-3.5" />
                                        <span>Percakapan</span>
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        aria-selected={activeTab === 'audits'}
                                        onClick={() => setActiveTab('audits')}
                                        className={cn(
                                            'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                            activeTab === 'audits'
                                                ? 'bg-card text-foreground shadow-xs'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        <History className="size-3.5" />
                                        <span>Riwayat Aksi</span>
                                        {audits.length > 0 && (
                                            <span className="py-0.2 rounded bg-secondary px-1.5 text-[11px] tabular-nums">
                                                {audits.length}
                                            </span>
                                        )}
                                    </button>
                                </div>

                                <Button
                                    variant="ghost"
                                    size="icon"
                                    asChild
                                    className="size-8 shrink-0 text-muted-foreground hover:text-foreground"
                                >
                                    <Link
                                        href={editAiPreferences()}
                                        aria-label="Pengaturan preferensi AI dan notifikasi"
                                    >
                                        <SlidersHorizontal className="size-4" />
                                    </Link>
                                </Button>
                            </div>
                        </header>

                        {/* Body */}
                        <div className="flex-1 overflow-y-auto px-4 py-5 md:px-6">
                            {activeTab === 'audits' ? (
                                <AuditHistoryPanel audits={audits} />
                            ) : loadingConversation ? (
                                <div className="mx-auto max-w-3xl space-y-4">
                                    <Skeleton className="ml-auto h-12 w-2/3 rounded-xl" />
                                    <Skeleton className="h-28 w-5/6 rounded-xl" />
                                    <Skeleton className="ml-auto h-10 w-1/2 rounded-xl" />
                                </div>
                            ) : messages.length === 0 ? (
                                <div className="mx-auto flex min-h-full max-w-2xl flex-col items-center justify-center py-6 text-center">
                                    <div className="mb-3 flex size-11 items-center justify-center rounded-xl border border-border bg-secondary/50">
                                        <Sparkles className="size-5 text-primary" />
                                    </div>
                                    <h2 className="text-lg font-semibold tracking-tight text-foreground">
                                        Apa yang ingin kita atur hari ini?
                                    </h2>
                                    <p className="mt-1.5 max-w-md text-sm leading-relaxed text-muted-foreground">
                                        Tanyakan deadline, jadwal kosong, atau
                                        minta disusunkan jadwal belajar. Setiap
                                        usulan perubahan data baru tersimpan
                                        setelah Anda menyetujuinya.
                                    </p>

                                    <div className="mt-6 grid w-full gap-2 sm:grid-cols-2">
                                        {STARTER_PROMPTS.map((prompt) => (
                                            <button
                                                key={prompt}
                                                type="button"
                                                disabled={
                                                    sending ||
                                                    cooldown.isCoolingDown
                                                }
                                                onClick={() =>
                                                    void sendPrompt(prompt)
                                                }
                                                className="rounded-lg border border-border bg-background px-3.5 py-3 text-left text-xs leading-relaxed text-foreground transition-colors hover:border-primary/40 hover:bg-secondary/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                                            >
                                                {prompt}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ) : (
                                <div className="mx-auto max-w-3xl space-y-5">
                                    {messages.map((message) => {
                                        if (message.role === 'user') {
                                            return (
                                                <div
                                                    key={message.id}
                                                    className="flex justify-end"
                                                >
                                                    <div className="max-w-[85%] rounded-xl border border-border bg-secondary px-4 py-3 text-sm leading-relaxed text-foreground sm:max-w-[75%]">
                                                        <p className="whitespace-pre-wrap">
                                                            {message.content}
                                                        </p>
                                                        {message.created_at_local && (
                                                            <div className="mt-1.5 text-right text-[11px] text-muted-foreground tabular-nums">
                                                                {
                                                                    message.created_at_local
                                                                }
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        }

                                        return (
                                            <div
                                                key={message.id}
                                                className="flex flex-col items-start"
                                            >
                                                <ToolCallsDisclosure
                                                    calls={
                                                        message.tool_calls_summary
                                                    }
                                                />

                                                <div className="w-full rounded-xl border border-border bg-background px-4 py-3.5">
                                                    <MarkdownLite
                                                        content={
                                                            message.content
                                                        }
                                                    />

                                                    {message.proposals.length >
                                                        0 && (
                                                        <ActionPlanCard
                                                            messageId={
                                                                message.id
                                                            }
                                                            proposals={
                                                                message.proposals
                                                            }
                                                            submitting={
                                                                decidingMessageId ===
                                                                message.id
                                                            }
                                                            onDecide={
                                                                handleDecideProposals
                                                            }
                                                        />
                                                    )}

                                                    <div className="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground tabular-nums">
                                                        {message.provider && (
                                                            <Badge
                                                                variant="outline"
                                                                className="h-5 px-1.5 text-[10px] font-normal"
                                                            >
                                                                {PROVIDER_LABELS[
                                                                    message
                                                                        .provider
                                                                ] ??
                                                                    message.provider}
                                                            </Badge>
                                                        )}
                                                        {message.created_at_local && (
                                                            <span>
                                                                {
                                                                    message.created_at_local
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}

                                    {sending && (
                                        <div
                                            role="status"
                                            aria-live="polite"
                                            className="w-full rounded-xl border border-border bg-background px-4 py-3.5"
                                        >
                                            <p className="mb-2.5 text-xs font-medium text-muted-foreground">
                                                Memeriksa data Managjeh dan
                                                menyusun jawaban...
                                            </p>
                                            <div className="space-y-2">
                                                <Skeleton className="h-3.5 w-3/4" />
                                                <Skeleton className="h-3.5 w-11/12" />
                                                <Skeleton className="h-3.5 w-1/2" />
                                            </div>
                                        </div>
                                    )}

                                    <div ref={bottomRef} />
                                </div>
                            )}
                        </div>

                        {/* Composer footer */}
                        {activeTab === 'chat' && (
                            <div className="border-t border-border bg-card px-4 py-3 md:px-6">
                                <div className="mx-auto max-w-3xl space-y-2">
                                    {!isOnline && (
                                        <div
                                            role="status"
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-300/80 bg-amber-50/80 px-3 py-2 text-xs text-amber-900 dark:border-amber-800/70 dark:bg-amber-950/40 dark:text-amber-200"
                                        >
                                            <div className="flex items-center gap-2">
                                                <WifiOff
                                                    className="size-4 shrink-0"
                                                    aria-hidden="true"
                                                />
                                                <span>
                                                    Mode Offline (Hanya Baca):
                                                    AI Chat membutuhkan koneksi
                                                    internet. Gunakan AI Planner
                                                    lokal untuk menyusun
                                                    prioritas offline.
                                                </span>
                                            </div>
                                            <Link
                                                href={aiPlannerIndex()}
                                                className="font-medium underline underline-offset-4"
                                            >
                                                Buka AI Planner
                                            </Link>
                                        </div>
                                    )}

                                    {cooldown.rateLimitMessage && (
                                        <div
                                            role="status"
                                            aria-live="polite"
                                            className="rounded-lg border border-amber-300/80 bg-amber-50/80 px-3 py-2 text-xs text-amber-900 tabular-nums dark:border-amber-800/70 dark:bg-amber-950/40 dark:text-amber-200"
                                        >
                                            {cooldown.rateLimitMessage}
                                        </div>
                                    )}

                                    {serviceError && (
                                        <div
                                            role="alert"
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-red-200 bg-red-50/80 px-3 py-2 text-xs text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200"
                                        >
                                            <div className="flex items-center gap-2">
                                                <AlertCircle className="size-4 shrink-0" />
                                                <span>
                                                    {serviceError.message}
                                                </span>
                                            </div>
                                            <Link
                                                href={aiPlannerIndex()}
                                                className="font-medium underline underline-offset-4"
                                            >
                                                Buka AI Planner
                                            </Link>
                                        </div>
                                    )}

                                    <form
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            void sendPrompt(input);
                                        }}
                                        className="rounded-xl border border-input bg-background p-2 focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30"
                                    >
                                        <textarea
                                            ref={textareaRef}
                                            value={input}
                                            onChange={(e) =>
                                                setInput(
                                                    e.target.value.slice(
                                                        0,
                                                        MAX_MESSAGE_LENGTH,
                                                    ),
                                                )
                                            }
                                            onKeyDown={handleTextareaKeyDown}
                                            rows={2}
                                            maxLength={MAX_MESSAGE_LENGTH}
                                            disabled={!isOnline || sending}
                                            aria-label="Tulis pesan untuk Asisten AI Managjeh"
                                            placeholder={
                                                isOnline
                                                    ? 'Tulis pertanyaan atau minta bantuan menyusun jadwal belajar... (Enter untuk kirim, Shift+Enter baris baru)'
                                                    : 'Mode offline — hubungkan perangkat ke internet untuk mengirim pesan ke AI Chat.'
                                            }
                                            className="w-full resize-none bg-transparent px-2 py-1 text-sm leading-relaxed text-foreground placeholder:text-muted-foreground focus:outline-none disabled:opacity-60"
                                        />

                                        <div className="mt-1.5 flex items-center justify-between gap-2 px-1">
                                            <span
                                                className={cn(
                                                    'text-[11px] text-muted-foreground tabular-nums',
                                                    input.length >= 1900 &&
                                                        'text-amber-700 dark:text-amber-400',
                                                )}
                                            >
                                                {input.length.toLocaleString(
                                                    'id-ID',
                                                )}{' '}
                                                / 2.000
                                            </span>

                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={
                                                    !isOnline ||
                                                    sending ||
                                                    cooldown.isCoolingDown ||
                                                    input.trim() === ''
                                                }
                                                className="gap-1.5"
                                            >
                                                <span>
                                                    {sending
                                                        ? 'Memproses...'
                                                        : 'Kirim'}
                                                </span>
                                                <SendHorizontal className="size-3.5" />
                                            </Button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
