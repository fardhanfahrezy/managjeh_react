import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, Check } from 'lucide-react';
import type { FormEvent } from 'react';
import AiPreferencesController from '@/actions/App/Http/Controllers/Settings/AiPreferencesController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { edit as editProfile } from '@/routes/profile';
import type {
    AiPreferences,
    AiTone,
    NotificationPreferences,
} from '@/types/models';

type Props = {
    ai_preferences: AiPreferences;
    notification_preferences: NotificationPreferences;
    whatsapp_ready: boolean;
    timezone: string;
    status?: string | null;
};

const TONE_OPTIONS: { value: AiTone; label: string; description: string }[] = [
    {
        value: 'concise',
        label: 'Ringkas',
        description: 'Padat poin-poin, langsung ke inti.',
    },
    {
        value: 'balanced',
        label: 'Seimbang',
        description: 'Terstruktur, praktis, dan jelas.',
    },
    {
        value: 'detailed',
        label: 'Mendetail',
        description: 'Lengkap dengan alasan tiap saran.',
    },
];

const FOCUS_DURATION_OPTIONS = [30, 45, 60, 90];

const WEEKDAY_OPTIONS = [
    { value: 0, label: 'Minggu' },
    { value: 1, label: 'Senin' },
    { value: 2, label: 'Selasa' },
    { value: 3, label: 'Rabu' },
    { value: 4, label: 'Kamis' },
    { value: 5, label: 'Jumat' },
    { value: 6, label: 'Sabtu' },
];

