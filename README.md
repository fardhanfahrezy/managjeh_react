# Managjeh — Personal OS Mahasiswa

**Managjeh** adalah aplikasi web **Personal OS Mahasiswa** (_single-user sekarang, arsitektur siap multi-user_) berbasis **Laravel 13**, **Inertia.js v3**, **React 19**, **TypeScript**, **Tailwind CSS v4**, dan **PostgreSQL** untuk mengelola **Tasks (termasuk subtugas 1 tingkat)**, **Projects**, **Calendar (termasuk agenda berulang RFC 5545 ringkas)**, **Dashboard terpadu**, **Pengingat WhatsApp (Fonnte / HTTP Gateway)**, serta **AI Planner harian** dengan rantai fallback multi-provider (**Gemini → Grok → OpenRouter → Rule-based Fallback**).

---

## 1. Project Overview

Mahasiswa sering harus menyeimbangkan jadwal kuliah, tugas individu/kelompok, proyek akademik maupun kepanitiaan, serta tenggat waktu yang saling beririsan. **Managjeh** menyatukan manajemen tugas, proyek, dan kalender internal ke dalam satu pusat kendali yang tenang (**Design System: "The Quiet Desk"**), dilengkapi dua mekanisme proaktif:

1. **AI Planner Berjenjang yang Menghormati Kendali Pengguna:** Menganalisis tugas aktif, prioritas, tenggat, agenda kalender, dan slot waktu kosong untuk menyarankan maksimal **3 prioritas utama harian** serta rekomendasi jadwal pengingat tanpa pernah mengubah data pengguna secara sepihak.
2. **Pengingat WhatsApp Satu Arah dengan Perlindungan Privasi & Jam Tenang:** Mengirim kode OTP verifikasi dan pengingat tugas/agenda (`before_24h`, `before_1h`, `event_start`, `ai_recommended`) ke nomor WhatsApp terverifikasi, dengan penundaan otomatis selama _quiet hours_ (`00:00–05:00` waktu lokal pengguna).

---

## 2. Project Goals & Core Principles

1. **Privasi & Isolasi Kepemilikan Data (`user_id`):** Seluruh tabel domain memiliki `user_id` dan dilindungi oleh Laravel Policy (`ProjectPolicy`, `TaskPolicy`, `CalendarEventPolicy`) serta validasi kepemilikan lintas-relasi.
2. **Registrasi Publik Tertutup (`D-01`):** Pembuatan akun hanya dapat dilakukan melalui CLI provisioning (`php artisan user:provision`).
3. **AI sebagai Pendamping, Bukan Pengambil Keputusan (`D-12`):** AI Planner hanya memberikan rekomendasi; pembuatan pengingat dari rekomendasi AI membutuhkan klik persetujuan eksplisit pengguna (`POST /ai-planner/reminders/approve`).
4. **Persetujuan & Verifikasi WhatsApp (`D-14`):** Pengiriman pesan pengingat hanya aktif apabila `whatsapp_enabled = true`, `whatsapp_number` terisi, dan `whatsapp_verified_at !== null` (`User::canReceiveWhatsAppReminders()`).
5. **Minimisasi Data, Ekspor & Retensi (`D-11`, `D-15`):** Kredensial, token API, password, dan kode OTP tidak pernah diekspos di log, prompt AI, maupun file ekspor JSON. Data _soft-deleted_ dan log lama (> 30 hari) dibersihkan secara terjadwal.

---

## 3. Key Features (MVP 1 — Phase 0 s.d. Phase 7)

