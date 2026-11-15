# Development Guidelines & Phase-by-Phase Implementation Cookbook — Managjeh (MVP 1)

Dokumen ini disusun secara spesifik dan terperinci sebagai **panduan kerja langkah demi langkah bagi AI Coding Agent** (termasuk model AI yang lebih ringan/hemat biaya) untuk menyelesaikan **Phase 2 s.d. Phase 7** di atas fondasi **Phase 1** yang sudah selesai.

---

## 1. Apa yang Sudah Ada di Repository (`FACT` — Jangan Buat Ulang!)

Sebelum menulis kode baru, pahami file-file Phase 0 & Phase 1 yang **sudah terimplementasi dan lulus test**:

- **Enums (`app/Enums/`):**
    - [ProjectStatus.php](../app/Enums/ProjectStatus.php): `Active = 'active'`, `Completed = 'completed'`, `Archived = 'archived'` (`ProjectStatus::values()`).
    - [TaskStatus.php](../app/Enums/TaskStatus.php): `Todo = 'todo'`, `InProgress = 'in_progress'`, `Completed = 'completed'`, `Cancelled = 'cancelled'` (`$status->isActive()`).
    - [TaskPriority.php](../app/Enums/TaskPriority.php): `Low = 'low'`, `Medium = 'medium'`, `High = 'high'`, `Urgent = 'urgent'`.
- **Models (`app/Models/`):**
    - [User.php](../app/Models/User.php): memiliki relasi `projects()`, `tasks()`, dan helper `canReceiveWhatsAppReminders(): bool`.
    - [Project.php](../app/Models/Project.php): memiliki `SoftDeletes`, relasi `user()`, `tasks()`, scope `withProgressCounts()`, dan method `calculateProgress(): int`.
    - [Task.php](../app/Models/Task.php): memiliki `SoftDeletes`, relasi `user()`, `project()`, `parent()`, `subtasks()`, scope `active()`, method `isActive(): bool`, method `dueAtInUserTimezone(?string $timezone = null)`, serta validasi otomatis kepemilikan lintas-user & kedalaman subtugas maks. 1 level (`D-09`).
- **Policies (`app/Policies/`):**
    - [ProjectPolicy.php](../app/Policies/ProjectPolicy.php) & [TaskPolicy.php](../app/Policies/TaskPolicy.php).
- **Form Requests (`app/Http/Requests/`):**
    - [StoreProjectRequest.php](../app/Http/Requests/Projects/StoreProjectRequest.php) & [UpdateProjectRequest.php](../app/Http/Requests/Projects/UpdateProjectRequest.php).
    - [StoreTaskRequest.php](../app/Http/Requests/Tasks/StoreTaskRequest.php) & [UpdateTaskRequest.php](../app/Http/Requests/Tasks/UpdateTaskRequest.php) — keduanya memiliki method `$request->normalizedValidated()` yang otomatis mengonversi `due_at` dari `$user->timezone` ke UTC!
- **CLI Provisioning (`D-01`):**
    - [ProvisionUserCommand.php](../app/Console/Commands/ProvisionUserCommand.php) (`php artisan user:provision`).

---

## 2. Panduan Eksekusi Detail Per Phase (Untuk AI Agent)

Kerjakan **satu phase pada satu waktu**, jalankan verifikasi di akhir setiap phase, dan jangan melompat ke phase berikutnya sebelum seluruh test hijau.

---

### PHASE 2 — Task & Project UI + Settings Timezone/WhatsApp

#### 2.1 Backend yang Harus Dibuat di Phase 2

1. **`app/Http/Controllers/ProjectController.php`:**
    - `index(Request $request)`: ambil `$request->user()->projects()->withProgressCounts()`, dukung filter `status` (`active`/`completed`/`archived`), urutkan berdasarkan `due_date` & `created_at`, kembalikan `Inertia::render('projects/index', [...])`.
    - `store(StoreProjectRequest $request)`: `$request->user()->projects()->create($request->validated())`, redirect dengan flash toast.
    - `show(Request $request, Project $project)`: `$this->authorize('view', $project)`, muat `$project->load(['tasks' => fn ($q) => $q->whereNull('parent_id')->with('subtasks')->orderBy('sort_order')])`, kembalikan `Inertia::render('projects/show', [...])`.
    - `update(UpdateProjectRequest $request, Project $project)`: `$project->update($request->validated())`, redirect dengan flash toast.
    - `destroy(Request $request, Project $project)`: `$this->authorize('delete', $project)`, `$project->delete()` (soft delete), redirect ke `projects.index`.
