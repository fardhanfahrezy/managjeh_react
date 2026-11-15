# Managjeh — Personal OS Mahasiswa (MVP 1)

**Managjeh** adalah aplikasi web **Personal OS Mahasiswa** berbasis **Laravel 13**, **Inertia.js v3**, **React 19**, **TypeScript**, **Tailwind CSS v4**, dan **PostgreSQL** untuk mengelola ** tugas (Tasks)**, **proyek (Projects)**, **agenda kalender & jadwal berulang (Calendar)**, **ringkasan aktivitas (Dashboard)**, **pengingat WhatsApp**, serta **rekomendasi 3 prioritas harian dari AI Planner**.

---

## Keputusan Arsitektur Utama (`MVP 1` + `D-01` s.d. `D-15`)

| Area | Keputusan Final |
| :--- | :--- |
| **Model Pengguna** | Single-user sekarang, arsitektur siap multi-user (setiap tabel domain memiliki `user_id` & Policy) |
| **Registrasi (`D-01`)** | Registrasi publik **dinonaktifkan**. Akun dibuat melalui CLI `php artisan user:provision` |
| **Penamaan Database** | Bahasa Inggris, `snake_case` (`users`, `projects`, `tasks`, `calendar_events`, `reminders`, `ai_planner_runs`) |
| **Timezone** | Disimpan dalam **UTC** di PostgreSQL; tampilan & evaluasi mengikuti `users.timezone` (IANA, default `Asia/Jakarta`) |
| **Subtugas (`D-09`)** | Maksimal **1 tingkat** subtugas (`tasks.parent_id`), divalidasi di backend |
| **Agenda Berulang (`D-08`)** | Masuk MVP (`calendar_events` mendukung recurrence rule, edit/batal 1 kejadian atau seluruh rangkaian) |
| **Quiet Hours (`D-06`)** | Default pukul **`00:00–05:00`** waktu lokal pengguna; pengingat pada periode ini digeser ke `05:00` |
| **WhatsApp (`D-02`, `D-14`)** | Kanal pengiriman notifikasi via `WhatsAppGatewayInterface` (adapter); wajib verifikasi nomor/OTP & `whatsapp_enabled = true` |
| **AI Planner (`D-03`, `D-04`, `D-12`)** | Urutan fallback: **Gemini → Grok → OpenRouter → Rule-based Fallback**; model configurable; berjalan otomatis & manual |
| **Soft Delete & Retensi (`D-11`, `D-15`)** | Soft delete (`deleted_at`) untuk data operasional; minimisasi data log AI/WA dengan retensi terkontrol |

---

## Status Implementasi Saat Ini (`FACT` per Phase)

| Phase | Modul / Cakupan | Status Aktual (`FACT`) | Keterangan |
| :--- | :--- | :--- | :--- |
| **Phase 0** | Repository Audit | `Implemented` | Audit kode aktual & penyelarasan spesifikasi teknis MVP 1 (`D-01`–`D-15`). |
| **Phase 1** | Core Data (`users`, `projects`, `tasks`) | `Implemented` | Migrasi, Model, Enum, Factory, Policy, FormRequest, `user:provision`, & **57 Pest tests lulus**. |
| **Phase 2** | Task & Project UI + Settings | `Not Implemented` (`Planned`) | Controller, route, dan halaman React untuk Tasks, Projects, serta pengaturan Timezone/WhatsApp. |
| **Phase 3** | Calendar & Recurrence (`D-08`) | `Not Implemented` (`Planned`) | Migrasi `calendar_events`, ekspansi recurrence idempotent, CRUD agenda berjam & *all-day*, integrasi deadline task. |
| **Phase 4** | Dashboard Aggregation | `Partially Implemented` | Route `/dashboard` tersedia; perlu `DashboardController` dan UI 8 kartu ringkasan. |
| **Phase 5** | WhatsApp Reminder (`D-02`, `D-05`–`D-07`, `D-14`) | `Not Implemented` (`Planned`) | Migrasi `reminders`, `WhatsAppGatewayInterface`, verifikasi OTP, Scheduler per menit, Queue Job idempotent. |
| **Phase 6** | AI Planner (`D-03`, `D-04`, `D-12`, `D-13`) | `Not Implemented` (`Planned`) | Migrasi `ai_planner_runs`, `AiPlannerProviderInterface` (`Gemini` → `Grok` → `OpenRouter` → `Fallback`), validasi schema JSON, UI AI Planner. |
| **Phase 7** | Hardening & Retensi (`D-11`, `D-15`) | `Not Implemented` (`Planned`) | Pembersihan retensi terjadwal, rate limiting, ekspor data, dan regresi menyeluruh. |