- **Authentication & Security (Fortify):** Login email/password, Two-Factor Authentication (TOTP + recovery codes), WebAuthn Passkeys, konfirmasi password, dan CLI user provisioning (`D-01`).
- **Task Management (`/tasks`):** CRUD tugas, filter status (`todo`, `in_progress`, `completed`, `cancelled`), prioritas (`low`, `medium`, `high`, `urgent`), proyek, tenggat waktu (`due_at` dikonversi otomatis antara `users.timezone` dan UTC), serta subtugas maksimal 1 tingkat (`D-09`).
- **Project Management (`/projects`, `/projects/{project}`):** CRUD proyek (`active`, `completed`, `archived`), rentang tanggal (`start_date`–`due_date`), kalkulasi progres otomatis (`completed / non-cancelled`), dan daftar tugas per proyek.
- **Calendar & Recurring Events (`/calendar`):** Tampilan `Day`, `Week`, dan `Month` sesuai `users.timezone`, mendukung agenda berjam (`is_all_day = false`, UTC) dan seharian (`is_all_day = true`, tanggal lokal), aturan berulang (`DAILY`, `WEEKLY`, `MONTHLY`), edit/hapus satu kejadian (_single occurrence override_ / `recurrence_exceptions`) atau seluruh rangkaian (`D-08`), integrasi indikator deadline tugas, serta deteksi konflik jadwal non-blocking.
- **Dashboard (`/dashboard`):** 8 bagian ringkasan terpadu (sapaan & tanggal lokal, 3 prioritas AI hari ini, tugas hari ini, tenggat terdekat/terlambat, agenda hari ini, progres proyek aktif, status pengingat WhatsApp, dan metrik penyelesaian tugas).
- **WhatsApp Reminder & OTP Verification:** Adapter `WhatsAppGatewayInterface` (`FonnteWhatsAppGateway`, `HttpWhatsAppGateway`, `NullWhatsAppGateway`), verifikasi OTP 6 digit via cache hash (TTL 5 menit), penjadwalan pengingat otomatis (`ReminderSynchronizer`), penyesuaian jam tenang (`QuietHoursAdjuster`), dan pengiriman antrean idempotent (`SendWhatsAppMessageJob`).
- **AI Planner (`/ai-planner`):** Orkestrasi `GeminiPlannerProvider` → `GrokPlannerProvider` → `OpenRouterPlannerProvider` → `FallbackPlannerProvider` dengan _circuit breaker_ berbasis cache, sanitasi prompt (`AiPlannerContext`), persetujuan rekomendasi pengingat 1 klik, serta riwayat eksekusi.
- **Data Export, Rate Limiting & Retention (`/settings/profile`):** Unduh seluruh data pengguna dalam format JSON (`GET /settings/profile/export`), umpan balik _cooldown_ inline saat terkena HTTP `429`, dan command pembersihan retensi `php artisan app:prune-retention-data`.

---

## 4. Current Implementation Status (`FACT`)

### 4.1 Status Phase Pengembangan (Phase 0 – Phase 7)

| Phase       | Cakupan                                           | Status Aktual              | Verifikasi                                                                                          |
| :---------- | :------------------------------------------------ | :------------------------- | :-------------------------------------------------------------------------------------------------- |
| **Phase 0** | Repository Audit & Baseline                       | **Implemented & Verified** | Spesifikasi teknis MVP 1 & keputusan `D-01`–`D-15` terkunci.                                        |
| **Phase 1** | Core Data (`users`, `projects`, `tasks`) & Auth   | **Implemented & Verified** | Migrasi, Model, Enum, Policy, FormRequest, `user:provision`, unit/feature tests.                    |
| **Phase 2** | Task & Project UI + Settings Timezone/WA          | **Implemented & Verified** | `ProjectController`, `TaskController`, halaman `tasks/*`, `projects/*`, `settings/profile.tsx`.     |
| **Phase 3** | Calendar & Agenda Berulang (`D-08`)               | **Implemented & Verified** | Migrasi `calendar_events`, `RecurrenceExpanderService`, `ScheduleConflictDetector`, UI `/calendar`. |
| **Phase 4** | Dashboard Aggregation                             | **Implemented & Verified** | `DashboardAggregatorService`, `DashboardController`, UI `/dashboard` 8 komponen.                    |
| **Phase 5** | WhatsApp Reminder & OTP (`D-02`, `D-05`–`D-07`)   | **Implemented & Verified** | Migrasi `reminders`, `FonnteWhatsAppGateway` (teruji live), `QuietHoursAdjuster`, OTP & Queue Job.  |
| **Phase 6** | AI Planner (`Gemini → Grok → OpenRouter → Rule`)  | **Implemented & Verified** | Migrasi `ai_planner_runs`, 4 provider adapters (teruji live), circuit breaker, UI `/ai-planner`.    |
| **Phase 7** | Hardening, Retensi (`D-11`, `D-15`) & Data Export | **Implemented & Verified** | `PruneRetentionDataCommand`, rate limiters + UI cooldown `429`, JSON export, `82` Pest tests lulus. |

### 4.2 Klasifikasi Modul Domain

