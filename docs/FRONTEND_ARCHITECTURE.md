# Frontend Architecture — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan arsitektur frontend **React 19 + Inertia.js v3 + TypeScript + Tailwind CSS v4** yang telah diimplementasikan (`Implemented & Verified`) untuk **Managjeh MVP 1** di atas sistem desain **"The Quiet Desk"** ([DESIGN.md](../DESIGN.md)).

---

## 1. Technology Stack & Konvensi (`FACT`)

- **React:** `^19.2.0` (`babel-plugin-react-compiler` `^1.0.0`, `strictMode: true`).
- **Inertia.js v3:** `@inertiajs/react` `^3.0.0`, `@inertiajs/vite` `^3.0.0` (menggunakan `<Form>`, `<Link>`, `useForm`, `usePage`, dan `router`; **Axios tidak digunakan**).
- **Typed Routing (Laravel Wayfinder):** `@laravel/vite-plugin-wayfinder` & `laravel/wayfinder` (`0.1.23`) menghasilkan fungsi route dan controller action bertipe di `resources/js/routes/*` dan `resources/js/actions/*` (termasuk varian `.form()` melalui `php artisan wayfinder:generate --with-form`).
- **Styling & UI Primitives:** Tailwind CSS v4 ([resources/css/app.css](../resources/css/app.css)), komponen Radix UI di `resources/js/components/ui/*`, ikon `lucide-react`, dan notifikasi toast `sonner`.
- **Visual System ("The Quiet Desk" — [DESIGN.md](../DESIGN.md)):**
    - Font tunggal lokal **Inter** (_variable font_) dengan `tabular-nums` pada waktu, tanggal, durasi, dan persentase.
    - Kanvas hangat _Bright Snow_ (`#fafaf9`), permukaan kartu _Pure White_ (`#ffffff`) berborder `1px` _Hairline Stone_ (`#e7e5e4`), aksen primer _Shamrock Emerald_ (`#059669`), serta _Ochre Amber_ (`#d97706`) / _Crimson Alert_ (`#dc2626`) khusus sinyal status nyata.

---

## 2. Kontrak TypeScript Aktual (`resources/js/types/`)

### 2.1 Tipe `User` di [resources/js/types/auth.ts](../resources/js/types/auth.ts)

```ts
export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    timezone: string;
    whatsapp_number: string | null;
    whatsapp_verified_at: string | null;
    whatsapp_enabled: boolean;
    reminder_quiet_start: string | null;
    reminder_quiet_end: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};
```

### 2.2 Tipe Domain di [resources/js/types/models.ts](../resources/js/types/models.ts)

```ts
export type ProjectStatus = 'active' | 'completed' | 'archived';
export type TaskStatus = 'todo' | 'in_progress' | 'completed' | 'cancelled';
export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';
export type CalendarView = 'day' | 'week' | 'month';
export type AiPlannerRunStatus = 'pending' | 'success' | 'failed';
```

File `resources/js/types/models.ts` mengekspor tipe lengkap yang digunakan lintas halaman:

- `ProjectSummary`, `Project`, `Task`, `TaskOption`
- `CalendarEvent` (termasuk `series_id`, `occurrence_key`, `is_recurring`, `is_override`, `starts_at_local`, `ends_at_local`, `has_conflict`), `TaskDeadlineItem`, `ConflictWarning`
- `DashboardData`, `DashboardTaskItem`, `DashboardProjectItem`, `DashboardWhatsAppStatus`, `DashboardCompletedSummary`
- `AiPlannerRun`, `AiPlannerResult`, `AiPlannerPriorityItem`, `AiPlannerRecommendedReminder`, `AiPlannerWarning`

---

## 3. Daftar Halaman & Komponen Utama (`Implemented & Verified`)

Seluruh halaman aplikasi menggunakan layout utama `AppLayout` (sidebar navigasi responsif di kiri + area konten utama dengan wrapper konsisten `flex flex-1 flex-col gap-5 p-4 md:p-6` atau `gap-6`):