2. **`app/Http/Controllers/TaskController.php`:**
    - `index(Request $request)`: ambil tugas milik `$request->user()->id` (`whereNull('parent_id')->with(['project:id,name,status', 'subtasks'])`), dukung filter query `status`, `priority`, `project_id`, dan `due_date`, kembalikan `Inertia::render('tasks/index', [...])`.
    - `store(StoreTaskRequest $request)`: `$request->user()->tasks()->create($request->normalizedValidated())`, redirect back dengan flash toast.
    - `update(UpdateTaskRequest $request, Task $task)`: `$task->update($request->normalizedValidated())`, redirect back dengan flash toast.
    - `destroy(Request $request, Task $task)`: `$this->authorize('delete', $task)`, `$task->delete()`, redirect back.
3. **Routes di `routes/web.php`:**
    - Di dalam grup `Route::middleware(['auth', 'verified'])->group(...)`:
        ```php
        Route::resource('projects', ProjectController::class)->except(['create', 'edit']);
        Route::resource('tasks', TaskController::class)->except(['create', 'edit', 'show']);
        ```
    - Jalankan `php artisan wayfinder:generate` agar route helper TypeScript ter-generate di `resources/js/routes/` dan `resources/js/actions/`.

#### 2.2 Frontend yang Harus Dibuat/Diubah di Phase 2

1. **Types (`resources/js/types/models.ts`):** Buat tipe TypeScript `Project`, `Task`, `ProjectStatus`, `TaskStatus`, `TaskPriority` lalu ekspor dari `resources/js/types/index.ts`.
2. **Sidebar (`resources/js/components/app-sidebar.tsx`):** Tambahkan menu navigasi ke `Dashboard`, `Tasks`, `Projects` (dan siapkan slot `Calendar` & `AI Planner`).
3. **Halaman `resources/js/pages/tasks/index.tsx`:**
    - Daftar tugas dengan filter `status`, `priority`, dan `project_id`.
    - Dialog/Form tambah & edit tugas (`title`, `description`, `status`, `priority`, `project_id`, `parent_id` opsional, `due_at` dalam waktu lokal pengguna).
    - Checkbox/dropdown cepat untuk mengubah status tugas (`todo`, `in_progress`, `completed`, `cancelled`).
    - Empty state ketika belum ada tugas.
4. **Halaman `resources/js/pages/projects/index.tsx` & `resources/js/pages/projects/show.tsx`:**
    - Kartu proyek dengan progress bar (`progress` 0–100%), status badge, `start_date`–`due_date`, dan form buat/edit proyek.
    - Halaman detail proyek (`projects/show.tsx`) yang menampilkan daftar tugas dalam proyek tersebut beserta tombol tambah tugas langsung ke proyek.
5. **Perluasan `resources/js/pages/settings/profile.tsx`:**
    - Tambahkan input `timezone` (select dari prop `timezones`), `whatsapp_number`, checkbox `whatsapp_enabled`, `reminder_quiet_start`, dan `reminder_quiet_end` (default `00:00`–`05:00`), serta indikator status verifikasi WhatsApp (`whatsapp_verified_at`).

#### 2.3 Test Wajib Phase 2 (`tests/Feature/TaskAndProjectCrudTest.php`)

- User dapat melihat halaman `/tasks` dan `/projects`, membuat, mengubah, memfilter, dan mengarsipkan/menghapus task & project miliknya.
- User mendapatkan `403 Forbidden` jika mencoba melihat/mengubah/menghapus task atau project milik user lain.

---

### PHASE 3 — Calendar & Agenda Berulang (`D-08`, `D-10`)

#### 3.1 Backend yang Harus Dibuat di Phase 3

1. **Migration:** `database/migrations/2026_11_15_000004_create_calendar_events_table.php` sesuai spesifikasi di [docs/DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) Section 3.1.
2. **Model & Factory & Policy:**
    - `app/Models/CalendarEvent.php` (dengan `SoftDeletes`, validasi konsistensi `is_all_day` vs `starts_at`/`ends_at` vs `start_date`/`end_date`, serta validasi kepemilikan `project_id` & `task_id`).
    - `database/factories/CalendarEventFactory.php`.
    - `app/Policies/CalendarEventPolicy.php`.
3. **Service Recurrence & Conflict (`app/Services/Calendar/`):**
    - `RecurrenceExpanderService.php`: mengekspansi agenda yang memiliki `recurrence_rule` (`FREQ=DAILY`, `FREQ=WEEKLY`, `FREQ=MONTHLY`) ke dalam rentang tanggal tampilan (`rangeStart` s.d. `rangeEnd` dalam `users.timezone`), menerapkan `recurrence_exceptions` dan `parent_event_id` overrides secara idempotent (`D-08`).
    - `ScheduleConflictDetector.php`: memeriksa apakah sebuah agenda berjam beririsan dengan agenda lain pada rentang waktu yang sama dan mengembalikan array warning (tanpa memblokir penyimpanan).