| Modul                                                 | Status Klasifikasi            | Keterangan                                                                  |
| :---------------------------------------------------- | :---------------------------- | :-------------------------------------------------------------------------- |
| Authentication, 2FA TOTP, Passkeys, User Provisioning | **Implemented and verified**  | Fortify + WebAuthn Passkeys + `php artisan user:provision`.                 |
| Dashboard (`/dashboard`)                              | **Implemented and verified**  | 8 bagian agregasi lintas modul.                                             |
| Tasks & 1-Level Subtasks (`/tasks`)                   | **Implemented and verified**  | Mendukung filter, subtugas 1 tingkat (`D-09`), sinkronisasi pengingat.      |
| Projects (`/projects`, `/projects/{project}`)         | **Implemented and verified**  | Kalkulasi progres otomatis & isolasi kepemilikan.                           |
| Calendar & Recurring Events (`/calendar`)             | **Implemented and verified**  | Day/Week/Month view, recurrence `D-08`, deteksi konflik.                    |
| WhatsApp Integration & OTP Verification               | **Implemented and verified**  | Driver `fonnte`, `http`, `null`; OTP hash cache; quiet hours `00:00–05:00`. |
| AI Planner (`/ai-planner`)                            | **Implemented and verified**  | `Gemini` → `Grok` → `OpenRouter` → `Fallback`, circuit breaker, approval.   |
| Settings, Data Export (JSON), Retention & Cleanup     | **Implemented and verified**  | `/settings/profile`, `/settings/security`, `/settings/appearance`, export.  |
| Focus/Goals, Habits/Health, Finance/Budgets/Bills     | **Planned / Not implemented** | Di luar cakupan MVP 1 (lihat [docs/PRD.md](docs/PRD.md) Section 1.2).       |
| Learning, Career, Relationships, Life Admin, Journal  | **Planned / Not implemented** | Di luar cakupan MVP 1 (lihat [docs/PRD.md](docs/PRD.md) Section 1.2).       |
| External Google Calendar Sync & Two-Way WhatsApp Bot  | **Planned / Not implemented** | Di luar cakupan MVP 1 (`D-02`, `D-10`).                                     |

---

## 5. Technology Stack (`FACT`)

- **Backend:** PHP `^8.3` (teruji pada PHP `8.5.12`), Laravel Framework `^13.17` (`13.42.0`), Inertia.js Laravel `^3.0` (`3.7.0`), Laravel Fortify `^1.37.2` (`1.42.0`), Laravel Wayfinder `^0.1.14` (`0.1.23`), Laravel Passkeys (`@laravel/passkeys` `^0.2.0`).
- **Database:** PostgreSQL (`DB_CONNECTION=pgsql`, database utama `managjeh`, database pengujian `managjeh_test`).
- **Frontend:** React `^19.2.0` (`babel-plugin-react-compiler` `^1.0.0`), `@inertiajs/react` `^3.0.0`, TypeScript `^5.7.2`, Tailwind CSS `^4.0.0` (`@tailwindcss/vite` `^4.1.11`), Radix UI primitives, Lucide React `^0.475.0`, Sonner `^2.0.0`.
- **Build & Code Quality Tooling:** Vite+ (`vite-plus` `1.4.0` / `vp`), Pest `^5.3` (`5.3.5`), Larastan/PHPStan `^3.9` (`3.15.0`, level 7), Laravel Pint `^1.27` (`1.32.3`).

---

## 6. Architecture Overview

Managjeh menggunakan arsitektur monolit modular berbasis **Laravel 13 + Inertia.js v3**:

- **HTTP Layer (`app/Http/Controllers/`, `app/Http/Requests/`, `app/Policies/`):** Seluruh request divalidasi oleh FormRequest, diotorisasi melalui Policy (`user_id`), dan merender halaman React melalui `Inertia::render()` atau mengembalikan redirect dengan flash toast.
- **Service Layer (`app/Services/`):**
    - `Calendar\RecurrenceExpanderService` & `Calendar\ScheduleConflictDetector`
    - `DashboardAggregatorService`
    - `Reminder\QuietHoursAdjuster` & `Reminder\ReminderSynchronizer`
    - `WhatsApp\FonnteWhatsAppGateway`, `HttpWhatsAppGateway`, `NullWhatsAppGateway` (mengimplementasikan `WhatsAppGatewayInterface`)
    - `AiPlanner\AiPlannerService`, `AiPromptBuilder`, `GeminiPlannerProvider`, `GrokPlannerProvider`, `OpenRouterPlannerProvider`, `FallbackPlannerProvider` (mengimplementasikan `AiPlannerProviderInterface`)