| No    | Halaman / Komponen                 | Path File (`resources/js/`)                                                   | Route Name                         | Status Aktual            | Fitur Utama                                                                                                                                                                                                     |
| :---- | :--------------------------------- | :---------------------------------------------------------------------------- | :--------------------------------- | :----------------------- | :-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **1** | **Dashboard**                      | `pages/dashboard.tsx`                                                         | `dashboard`                        | `Implemented & Verified` | 8 kartu ringkasan: sapaan lokal, 3 prioritas AI, tugas hari ini (centang cepat), tenggat terdekat, agenda hari ini, proyek aktif, status WA, metrik selesai.                                                    |
| **2** | **Tasks List**                     | `pages/tasks/index.tsx`                                                       | `tasks.index`                      | `Implemented & Verified` | Filter status, prioritas, & proyek; daftar tugas dikelompokkan; ekspansi subtugas 1 tingkat (`D-09`); empty state.                                                                                              |
| **3** | **Task Form & Row Components**     | `components/tasks/task-form-dialog.tsx`, `components/tasks/task-row-item.tsx` | —                                  | `Implemented & Verified` | Dialog buat/edit tugas & pengelolaan subtugas inline; badge prioritas/status & aksi cepat ubah status.                                                                                                          |
| **4** | **Projects List**                  | `pages/projects/index.tsx`                                                    | `projects.index`                   | `Implemented & Verified` | Filter status proyek, grid kartu proyek dengan progress bar (`0–100%`), dialog buat/edit/hapus proyek.                                                                                                          |
| **5** | **Project Detail**                 | `pages/projects/show.tsx`                                                     | `projects.show`                    | `Implemented & Verified` | Ringkasan progres proyek, daftar tugas dalam proyek, dan tombol tambah tugas langsung ke proyek tersebut.                                                                                                       |
| **6** | **Calendar**                       | `pages/calendar/index.tsx`                                                    | `calendar.index`                   | `Implemented & Verified` | Switcher `Day` / `Week` / `Month`, grid jam & _all-day_, indikator deadline tugas, banner konflik jadwal, modal berulang (`D-08`) + dialog pilih scope `single` vs `series`.                                    |
| **7** | **AI Planner**                     | `pages/ai-planner/index.tsx`                                                  | `ai-planner.index`                 | `Implemented & Verified` | Tombol generate manual (dengan cooldown `429`), 3 kartu prioritas utama, rekomendasi pengingat WA 1-klik (`approveReminder`), banner peringatan, & riwayat run.                                                 |
| **8** | **Profile, WhatsApp & Privacy**    | `pages/settings/profile.tsx`                                                  | `profile.edit`                     | `Implemented & Verified` | Form profil, timezone IANA, nomor WA, toggle pengingat, jam tenang (`00:00–05:00`), verifikasi OTP WA (dengan cooldown `429`), kartu **Data & Privasi** (ekspor JSON & info retensi 30 hari), serta hapus akun. |
| **9** | **Security & Appearance Settings** | `pages/settings/security.tsx`, `pages/settings/appearance.tsx`                | `security.edit`, `appearance.edit` | `Implemented & Verified` | Ubah password, kelola 2FA TOTP + recovery codes, WebAuthn Passkeys, dan pilih tema (`light`/`dark`/`system`).                                                                                                   |

---

## 4. Custom Hooks (`resources/js/hooks/`)

- [use-rate-limit-cooldown.ts](../resources/js/hooks/use-rate-limit-cooldown.ts) — Menangani respons HTTP `429 Too Many Requests` melalui callback `onHttpException` Inertia v3, membaca header `Retry-After`, menonaktifkan tombol sementara, dan menyediakan pesan hitung mundur (`role="status" aria-live="polite"`).
- [use-flash-toast.ts](../resources/js/hooks/use-flash-toast.ts) — Menampilkan notifikasi toast `sonner` secara otomatis dari flash data Inertia (`toast.success` / `toast.error`).
- [use-appearance.tsx](../resources/js/hooks/use-appearance.tsx) — Mengelola preferensi mode terang/gelap/sistem.
- [use-mobile.tsx](../resources/js/hooks/use-mobile.tsx) & [use-mobile-navigation.ts](../resources/js/hooks/use-mobile-navigation.ts) — Responsivitas sidebar dan drawer navigasi mobile.

---