4. **Form Requests & Controller:**
    - `StoreCalendarEventRequest.php` & `UpdateCalendarEventRequest.php` (mendukung parameter `edit_scope`: `'single'` atau `'series'` untuk agenda berulang `D-08`).
    - `CalendarEventController.php`: menampilkan agenda + deadline `tasks` dalam rentang tanggal tampilan (`day`, `week`, `month` berdasarkan `$user->timezone`).

#### 3.2 Frontend yang Harus Dibuat di Phase 3

- `resources/js/pages/calendar/index.tsx`: tampilan kalender Harian, Mingguan, dan Bulanan; menampilkan agenda berjam, agenda _all-day_, agenda berulang, dan badge deadline tugas (`tasks.due_at`); modal buat/edit agenda (termasuk pilihan pengulangan dan opsi edit 1 kejadian vs seluruh rangkaian); banner peringatan jika ada jadwal bentrok.

#### 3.3 Test Wajib Phase 3 (`tests/Feature/CalendarEventTest.php`)

- Konversi waktu UTC <-> `users.timezone` untuk agenda berjam dan _all-day_ (termasuk di sekitar pergantian hari `00:00` lokal).
- Ekspansi agenda berulang (`D-08`), pengubahan satu kejadian (_single occurrence_), pengecualian tanggal, dan pembatalan kejadian.
- Peringatan konflik jadwal dikembalikan dengan benar tanpa menggagalkan penyimpanan.

---

### PHASE 4 — Dashboard Aggregation

#### 4.1 Backend yang Harus Dibuat di Phase 4

1. **`app/Services/DashboardAggregatorService.php`:**
    - Menghitung seluruh data dashboard secara efisien (tanpa N+1 query) berdasarkan `$user->timezone`:
        1. `greeting` & `local_date`
        2. `today_tasks` (tugas aktif yang jatuh tempo hari ini atau sedang dikerjakan)
        3. `upcoming_tasks` (tugas aktif dengan deadline terdekat / overdue)
        4. `today_events` (agenda kalender hari ini termasuk ekspansi recurrence)
        5. `active_projects` (`ProjectStatus::Active` dengan `withProgressCounts()`)
        6. `ai_priorities` (hasil `ai_planner_runs` terbaru yang `success` untuk `planning_date` hari ini, jika ada)
        7. `whatsapp_status` (`enabled`, `verified`, `number_masked`, `quiet_hours`)
        8. `completed_summary` (jumlah tugas selesai hari ini & 7 hari terakhir)
2. **`app/Http/Controllers/DashboardController.php` (`__invoke`):**
    - Menggantikan `Route::inertia('dashboard', 'dashboard')` di `routes/web.php`.
3. **Frontend (`resources/js/pages/dashboard.tsx`):**
    - Menggantikan `PlaceholderPattern` dengan 8 kartu/komponen ringkasan yang responsif, lengkap dengan empty state, aksi cepat centang tugas selesai, dan navigasi ke modul terkait.

---

### PHASE 5 — WhatsApp Reminder (`D-02`, `D-05`, `D-06`, `D-07`, `D-14`)

#### 5.1 Backend yang Harus Dibuat di Phase 5

1. **Migration & Model:**
    - `database/migrations/2026_11_15_000005_create_reminders_table.php` sesuai [docs/DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) Section 3.2.
    - `app/Enums/ReminderStatus.php` (`Pending`, `Processing`, `Sent`, `Failed`, `Cancelled`).
    - `app/Models/Reminder.php` & `database/factories/ReminderFactory.php`.
2. **Gateway Contract & Adapter (`app/Contracts/WhatsAppGatewayInterface.php` & `app/Services/WhatsApp/`):**
    - Method: `sendMessage(string $phoneNumber, string $message, string $idempotencyKey): WhatsAppSendResult`.
    - Method: `sendVerificationOtp(string $phoneNumber, string $otpCode): WhatsAppSendResult` (`D-14`).
    - Buat `HttpWhatsAppGateway.php` (konfigurasi via `config/services.php` -> `services.whatsapp.*`) dan `NullWhatsAppGateway.php` (untuk lokal/testing).
3. **Quiet Hours & Reminder Scheduler Service (`app/Services/Reminder/`):**
    - `QuietHoursAdjuster.php`: mengevaluasi apakah `$scheduledAtUtc` jatuh pada rentang `reminder_quiet_start`–`reminder_quiet_end` (default `00:00–05:00` di `$user->timezone`). Jika ya, geser ke akhir jam tenang (`05:00` waktu lokal → UTC).
    - `ReminderSynchronizer.php`: otomatis membuat/memperbarui reminder (`before_24h`, `before_1h` sesuai `D-05`) ketika `Task` atau `CalendarEvent` dibuat/diubah, serta membatalkan (`cancelled`) reminder `pending` ketika task selesai/dibatalkan/dihapus.