- **Async & Scheduled Layer (`app/Jobs/`, `app/Console/Commands/`, `routes/console.php`):**
    - `reminders:dispatch-due` (setiap menit) → mendispatch `SendWhatsAppMessageJob`.
    - `ai-planner:generate-daily` (setiap hari pukul `05:30`).
    - `app:prune-retention-data` (setiap hari pukul `02:30`).

Lihat detail lengkap di [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md).

---

## 7. Repository Structure

```text
.
├── app/
│   ├── Actions/Fortify/         # Action autentikasi & reset password Fortify
│   ├── Concerns/                # Trait validasi password & profil
│   ├── Console/Commands/        # user:provision, reminders:dispatch-due, ai-planner:generate-daily, app:prune-retention-data
│   ├── Contracts/               # AiPlannerProviderInterface, WhatsAppGatewayInterface
│   ├── Enums/                   # ProjectStatus, TaskStatus, TaskPriority, ReminderStatus, AiPlannerRunStatus
│   ├── Exceptions/              # AiProviderException
│   ├── Http/
│   │   ├── Controllers/         # Dashboard, Project, Task, CalendarEvent, AiPlanner, Settings/*
│   │   ├── Middleware/          # HandleAppearance, HandleInertiaRequests
│   │   └── Requests/            # FormRequest terpisah untuk Projects, Tasks, Calendar, Settings
│   ├── Jobs/                    # SendWhatsAppMessageJob
│   ├── Models/                  # User, Project, Task, CalendarEvent, Reminder, AiPlannerRun
│   ├── Policies/                # ProjectPolicy, TaskPolicy, CalendarEventPolicy
│   ├── Providers/               # AppServiceProvider (DI bindings & RateLimiters), FortifyServiceProvider
│   └── Services/                # AiPlanner/*, Calendar/*, Reminder/*, WhatsApp/*, DashboardAggregatorService
├── config/                      # Konfigurasi Laravel (termasuk services.php & fortify.php)
├── database/
│   ├── factories/               # Factory untuk User, Project, Task, CalendarEvent, Reminder, AiPlannerRun
│   ├── migrations/              # 11 file migrasi PostgreSQL
│   └── seeders/                 # DatabaseSeeder
├── docs/                        # Dokumentasi teknis lengkap (PRD, Schema, Business Rules, API, dll.)
├── resources/
│   ├── css/app.css              # Design tokens Tailwind v4 ("The Quiet Desk") & font Inter lokal
│   └── js/
│       ├── actions/             # Auto-generated oleh Laravel Wayfinder (jangan diedit manual)
│       ├── components/          # Komponen UI Radix, layout sidebar, form dialog task
│       ├── hooks/               # Custom hooks (use-rate-limit-cooldown, use-flash-toast, dll.)
│       ├── layouts/             # AppLayout, AuthLayout, SettingsLayout
│       ├── pages/               # Halaman Inertia (dashboard, tasks, projects, calendar, ai-planner, settings, auth)
│       ├── routes/              # Auto-generated oleh Laravel Wayfinder (jangan diedit manual)
│       └── types/               # Definisi tipe TypeScript (auth.ts, models.ts, index.ts)
├── routes/
│   ├── console.php              # Jadwal cron Laravel Scheduler
│   ├── settings.php             # Route pengaturan profil, keamanan, OTP WhatsApp, dan ekspor data
│   └── web.php                  # Route utama aplikasi
└── tests/
    ├── Feature/                 # 17 file Feature test Pest (Auth, CoreData, CRUD, Calendar, Dashboard, WA, AI, Hardening)
    └── Unit/                    # Unit test
```

---

## 8. Requirements and Prerequisites

- **PHP:** `>= 8.3` dengan ekstensi `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `intl`, `bcmath`.
- **Composer:** `>= 2.7`
- **Node.js & npm:** Node.js `>= 22.x` & npm `>= 10.x`
- **Database:** PostgreSQL `>= 16` (berjalan di `127.0.0.1:5432`)

---

## 9. Installation

```bash
# 1. Clone repository & masuk ke direktori proyek
git clone https://github.com/fardhanfahrezy/managjeh_react.git
cd managjeh_react

