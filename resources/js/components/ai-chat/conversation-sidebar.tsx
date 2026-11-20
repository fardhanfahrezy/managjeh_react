import { Check, MessageSquarePlus, Pencil, Trash2, X } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { AiChatConversation } from '@/types/models';

type Props = {
    conversations: AiChatConversation[];
    activeConversationId: number | null;
    onSelectConversation: (conversationId: number) => void;
    onNewConversation: () => void;
    onRenameConversation: (
        conversationId: number,
        title: string,
    ) => Promise<void>;
    onDeleteConversation: (conversationId: number) => Promise<void>;
};

export function ConversationSidebar({
    conversations,
    activeConversationId,
    onSelectConversation,
    onNewConversation,
    onRenameConversation,
    onDeleteConversation,
}: Props) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editingTitle, setEditingTitle] = useState('');
    const [savingRename, setSavingRename] = useState(false);
    const [deletingConversation, setDeletingConversation] =
        useState<AiChatConversation | null>(null);
    const [deleting, setDeleting] = useState(false);

    const startRename = (conversation: AiChatConversation) => {
        setEditingId(conversation.id);
        setEditingTitle(conversation.title);
    };

    const cancelRename = () => {
        setEditingId(null);
        setEditingTitle('');
    };

    const submitRename = async (conversationId: number) => {
        const clean = editingTitle.trim();
        if (clean === '') {
            return;
        }

        setSavingRename(true);
        try {
            await onRenameConversation(conversationId, clean.slice(0, 60));
            setEditingId(null);
        } finally {
            setSavingRename(false);
        }
    };

    const confirmDelete = async () => {
        if (!deletingConversation) {
            return;
        }

        setDeleting(true);
        try {
            await onDeleteConversation(deletingConversation.id);
            setDeletingConversation(null);
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="flex h-full flex-col">
            <div className="border-b border-border p-3">
                <Button
                    type="button"
                    variant="outline"
                    className="w-full justify-start gap-2 bg-card"
                    onClick={onNewConversation}
                >
                    <MessageSquarePlus className="size-4 text-primary" />
                    <span>Percakapan Baru</span>
                </Button>
            </div>

            <div className="flex-1 space-y-1 overflow-y-auto p-2">
                {conversations.length === 0 ? (
                    <p className="px-2 py-6 text-center text-xs leading-relaxed text-muted-foreground">
                        Belum ada riwayat percakapan. Kirim pesan pertama untuk
                        memulai.
                    </p>
                ) : (
                    conversations.map((conversation) => {
                        const isActive =
                            activeConversationId === conversation.id;
                        const isEditing = editingId === conversation.id;

                        if (isEditing) {
                            return (
                                <form
                                    key={conversation.id}
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        void submitRename(conversation.id);
                                    }}
                                    className="flex items-center gap-1 rounded-md border border-border bg-card p-1.5"
                                >
                                    <Input
                                        value={editingTitle}
                                        onChange={(e) =>
                                            setEditingTitle(e.target.value)
                                        }
                                        maxLength={60}
                                        autoFocus
                                        aria-label="Judul percakapan baru"
                                        className="h-7 px-2 text-xs"
                                        disabled={savingRename}
                                    />
                                    <Button
                                        type="submit"
                                        size="icon"
                                        variant="ghost"
                                        className="size-7 shrink-0"
                                        aria-label="Simpan judul percakapan"
                                        disabled={
                                            savingRename ||
                                            editingTitle.trim() === ''
                                        }
                                    >
                                        <Check className="size-3.5 text-primary" />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-7 shrink-0"
                                        aria-label="Batal ubah judul"
                                        onClick={cancelRename}
                                        disabled={savingRename}
                                    >
                                        <X className="size-3.5" />
                                    </Button>
                                </form>
                            );
                        }

                        return (
                            <div
                                key={conversation.id}
                                className={cn(
                                    'group flex items-center gap-1 rounded-md px-2 py-2 text-sm transition-colors',
                                    isActive
                                        ? 'bg-secondary font-medium text-foreground'
                                        : 'text-muted-foreground hover:bg-secondary/60 hover:text-foreground',
                                )}
                            >
                                <button
                                    type="button"
                                    onClick={() =>
                                        onSelectConversation(conversation.id)
                                    }
                                    className="min-w-0 flex-1 text-left focus-visible:outline-none"
                                >
                                    <div className="truncate text-[13px] leading-snug">
                                        {conversation.title}
                                    </div>
                                    {conversation.last_message_at_local && (
                                        <div className="mt-0.5 text-[11px] text-muted-foreground tabular-nums">
                                            {conversation.last_message_at_local}
                                        </div>
                                    )}
                                </button>

                                <div className="flex shrink-0 items-center gap-0.5 opacity-100 transition-opacity sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100">
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-7 text-muted-foreground hover:text-foreground"
                                        aria-label={`Ubah judul percakapan ${conversation.title}`}
                                        onClick={() =>
                                            startRename(conversation)
                                        }
                                    >
                                        <Pencil className="size-3.5" />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-7 text-muted-foreground hover:text-destructive"
                                        aria-label={`Hapus percakapan ${conversation.title}`}
                                        onClick={() =>
                                            setDeletingConversation(
                                                conversation,
                                            )
                                        }
                                    >
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </div>
                            </div>
                        );
                    })
                )}
            </div>

            <div className="border-t border-border px-3 py-2.5 text-[11px] leading-relaxed text-muted-foreground">
                Riwayat percakapan disimpan otomatis selama 30 hari dan dapat
                dihapus kapan saja.
            </div>

            <Dialog
                open={deletingConversation !== null}
                onOpenChange={(open) => {
                    if (!open && !deleting) {
                        setDeletingConversation(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus percakapan ini?</DialogTitle>
                        <DialogDescription>
                            Percakapan &ldquo;{deletingConversation?.title}
                            &rdquo; beserta daftar pesan dan usulan aksinya akan
                            dihapus permanen. Tugas atau jadwal yang sudah
                            disetujui sebelumnya tidak ikut terhapus.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDeletingConversation(null)}
                            disabled={deleting}
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => void confirmDelete()}
                            disabled={deleting}
                        >
                            {deleting ? 'Menghapus...' : 'Hapus Percakapan'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