export default function AiSettings({
    ai_preferences,
    notification_preferences,
    whatsapp_ready,
    timezone,
    status,
}: Props) {
    const form = useForm({
        ai_preferences: {
            tone: ai_preferences.tone,
            preferred_study_start: ai_preferences.preferred_study_start,
            preferred_study_end: ai_preferences.preferred_study_end,
            default_focus_minutes: ai_preferences.default_focus_minutes,
            custom_instruction: ai_preferences.custom_instruction ?? '',
        },
        notification_preferences: {
            daily_summary_enabled:
                notification_preferences.daily_summary_enabled,
            daily_summary_time: notification_preferences.daily_summary_time,
            weekly_summary_enabled:
                notification_preferences.weekly_summary_enabled,
            weekly_summary_day: notification_preferences.weekly_summary_day,
            weekly_summary_time: notification_preferences.weekly_summary_time,
            event_prep_enabled: notification_preferences.event_prep_enabled,
        },
    });

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(AiPreferencesController.update.url(), {
            preserveScroll: true,
        });
    };

    const formErrors = form.errors as Record<string, string | undefined>;
    const customInstructionLength =
        form.data.ai_preferences.custom_instruction.length;

    return (
        <>
            <Head title="Pengaturan AI & Notifikasi" />

            <form onSubmit={handleSubmit} className="space-y-8">
                {/* Section 1: AI Preferences */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Preferensi Asisten AI"
                        description="Atur gaya jawaban, jam belajar favorit, dan sesi fokus default untuk AI Chat serta AI Planner."
                    />

                    <div className="space-y-2">
                        <Label>Gaya respons AI</Label>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {TONE_OPTIONS.map((option) => {
                                const selected =
                                    form.data.ai_preferences.tone ===
                                    option.value;

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() =>
                                            form.setData('ai_preferences', {
                                                ...form.data.ai_preferences,
                                                tone: option.value,
                                            })
                                        }
                                        className={`rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                                            selected
                                                ? 'border-primary bg-emerald-50/70 dark:bg-emerald-950/30'
                                                : 'border-border bg-card hover:bg-secondary/60'
                                        }`}
                                    >
                                        <div className="flex items-center justify-between gap-2 text-sm font-medium text-foreground">
                                            <span>{option.label}</span>
                                            {selected && (
                                                <Check className="size-3.5 text-primary" />
                                            )}
                                        </div>
                                        <p className="mt-1 text-xs leading-snug text-muted-foreground">
                                            {option.description}
                                        </p>
                                    </button>
                                );
                            })}
                        </div>
                        <InputError
                            message={formErrors['ai_preferences.tone']}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="preferred_study_start">
                                Jam belajar mulai ({timezone})
                            </Label>
                            <Input
                                id="preferred_study_start"
                                type="time"
                                className="tabular-nums"
                                value={
                                    form.data.ai_preferences
                                        .preferred_study_start
                                }
                                onChange={(e) =>
                                    form.setData('ai_preferences', {
                                        ...form.data.ai_preferences,
                                        preferred_study_start: e.target.value,
                                    })
                                }
                                required
                            />
                            <InputError
                                message={
                                    formErrors[
                                        'ai_preferences.preferred_study_start'
                                    ]
                                }
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="preferred_study_end">
                                Jam belajar selesai ({timezone})
                            </Label>
                            <Input
                                id="preferred_study_end"
                                type="time"
                                className="tabular-nums"
                                value={
                                    form.data.ai_preferences.preferred_study_end
                                }
                                onChange={(e) =>
                                    form.setData('ai_preferences', {
                                        ...form.data.ai_preferences,
                                        preferred_study_end: e.target.value,
                                    })
                                }
                                required
                            />
                            <InputError
                                message={
                                    formErrors[
                                        'ai_preferences.preferred_study_end'
                                    ]
                                }
                            />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="default_focus_minutes">
                            Durasi blok fokus default
                        </Label>
                        <Select
                            value={String(
                                form.data.ai_preferences.default_focus_minutes,
                            )}
                            onValueChange={(value) =>
                                form.setData('ai_preferences', {
                                    ...form.data.ai_preferences,
                                    default_focus_minutes: Number(value),
                                })
                            }
                        >
                            <SelectTrigger
                                id="default_focus_minutes"
                                className="w-full tabular-nums sm:w-56"
                            >
                                <SelectValue placeholder="Pilih durasi" />
                            </SelectTrigger>
                            <SelectContent>
                                {FOCUS_DURATION_OPTIONS.map((minutes) => (
                                    <SelectItem
                                        key={minutes}
                                        value={String(minutes)}
                                    >
                                        {minutes} menit per sesi
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            message={
                                formErrors[
                                    'ai_preferences.default_focus_minutes'
                                ]
                            }
                        />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center justify-between gap-2">
                            <Label htmlFor="custom_instruction">
                                Instruksi pribadi singkat (opsional)
                            </Label>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {customInstructionLength} / 300
                            </span>
                        </div>
                        <textarea
                            id="custom_instruction"
                            rows={3}
                            maxLength={300}
                            value={form.data.ai_preferences.custom_instruction}
                            onChange={(e) =>
                                form.setData('ai_preferences', {
                                    ...form.data.ai_preferences,
                                    custom_instruction: e.target.value.slice(
                                        0,
                                        300,
                                    ),
                                })
                            }
                            placeholder="Contoh: Prioritaskan tugas praktikum di pagi hari dan sisakan jeda 15 menit antar sesi belajar."
                            className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm leading-relaxed text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        />
                        <InputError
                            message={
                                formErrors['ai_preferences.custom_instruction']
                            }
                        />
                    </div>
                </div>

                <Separator />

                {/* Section 2: Outbound Notification Preferences */}
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Ringkasan & Pengingat Outbound"
                        description="Kontrol jadwal ringkasan harian, evaluasi mingguan, dan pengingat persiapan kegiatan ke WhatsApp."
                    />

                    {!whatsapp_ready && (
                        <div className="flex items-start gap-2.5 rounded-lg border border-amber-300/80 bg-amber-50/80 px-3.5 py-3 text-xs leading-relaxed text-amber-900 dark:border-amber-800/70 dark:bg-amber-950/40 dark:text-amber-200">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                            <div>
                                Pengingat WhatsApp belum aktif atau nomor Anda
                                belum terverifikasi OTP. Pengaturan di bawah
                                tetap dapat disimpan, lalu aktifkan nomor
                                WhatsApp Anda di{' '}
                                <Link
                                    href={editProfile()}
                                    className="font-medium underline underline-offset-4"
                                >
                                    Pengaturan Profil
                                </Link>
                                .
                            </div>
                        </div>
                    )}

                    {/* Event prep toggle */}
                    <div className="flex items-start gap-3 rounded-lg border border-border bg-card p-3.5">
                        <Checkbox
                            id="event_prep_enabled"
                            checked={
                                form.data.notification_preferences
                                    .event_prep_enabled
                            }
                            onCheckedChange={(checked) =>
                                form.setData('notification_preferences', {
                                    ...form.data.notification_preferences,
                                    event_prep_enabled: checked === true,
                                })
                            }
                            className="mt-0.5"
                        />
                        <div className="space-y-1">
                            <Label
                                htmlFor="event_prep_enabled"
                                className="cursor-pointer text-sm font-medium"
                            >
                                Persiapan kegiatan (H-15 menit)
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Kirim pengingat singkat 15 menit sebelum kuliah,
                                rapat, atau sesi belajar terjadwal dimulai.
                            </p>
                        </div>
                    </div>

                    {/* Daily summary */}
                    <div className="space-y-3 rounded-lg border border-border bg-card p-3.5">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="daily_summary_enabled"
                                checked={
                                    form.data.notification_preferences
                                        .daily_summary_enabled
                                }
                                onCheckedChange={(checked) =>
                                    form.setData('notification_preferences', {
                                        ...form.data.notification_preferences,
                                        daily_summary_enabled: checked === true,
                                    })
                                }
                                className="mt-0.5"
                            />
                            <div className="space-y-1">
                                <Label
                                    htmlFor="daily_summary_enabled"
                                    className="cursor-pointer text-sm font-medium"
                                >
                                    Ringkasan harian pagi
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Rekap tugas jatuh tempo hari ini, agenda
                                    harian, dan prioritas teratas (menghormati
                                    jam tenang).
                                </p>
                            </div>
                        </div>

                        {form.data.notification_preferences
                            .daily_summary_enabled && (
                            <div className="grid gap-1.5 pl-7 sm:max-w-xs">
                                <Label htmlFor="daily_summary_time">
                                    Jam kirim harian ({timezone})
                                </Label>
                                <Input
                                    id="daily_summary_time"
                                    type="time"
                                    className="tabular-nums"
                                    value={
                                        form.data.notification_preferences
                                            .daily_summary_time
                                    }
                                    onChange={(e) =>
                                        form.setData(
                                            'notification_preferences',
                                            {
                                                ...form.data
                                                    .notification_preferences,
                                                daily_summary_time:
                                                    e.target.value,
                                            },
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        formErrors[
                                            'notification_preferences.daily_summary_time'
                                        ]
                                    }
                                />
                            </div>
                        )}
                    </div>

                    {/* Weekly summary */}
                    <div className="space-y-3 rounded-lg border border-border bg-card p-3.5">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="weekly_summary_enabled"
                                checked={
                                    form.data.notification_preferences
                                        .weekly_summary_enabled
                                }
                                onCheckedChange={(checked) =>
                                    form.setData('notification_preferences', {
                                        ...form.data.notification_preferences,
                                        weekly_summary_enabled:
                                            checked === true,
                                    })
                                }
                                className="mt-0.5"
                            />
                            <div className="space-y-1">
                                <Label
                                    htmlFor="weekly_summary_enabled"
                                    className="cursor-pointer text-sm font-medium"
                                >
                                    Evaluasi & ringkasan mingguan
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Rekap tugas selesai 7 hari terakhir, progres
                                    proyek aktif, dan ajakan refleksi di AI
                                    Chat.
                                </p>
                            </div>
                        </div>

                        {form.data.notification_preferences
                            .weekly_summary_enabled && (
                            <div className="grid gap-3 pl-7 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="weekly_summary_day">
                                        Hari kirim
                                    </Label>
                                    <Select
                                        value={String(
                                            form.data.notification_preferences
                                                .weekly_summary_day,
                                        )}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'notification_preferences',
                                                {
                                                    ...form.data
                                                        .notification_preferences,
                                                    weekly_summary_day:
                                                        Number(value),
                                                },
                                            )
                                        }
                                    >
                                        <SelectTrigger id="weekly_summary_day">
                                            <SelectValue placeholder="Pilih hari" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {WEEKDAY_OPTIONS.map((day) => (
                                                <SelectItem
                                                    key={day.value}
                                                    value={String(day.value)}
                                                >
                                                    {day.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="weekly_summary_time">
                                        Jam kirim ({timezone})
                                    </Label>
                                    <Input
                                        id="weekly_summary_time"
                                        type="time"
                                        className="tabular-nums"
                                        value={
                                            form.data.notification_preferences
                                                .weekly_summary_time
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'notification_preferences',
                                                {
                                                    ...form.data
                                                        .notification_preferences,
                                                    weekly_summary_time:
                                                        e.target.value,
                                                },
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex items-center gap-4">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Menyimpan...' : 'Simpan Preferensi'}
                    </Button>

                    {(form.recentlySuccessful || status) && (
                        <p className="text-sm text-primary">
                            {status ?? 'Preferensi tersimpan.'}
                        </p>
                    )}
                </div>
            </form>
        </>
    );
}