# 2. Install dependency PHP & JavaScript
composer install
npm install

# 3. Salin file environment & generate application key
cp .env.example .env
php artisan key:generate
```

Atau jalankan skrip setup satu langkah:

```bash
composer setup
```

---

## 10. Environment Configuration

Buka file `.env` dan sesuaikan konfigurasi database, AI provider, serta WhatsApp gateway (gunakan nilai milik Anda sendiri, jangan pernah commit `.env`):

```dotenv
APP_NAME="Managjeh"
APP_ENV=local
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=managjeh
DB_USERNAME=postgres
DB_PASSWORD=your_postgres_password

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

# AI Planner Providers (Gemini -> Grok -> OpenRouter -> Fallback)
GEMINI_API_KEY=your_gemini_api_key
GEMINI_MODEL=gemini-flash-latest
XAI_API_KEY=your_xai_grok_api_key
GROK_MODEL=grok-3-mini
OPENROUTER_API_KEY=your_openrouter_api_key
OPENROUTER_MODEL=google/gemini-3.8-flash
AI_PLANNER_TIMEOUT=20

# WhatsApp Gateway (fonnte | http | null)
WHATSAPP_DRIVER=fonnte
FONNTE_TOKEN=your_fonnte_device_token
```

---

## 11. Database Setup & User Provisioning (`D-01`)

1. Buat dua database PostgreSQL (`managjeh` untuk aplikasi dan `managjeh_test` untuk pengujian):
    ```sql
    CREATE DATABASE managjeh;
    CREATE DATABASE managjeh_test;
    ```
2. Jalankan migrasi:
    ```bash
    php artisan migrate
    ```
3. Buat akun pengguna melalui CLI (**registrasi publik `/register` dinonaktifkan sesuai `D-01`**):
    ```bash
    php artisan user:provision \
      --name="Nama Mahasiswa" \
      --email="mahasiswa@example.com" \
      --password="PasswordKuat#2026!" \
      --timezone="Asia/Jakarta"
    ```

---

## 12. Running Development Environment

```bash
# Menjalankan Laravel server, Queue listener, Pail log, dan Vite dev server secara bersamaan:
composer dev

# Atau jalankan terpisah:
php artisan serve
npm run dev
php artisan queue:listen --tries=3
```

Jika Anda menambahkan atau mengubah route/controller Laravel, regenerasi helper TypeScript Wayfinder dengan:

```bash
php artisan wayfinder:generate --with-form
```

---

## 13. Testing

Pengujian otomatis menggunakan **Pest 5** dan database PostgreSQL terpisah (`managjeh_test` sebagaimana diatur pada `phpunit.xml` dengan `WHATSAPP_DRIVER=null` dan `QUEUE_CONNECTION=sync`):

```bash
# Menjalankan seluruh test suite (Unit + Feature)
php artisan test

# Menjalankan satu file test spesifik
php artisan test tests/Feature/Phase7HardeningTest.php

# Menjalankan rangkaian lengkap (config:clear + Pint check + PHPStan + Pest)
composer test
```

---

## 14. Code Quality Checks

```bash
# 1. Format kode PHP dengan Laravel Pint
php vendor/bin/pint --dirty --format agent

# 2. Static analysis PHPStan / Larastan (Level 7)
php vendor/bin/phpstan analyse --memory-limit=512M

# 3. Pemeriksaan TypeScript
npm run types:check

# 4. Pemeriksaan formatting & linting frontend/markdown dengan Vite+ (vp)
npm run check

# 5. Memperbaiki formatting otomatis dengan Vite+ (vp)
npm run check:fix

