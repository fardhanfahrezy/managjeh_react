import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
    timezones = ['Asia/Jakarta'],
}: {
    mustVerifyEmail: boolean;
    status?: string;
    timezones?: string[];
}) {
    const { auth } = usePage<PageProps>().props;
    const isWhatsAppVerified = auth.user.whatsapp_verified_at !== null;
    const quietStart = (auth.user.reminder_quiet_start ?? '00:00').slice(0, 5);
    const quietEnd = (auth.user.reminder_quiet_end ?? '05:00').slice(0, 5);

    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your name, email address, timezone, and WhatsApp reminder preferences"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Email address"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Your email address is unverified.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Click here to re-send the
                                                verification email.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                A new verification link has been
                                                sent to your email address.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="space-y-5 border-t border-border pt-6">
                                <Heading
                                    variant="small"
                                    title="Zona Waktu & Pengingat WhatsApp"
                                    description="Atur zona waktu lokal, nomor WhatsApp, dan jam tenang pengingat (quiet hours)."
                                />

                                <div className="grid gap-2">
                                    <Label htmlFor="timezone">Zona Waktu</Label>
                                    <select
                                        id="timezone"
                                        name="timezone"
                                        defaultValue={auth.user.timezone}
                                        className="flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    >
                                        {timezones.map((tz) => (
                                            <option key={tz} value={tz}>
                                                {tz}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        className="mt-1"
                                        message={errors.timezone}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <Label htmlFor="whatsapp_number">
                                            Nomor WhatsApp
                                        </Label>
                                        <Badge
                                            variant="outline"
                                            className={
                                                isWhatsAppVerified
                                                    ? 'border-emerald-600/25 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-950/50 dark:text-emerald-300'
                                                    : 'border-amber-600/25 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/50 dark:text-amber-300'
                                            }
                                        >
                                            {isWhatsAppVerified
                                                ? 'Terverifikasi'
                                                : 'Belum terverifikasi'}
                                        </Badge>
                                    </div>

                                    <Input
                                        id="whatsapp_number"
                                        name="whatsapp_number"
                                        defaultValue={
                                            auth.user.whatsapp_number ?? ''
                                        }
                                        placeholder="+6281234567890"
                                        className="tabular-nums"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Gunakan format internasional (contoh:
                                        +6281234567890). Mengubah nomor akan
                                        mereset status verifikasi.
                                    </p>
                                    <InputError
                                        className="mt-1"
                                        message={errors.whatsapp_number}
                                    />
                                </div>

                                <div className="flex items-start gap-3">
                                    <input
                                        type="hidden"
                                        name="whatsapp_enabled"
                                        value="0"
                                    />
                                    <input
                                        id="whatsapp_enabled"
                                        type="checkbox"
                                        name="whatsapp_enabled"
                                        value="1"
                                        defaultChecked={
                                            auth.user.whatsapp_enabled
                                        }
                                        className="mt-1 size-4 rounded border-input text-primary accent-[#059669] focus-visible:ring-ring"
                                    />
                                    <div className="space-y-0.5">
                                        <Label
                                            htmlFor="whatsapp_enabled"
                                            className="cursor-pointer"
                                        >
                                            Aktifkan pengingat WhatsApp
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            Pesan hanya akan dikirim setelah
                                            nomor terverifikasi OTP.
                                        </p>
                                    </div>
                                </div>
                                <InputError
                                    className="mt-1"
                                    message={errors.whatsapp_enabled}
                                />

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="reminder_quiet_start">
                                            Awal Jam Tenang
                                        </Label>
                                        <Input
                                            id="reminder_quiet_start"
                                            type="time"
                                            name="reminder_quiet_start"
                                            defaultValue={quietStart}
                                            className="tabular-nums"
                                        />
                                        <InputError
                                            className="mt-1"
                                            message={
                                                errors.reminder_quiet_start
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="reminder_quiet_end">
                                            Akhir Jam Tenang
                                        </Label>
                                        <Input
                                            id="reminder_quiet_end"
                                            type="time"
                                            name="reminder_quiet_end"
                                            defaultValue={quietEnd}
                                            className="tabular-nums"
                                        />
                                        <InputError
                                            className="mt-1"
                                            message={errors.reminder_quiet_end}
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