---

## Teknologi yang Digunakan (`FACT`)

- **Backend:** PHP `^8.3` (`8.5.12`), Laravel `13.42.0`, Inertia.js Laravel `3.7.0`, Laravel Fortify `1.42.0` (2FA TOTP & Passkeys/WebAuthn), Laravel Wayfinder `0.1.23`, PostgreSQL `18.6`.
- **Frontend:** React `19.2.0`, `@inertiajs/react` `^3.0.0`, TypeScript `^5.7.2`, Tailwind CSS `v4`, Radix UI primitives, Lucide React icons, Sonner toast, Vite+ (`vp` `1.4.0`).
- **Quality & Testing:** Pest `5.3.5`, Larastan/PHPStan `3.15.0` (level 7), Laravel Pint `1.32.3`, TypeScript `tsc --noEmit`.

---

## Panduan Instalasi & Provisioning Pengguna (`D-01`)

1. Pastikan PostgreSQL berjalan dan buat dua database:
    - `managjeh` (aplikasi)
    - `managjeh_test` (pengujian otomatis)
2. Jalankan setup:
    ```bash
    composer install
    cp .env.example .env
    php artisan key:generate
    php artisan migrate
    npm install
    npm run build
    ```
3. **Buat akun pengguna melalui CLI Provisioning (`D-01` — registrasi publik dinonaktifkan):**
    ```bash
    php artisan user:provision --name="Nama Mahasiswa" --email="mahasiswa@example.com" --password="PasswordKuat#2026!" --timezone="Asia/Jakarta"
    ```

---

## Daftar Perintah Pengembangan & Pengujian

| Tujuan | Perintah |
| :--- | :--- |
| Menjalankan dev server Laravel + Vite | `composer dev` |
| Membuat/memperbarui akun pengguna (`D-01`) | `php artisan user:provision` |
| Menjalankan seluruh test Pest | `php artisan test` |
| Static analysis PHPStan (level 7) | `php vendor/bin/phpstan analyse --memory-limit=512M` |
| Format kode PHP dengan Pint | `php vendor/bin/pint --dirty --format agent` |
| Type-check TypeScript (`tsc --noEmit`) | `npx.cmd tsc --noEmit` (atau `npm run types:check`) |
| Lint & format check frontend (`vp check`) | `npm run check` |
| Build aset frontend produksi | `npm run build` |

---

## Dokumentasi Lengkap Proyek

- [AGENTS.md](AGENTS.md) — Panduan & aturan wajib seluruh AI Agent
- [CLAUDE.md](CLAUDE.md) — Instruksi ringkas untuk AI Coding Agent
- [docs/PRD.md](docs/PRD.md) — Technical Specification MVP 1, Keputusan `D-01`–`D-15`, Roadmap Phase 0–7, & Acceptance Criteria
- [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — Skema PostgreSQL aktual (Phase 1) & spesifikasi tabel Phase 3, 5, 6
- [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — Aturan bisnis Task, Project, Calendar, Quiet Hours, WhatsApp, & AI Planner
- [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) — Panduan implementasi detail Phase 2 s.d. Phase 7 (khusus untuk eksekusi AI Agent)
- [docs/FRONTEND_ARCHITECTURE.md](docs/FRONTEND_ARCHITECTURE.md) — Rancangan 8 halaman React Inertia, TypeScript types, & kontrak props
- [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md) — Route Inertia, `WhatsAppGatewayInterface`, & `AiPlannerProviderInterface`
- [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) — Arsitektur modular & diagram sistem
- [docs/USER_FLOW.md](docs/USER_FLOW.md) — Diagram alur pengguna & background jobs
- [docs/SECURITY.md](docs/SECURITY.md) — Otorisasi `user_id`, keamanan webhook, & privasi AI
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Panduan deployment, Queue Worker, & Scheduler