## 5. Aksesibilitas & Responsivitas

1. **Keyboard & Screen Reader:** Semua kontrol interaktif menggunakan primitif Radix UI dengan cincin fokus `focus-visible` `3px`; seluruh tombol ikon (`size="icon"`) memiliki `aria-label` kontekstual (mis. `Edit project ...`, `Hapus tugas ...`, `Periode sebelumnya`); pesan status/cooldown menggunakan `role="status"` dan `aria-live="polite"`.
2. **Tabular Numerals:** Seluruh tampilan angka waktu, tanggal, persentase progres, kode OTP, dan durasi menggunakan utilitas `tabular-nums`.
3. **Mobile-First Layout:** Header halaman, filter bar, dan kartu beradaptasi dari layar ponsel (`375px`) hingga desktop lebar menggunakan kombinasi `flex-col sm:flex-row`, `min-w-0`, dan drawer `Sheet` pada navigasi mobile.
4. **Optimasi Code-Splitting Entry (`FACT`):** Di [resources/js/app.tsx](../resources/js/app.tsx), `AuthLayout`, `SettingsLayout`, dan `Toaster` (`sonner`) dimuat secara lazy menggunakan `React.lazy` + `Suspense` agar tidak menghalangi initial load halaman utama seperti `/dashboard`.

---

## 6. Rencana Frontend Lanjutan — Website AI Chat Hub (`CONFIRMED` / `Planned` — `D-17`, `D-18`, `D-19`)

> **Status Implementasi:** `Planned` (desain teknis MVP 2 terkunci; belum ada halaman/komponen AI Chat di `resources/js/` saat ini; `/ai-planner` pada Bagian 3 tetap dipertahankan).

1. **AI Chat Interface (`/ai-chat`, Berdampingan dengan `/ai-planner` — `D-17`):**
    - Menambahkan menu baru **"AI Chat"** di [app-sidebar.tsx](../resources/js/components/app-sidebar.tsx) di dalam `AppLayout` mengikuti sistem desain **"The Quiet Desk"** ([DESIGN.md](../DESIGN.md)).
    - Tata letak **2 kolom di desktop** (daftar riwayat percakapan di kiri + panel chat di kanan), **drawer daftar percakapan di mobile (`375px`)**, serta tombol **starter prompt chips** (mis. susun jadwal belajar minggu ini, cek deadline terdekat).
    - Pengiriman pesan menggunakan **synchronous JSON request-response** (`fetch` / Inertia `useHttp`) dengan optimistic user bubble, indikator status memproses, batas input `2.000` karakter, serta reuse [use-rate-limit-cooldown.ts](../resources/js/hooks/use-rate-limit-cooldown.ts) untuk menangani HTTP `429`.
2. **Riwayat Percakapan & Panel Audit (`D-19`):**
    - Daftar sesi percakapan dengan judul otomatis dari pesan pertama (maks 60 karakter), aksi rename & hapus manual, info retensi otomatis 30 hari, serta panel/tab ringkas **"Riwayat Aksi AI"** di `/ai-chat`.
3. **Action Plan Confirmation UI (_Human-in-the-Loop_):**
    - Kartu Rencana Aksi (Action Plan Card) menampilkan daftar item usulan mutasi (`create_task`, `update_task`, `create_schedule`, `update_schedule`) dilengkapi **checkbox per item**, badge peringatan bentrok jadwal otomatis dari `ScheduleConflictDetector`, status masa berlaku **24 jam**, serta tombol **"Setujui Terpilih"** dan **"Tolak Semua"** (tanpa form edit inline di dalam kartu).
4. **Tab Settings Baru (`/settings/ai`) & Preferensi Notifikasi Outbound:**
    - Menyediakan tab Settings baru **`/settings/ai`** untuk mengatur `users.ai_preferences` (gaya respons `ringkas`/`seimbang`/`mendetail`, jam belajar favorit, durasi blok fokus default `30`/`45`/`60`/`90` menit, dan instruksi singkat maks 300 karakter) serta kontrol opt-in/opt-out dan jam kustom `users.notification_preferences` (Ringkasan Harian, Ringkasan Mingguan, Persiapan Kegiatan H-15 menit).