# 6. Menjalankan seluruh pemeriksaan CI sekaligus (persis seperti GitHub Actions)
composer ci:check
```

---

## 15. AI Provider Configuration (`D-03`, `D-04`, `D-13`)

`AiPlannerService` memanggil provider secara berurutan:

1. **Google Gemini (`GeminiPlannerProvider`):** Menggunakan `GEMINI_API_KEY`, `GEMINI_MODEL` (default `gemini-flash-latest`), dan `GEMINI_ENDPOINT`.
2. **xAI Grok (`GrokPlannerProvider`):** Menggunakan `GROK_API_KEY` (atau `XAI_API_KEY`), `GROK_MODEL` (default `grok-3-mini`), dan `GROK_ENDPOINT`.
3. **OpenRouter (`OpenRouterPlannerProvider`):** Menggunakan `OPENROUTER_API_KEY`, `OPENROUTER_MODEL` (default `google/gemini-3.8-flash`), dan `OPENROUTER_ENDPOINT`.
4. **Deterministic Rule-Based (`FallbackPlannerProvider`):** Berjalan secara lokal tanpa koneksi eksternal apabila ketiga provider LLM di atas gagal, melewati batas waktu (`AI_PLANNER_TIMEOUT`), terkena rate limit, atau belum dikonfigurasi.

> **Penting:** Status `success` pada `ai_planner_runs` dengan `provider = 'fallback'` menandakan rantai fallback deterministik berhasil menyelamatkan pembuatan rencana harian, **bukan** menandakan koneksi API LLM eksternal berhasil. Periksa kolom `provider` dan `model` pada halaman `/ai-planner` atau tabel `ai_planner_runs` untuk memverifikasi provider mana yang merespons.

---

## 16. WhatsApp Integration (`D-02`, `D-05`–`D-07`, `D-14`)

Binding `WhatsAppGatewayInterface` di `AppServiceProvider` mendukung 3 driver (`WHATSAPP_DRIVER`):

- `fonnte`: Mengirim pesan dan OTP melalui `FonnteWhatsAppGateway` (`https://api.fonnte.com/send`) menggunakan header `Authorization: <FONNTE_TOKEN>`.Jika `WHATSAPP_DRIVER` tidak diset tetapi `FONNTE_TOKEN` terisi, aplikasi otomatis memilih driver `fonnte`.
- `http`: Mengirim request JSON Bearer token ke gateway generik (`WHATSAPP_API_ENDPOINT` & `WHATSAPP_API_TOKEN`) melalui `HttpWhatsAppGateway`.
- `null`: Driver aman tanpa panggilan jaringan (`NullWhatsAppGateway`), digunakan pada pengujian otomatis (`phpunit.xml`) atau pengembangan lokal tanpa token.

Alur aktivasi di UI:

1. Buka `/settings/profile`, isi **Nomor WhatsApp** (format internasional, mis. `+6281234567890`), centang **Aktifkan Pengingat WhatsApp**, lalu klik **Save**.
2. Klik **Kirim Kode OTP**, masukkan 6 digit OTP yang diterima di WhatsApp, lalu klik **Verifikasi OTP**.

---

## 17. Scheduler and Background Processes

Di lingkungan produksi maupun pengembangan yang membutuhkan otomatisasi penuh, jalankan **Queue Worker** dan **Scheduler**:

```bash
# Menjalankan Queue Worker untuk memproses pengiriman WhatsApp
php artisan queue:work database --tries=3 --backoff=15,60,180 --timeout=30

# Menjalankan Scheduler secara lokal
php artisan schedule:work
```

Daftar jadwal aktif di `routes/console.php`:

- `reminders:dispatch-due` — **Setiap menit**: mengklaim reminder `pending` yang jatuh tempo (`scheduled_at <= now()`) menjadi `processing` dan mendispatch `SendWhatsAppMessageJob`.
- `ai-planner:generate-daily` — **Setiap hari pukul `05:30` UTC**: membuat rencana prioritas harian otomatis untuk pengguna yang belum memiliki rencana sukses pada tanggal lokalnya.
- `app:prune-retention-data` — **Setiap hari pukul `02:30` UTC**: menghapus permanen (`forceDelete`) data _soft-deleted_ (`tasks`, `calendar_events`, `projects`) serta memangkas log `reminders` terminal dan `ai_planner_runs` yang berusia > 30 hari.

---

## 18. Data Export and Privacy (`D-11`, `D-15`)

- **Ekspor Data JSON:** Pengguna dapat mengunduh seluruh datanya melalui tombol **Unduh Data (JSON)** di bagian **Data & Privasi** pada halaman `/settings/profile` (`GET /settings/profile/export`, dibatasi `throttle:data-export` 5x/menit). File JSON hanya memuat field yang di-whitelist (`user`, `projects`, `tasks`, `calendar_events`, `reminders`, `ai_planner_runs`) tanpa `password`, `two_factor_secret`, `remember_token`, OTP, maupun token API.
- **Penghapusan Akun:** Pengguna dapat menghapus akunnya melalui bagian **Delete account** di `/settings/profile` dengan konfirmasi password; seluruh data terkait otomatis terhapus melalui `cascadeOnDelete()` di PostgreSQL.

