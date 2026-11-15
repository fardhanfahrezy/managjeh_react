# CLAUDE.md

Instruksi kerja **Managjeh — Personal OS Mahasiswa (Technical Specification MVP 1)** untuk AI Coding Agent.

> **Single Source of Truth:** Sebelum memulai tugas apa pun, baca [AGENTS.md](AGENTS.md), [docs/PRD.md](docs/PRD.md), [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md), dan [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md). Seluruh aturan arsitektur, keputusan final `D-01` s.d. `D-15`, dan `<laravel-boost-guidelines>` terpusat di [AGENTS.md](AGENTS.md).

## 1. Gambaran Produk & Arsitektur (`FACT` + `REQUIREMENT`)

- **Produk:** Personal OS untuk mahasiswa (*single-user sekarang, arsitektur siap multi-user* dengan kepemilikan `user_id` di seluruh tabel).
- **Cakupan MVP 1:**
    1. **Task Management** (`tasks`: status `todo`/`in_progress`/`completed`/`cancelled`, prioritas `low`/`medium`/`high`/`urgent`, `due_at` UTC, `project_id`, `parent_id` maks. 1 level sesuai `D-09`, soft delete `D-11`).
    2. **Project Management** (`projects`: status `active`/`completed`/`archived`, `start_date`, `due_date`, kalkulasi progres otomatis dari task non-cancelled, soft delete `D-11`).
    3. **Calendar & Agenda Berulang** (`calendar_events`: agenda berjam dalam UTC, agenda *all-day* dalam `date` lokal, serta dukungan *recurrence rule*, override satu kejadian, dan exception sesuai `D-08`).
    4. **Dashboard** (`/dashboard`: greeting & tanggal lokal, ringkasan tugas hari ini, deadline terdekat, agenda hari ini, progres proyek aktif, 3 prioritas AI Planner, status WhatsApp, ringkasan tugas selesai).
    5. **WhatsApp Reminder** (`reminders`: kanal pengiriman pengingat satu arah melalui `WhatsAppGatewayInterface` `D-02`, verifikasi nomor OTP/gateway wajib `D-14`, multi-reminder per task `D-05` & `D-07`, quiet hours `00:00–05:00` waktu lokal pengguna `D-06`).
    6. **AI Planner** (`ai_planner_runs`: rekomendasi maksimal 3 prioritas harian dengan urutan fallback `Gemini` → `Grok` → `OpenRouter` → `Rule-based Fallback` sesuai `D-03` & `D-04`, dipicu otomatis via Scheduler/perubahan data relevan maupun manual sesuai `D-12`).
- **Di Luar Cakupan MVP (DILARANG DIBANGUN):** Keuangan/anggaran, habit tracker, mood/tidur, catatan kuliah, karier, relasi/kontak, jurnal mingguan, Google Calendar eksternal (`D-10`), serta perintah input/ubah data melalui balasan WhatsApp.

## 2. Status Implementasi Saat Ini (`FACT`)

- **Phase 0 (Audit) & Phase 1 (Core Data) — `Implemented`:**
    - Registrasi publik dinonaktifkan (`D-01`), akun dibuat via `php artisan user:provision`.
    - Tabel `users` (preferensi `timezone`, `whatsapp_number`, `whatsapp_verified_at`, `whatsapp_enabled`, `reminder_quiet_start = '00:00:00'`, `reminder_quiet_end = '05:00:00'`), `projects`, dan `tasks` sudah dimigrasikan.
    - Model `User`, `Project`, `Task`, Enum `ProjectStatus`, `TaskStatus`, `TaskPriority`, `ProjectPolicy`, `TaskPolicy`, dan FormRequest (`StoreProjectRequest`, `UpdateProjectRequest`, `StoreTaskRequest`, `UpdateTaskRequest`) sudah selesai beserta **57 Pest tests yang lulus** (`0` PHPStan error).
- **Phase 2 s.d. Phase 7 — `Planned` (Urutan Pengerjaan Berikutnya):**
    - **Phase 2:** Task & Project UI + Settings Timezone/WhatsApp (`ProjectController`, `TaskController`, halaman React `tasks/*`, `projects/*`, `settings/profile.tsx`).
    - **Phase 3:** Calendar & Recurrence (`calendar_events` migration, `CalendarEvent` model, `RecurrenceService`, `CalendarEventController`, halaman `calendar/index.tsx`).
    - **Phase 4:** Dashboard Aggregation (`DashboardController`, `DashboardAggregatorService`, halaman `dashboard.tsx`).
    - **Phase 5:** WhatsApp Reminder (`reminders` migration, `Reminder` model, `WhatsAppGatewayInterface`, `ReminderSchedulerService`, `SendWhatsAppMessageJob`, Scheduler per menit, OTP verification).
    - **Phase 6:** AI Planner (`ai_planner_runs` migration, `AiPlannerRun` model, `AiPlannerProviderInterface`, `Gemini`/`Grok`/`OpenRouter`/`Fallback` providers, `AiPlannerService`, halaman `ai-planner/index.tsx`).
    - **Phase 7:** Hardening (retensi log `D-15`, cleanup soft delete `D-11`, rate limiting, ekspor data, regresi penuh).

## 3. Dokumen Referensi di `docs/`

- [docs/PRD.md](docs/PRD.md) — Spesifikasi Teknis MVP 1 & Keputusan Final `D-01`–`D-15`
- [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — Skema PostgreSQL aktual (Phase 1) & target (Phase 3, 5, 6)
- [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — Aturan bisnis domain & validasi
- [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) — Panduan teknis langkah demi langkah per Phase untuk AI Agent
- [docs/FRONTEND_ARCHITECTURE.md](docs/FRONTEND_ARCHITECTURE.md) — Spesifikasi komponen React, TypeScript types, dan Inertia props
- [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md) — Route, kontrak `WhatsAppGatewayInterface`, dan kontrak `AiPlannerProviderInterface`
- [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md), [docs/USER_FLOW.md](docs/USER_FLOW.md), [docs/SECURITY.md](docs/SECURITY.md), [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)