4. **Queue Job & Scheduler:**
    - `app/Jobs/SendWhatsAppMessageJob.php` (`$tries = 3`, `$backoff = [15, 60, 180]`), memeriksa ulang status `processing`, idempotency, dan `$user->canReceiveWhatsAppReminders()`.
    - Command `reminders:dispatch-due` yang didaftarkan `Schedule::command('reminders:dispatch-due')->everyMinute()` di `routes/console.php`.
5. **Verifikasi Nomor WhatsApp (`D-14`):**
    - Endpoint `POST /settings/whatsapp/send-otp` (menyimpan hash OTP di cache dengan TTL 5 menit, jangan pernah simpan OTP mentah di DB sesuai `D-15`) dan `POST /settings/whatsapp/verify-otp` (mengisi `whatsapp_verified_at = now()`).

---

### PHASE 6 — AI Planner (`D-03`, `D-04`, `D-07`, `D-12`, `D-13`, `D-15`)

#### 6.1 Backend yang Harus Dibuat di Phase 6

1. **Migration & Model:**
    - `database/migrations/2026_11_15_000006_create_ai_planner_runs_table.php` sesuai [docs/DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) Section 3.3.
    - `app/Enums/AiPlannerRunStatus.php` (`Pending`, `Success`, `Failed`).
    - `app/Models/AiPlannerRun.php` & `database/factories/AiPlannerRunFactory.php`.
2. **Provider Contract & Adapters (`app/Contracts/AiPlannerProviderInterface.php` & `app/Services/AiPlanner/`):**
    - `GeminiPlannerProvider.php` (Prioritas 1 — `config('services.gemini.model')`)
    - `GrokPlannerProvider.php` (Prioritas 2 — `config('services.grok.model')`)
    - `OpenRouterPlannerProvider.php` (Prioritas 3 — `config('services.openrouter.model')`)
    - `FallbackPlannerProvider.php` (Prioritas 4 — algoritma deterministik berbasis skor prioritas + kedekatan deadline + slot waktu kosong kalender).
3. **Validator, Circuit Breaker & Orchestrator (`AiPlannerService.php`):**
    - Membentuk `input_snapshot` yang telah disanitasi (hanya task aktif, agenda hari itu, dan slot waktu kosong; **tanpa** nomor WA/email/token sesuai `D-15`).
    - Menggunakan cache circuit breaker per provider (mis. jika provider gagal 3x berturut-turut dalam 5 menit, lewati provider tersebut selama 5 menit sesuai `D-13`).
    - Memvalidasi output JSON (maks. 3 `priorities`, setiap `task_id` wajib ada di daftar task input).
    - Menyimpan hasil ke `ai_planner_runs`.
4. **Otomatisasi & UI (`D-12`):**
    - Jadwalkan generasi otomatis harian di `routes/console.php` dan pemicu asinkron saat task/agenda hari berjalan berubah signifikan.
    - Buat `AiPlannerController.php` dan halaman `resources/js/pages/ai-planner/index.tsx` (menampilkan 3 prioritas, alasan, estimasi menit, rekomendasi pengingat yang dapat disetujui pengguna dengan 1 klik, serta peringatan konflik jadwal).

---

### PHASE 7 — Hardening, Retensi Data (`D-11`, `D-15`) & Regression

1. **Command Retensi & Pembersihan Terjadwal (`app/Console/Commands/PruneRetentionDataCommand.php`):**
    - Menghapus permanen (`forceDelete`) data soft-deleted (`projects`, `tasks`, `calendar_events`) yang sudah melewati masa retensi (mis. > 30 hari, sesuai `D-11`).
    - Membersihkan log `ai_planner_runs` dan `reminders` lama yang sudah selesai/gagal (mis. > 30 hari, sesuai `D-15`).
2. **Rate Limiting & Ekspor Data:**
    - Tambahkan rate limiter pada endpoint pemicu AI Planner dan pengiriman OTP WhatsApp.
    - Tambahkan endpoint ekspor data JSON pengguna di Settings (sesuai Section 13 Rule 14).
3. **Verifikasi Akhir Menyeluruh:**
    - Jalankan `php artisan test`, `php vendor/bin/phpstan analyse --memory-limit=512M`, `php vendor/bin/pint --dirty --format agent`, `npx.cmd tsc --noEmit`, dan `npm run build`.