---

## 19. Deployment Guide

Lihat panduan lengkap di [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Ringkasan langkah produksi:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Pastikan HTTPS aktif (wajib untuk WebAuthn Passkeys), supervisor mengelola `php artisan queue:work`, dan cron menjalankan `* * * * * php artisan schedule:run`.

---

## 20. Troubleshooting

- **Error `Unable to locate file in Vite manifest`:** Jalankan `npm run build` atau `npm run dev`.
- **Gagal format/check di CI (`vp check`):** Jalankan `npm run check:fix` (`npx vp check --fix`) sebelum melakukan commit perubahan di `resources/js/` atau `docs/`.
- **Error TypeScript pada `.form()` Wayfinder:** Jalankan `php artisan wayfinder:generate --with-form` agar varian form ikut ter-generate.
- **Pengingat WhatsApp tidak terkirim:** Pastikan (1) `whatsapp_enabled = true` dan nomor sudah berstatus `Terverifikasi` di `/settings/profile`, (2) waktu saat ini tidak berada di dalam rentang Jam Tenang (`00:00–05:00`), serta (3) `php artisan reminders:dispatch-due` dan `php artisan queue:work` berjalan.
- **AI Planner selalu menggunakan `Fallback Deterministik`:** Periksa apakah `GEMINI_API_KEY`, `XAI_API_KEY`, atau `OPENROUTER_API_KEY` sudah diisi di `.env`, lalu jalankan `php artisan config:clear`. Jika sebuah provider gagal 3 kali berturut-turut dalam 5 menit, _circuit breaker_ akan melewati provider tersebut selama 5 menit.

---

## 21. Security Notes

- Jangan pernah melakukan commit file `.env` atau menyalin kredensial asli ke dalam dokumentasi/test.
- Setiap endpoint yang memodifikasi data wajib menggunakan FormRequest dan memeriksa kepemilikan melalui Policy (`$this->authorize(...)`).
- Kode OTP WhatsApp disimpan hanya dalam bentuk hash SHA-256 di cache selama 5 menit dengan maksimal 5 percobaan verifikasi.
- Lihat dokumentasi keamanan lengkap di [docs/SECURITY.md](docs/SECURITY.md).

---

## 22. Contributing & Documentation Index

Sebelum membuat perubahan kode, baca panduan arsitektur dan konvensi proyek:

1. [AGENTS.md](AGENTS.md) — Instruksi utama, konvensi arsitektur, dan aturan wajib untuk AI Coding Agent.
2. [CLAUDE.md](CLAUDE.md) — Ringkasan orientasi cepat untuk Claude Code.
3. [DESIGN.md](DESIGN.md) & [PRODUCT.md](PRODUCT.md) — Spesifikasi Design System **"The Quiet Desk"** dan konteks produk.
4. [docs/PRD.md](docs/PRD.md) — Spesifikasi Teknis MVP 1, Keputusan `D-01`–`D-15`, & Riwayat Phase 0–7.
5. [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — Dokumentasi lengkap 11 migrasi PostgreSQL, indeks, FK, dan retensi data.
6. [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — Aturan bisnis terverifikasi untuk seluruh modul MVP 1.
7. [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) — Arsitektur berlapis Laravel 13 + Inertia v3 + Queue/Scheduler.
8. [docs/FRONTEND_ARCHITECTURE.md](docs/FRONTEND_ARCHITECTURE.md) — Arsitektur React 19, kontrak TypeScript `models.ts`, dan spesifikasi halaman.
9. [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md) — Daftar lengkap 27 route aplikasi, kontrak `WhatsAppGatewayInterface` & `AiPlannerProviderInterface`.
10. [docs/USER_FLOW.md](docs/USER_FLOW.md) — Diagram alur pengguna & background jobs.
11. [docs/SECURITY.md](docs/SECURITY.md) — Dokumentasi keamanan, sanitasi prompt AI, rate limiting, dan privasi data.
12. [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Panduan deployment produksi, Queue Worker, Scheduler, & CI/CD.
13. [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) — Referensi implementasi Phase 0–7 & playbook pengembangan lanjutan untuk AI Agent.
