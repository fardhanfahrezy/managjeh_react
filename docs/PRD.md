# Product Requirements & Technical Specification — Managjeh (MVP 1)

- **Status:** **MVP 1 Selesai Diimplementasikan & Terverifikasi (Phase 0 s.d. Phase 7)**
- **Produk:** Personal OS Mahasiswa
- **Arsitektur:** Single-user sekarang, arsitektur siap multi-user (`user_id` di seluruh tabel domain)
- **Database:** PostgreSQL (`managjeh` & `managjeh_test`, 11 migrasi aktif, penamaan **Bahasa Inggris `snake_case`**)
- **Backend:** Laravel `13.42.0` (PHP `^8.3` / `8.5.12`)
- **Frontend:** React `19.2.0` + Inertia.js v3 (`3.7.0`) + TypeScript + Tailwind CSS v4
- **Visual System:** **"The Quiet Desk"** ([DESIGN.md](../DESIGN.md))
- **Timezone:** User-configurable (`users.timezone`, default `'Asia/Jakarta'`), seluruh timestamp absolut disimpan dalam **UTC**

---

## 1. Ringkasan Produk

Managjeh adalah aplikasi web **Personal OS** untuk membantu mahasiswa mengelola **tugas (Tasks)**, **proyek (Projects)**, **agenda kalender & jadwal berulang (Calendar)**, **pengingat WhatsApp**, serta **prioritas harian (AI Planner & Dashboard)**.

- **AI Planner** menganalisis tugas aktif yang belum selesai, prioritas, tenggat waktu, agenda kalender, hubungan tugas dengan proyek, dan slot waktu kosong, lalu menyarankan maksimal **tiga prioritas utama harian** beserta rekomendasi jadwal pengingat.
- **WhatsApp** menjadi kanal pengiriman pengingat dan kode OTP satu arah pada MVP 1, sedangkan seluruh pengelolaan dan perubahan data dilakukan melalui website.

### 1.1 Tujuan MVP 1 (`Implemented & Verified`)

Pengguna dapat:

1. Membuat dan mengelola tugas (beserta subtugas maksimal 1 tingkat sesuai `D-09`).
2. Mengelompokkan tugas ke dalam proyek dan memantau progres proyek secara akurat.
3. Membuat dan melihat agenda kalender (berjam, _all-day_, maupun berulang sesuai `D-08`) dalam tampilan harian, mingguan, dan bulanan.
4. Melihat ringkasan aktivitas terpadu pada Dashboard (8 bagian utama).
5. Menerima pengingat melalui WhatsApp (mendukung multi-reminder per task sesuai `D-05` & `D-07`, serta menghormati jam tenang `00:00–05:00` sesuai `D-06`).
6. Mendapatkan maksimal tiga prioritas harian dari AI Planner (`Gemini → Grok → OpenRouter → Rule-based Fallback` sesuai `D-03`, `D-04`, `D-12`).
7. Mengatur zona waktu (`users.timezone`), verifikasi nomor WhatsApp via OTP (`D-14`), preferensi notifikasi, serta mengekspor seluruh data pribadi dalam format JSON (`D-15`).

### 1.2 Di Luar Cakupan MVP 1 (`Planned / Not Implemented`)

Modul-modul berikut **tidak** termasuk dalam MVP 1 dan belum diimplementasikan di kode saat ini:

- Keuangan, transaksi, anggaran, dan tagihan berulang (_Finance, transactions, budgets, recurring bills_).
- Pelacak kebiasaan dan kesehatan (_Habits, health, sleep, mood_).
- Fokus timer & target jangka panjang terpisah (_Focus & goals_).
- Pembelajaran dan catatan kuliah (_Learning_).
- Karier dan portofolio (_Career_).
- Relasi dan kontak (_Relationships_).
- Administrasi dokumen hidup (_Life administration_).
- Jurnal dan refleksi mingguan (_Journal & reflection_).
- Integrasi langsung bank/e-wallet, sinkronisasi Google Calendar eksternal (`D-10`), serta perintah mutasi data via balasan pesan WhatsApp (`D-02`).

---

## 2. Keputusan Final (`D-01` s.d. `D-15`) & Status Aktual

| ID         | Area                         | Keputusan Final                                                                                                                                                                                       | Status Implementasi (`FACT`)                                                                                                                         |
| :--------- | :--------------------------- | :---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :--------------------------------------------------------------------------------------------------------------------------------------------------- |
| **`D-01`** | Registrasi Publik            | **Registrasi publik dinonaktifkan.** Akun dibuat melalui CLI `php artisan user:provision`.                                                                                                            | `Implemented & Verified` ([config/fortify.php](../config/fortify.php), [ProvisionUserCommand.php](../app/Console/Commands/ProvisionUserCommand.php)) |
| **`D-02`** | WhatsApp Gateway             | Menggunakan gateway pihak ketiga berbasis API melalui adapter `WhatsAppGatewayInterface` (`FonnteWhatsAppGateway`, `HttpWhatsAppGateway`, `NullWhatsAppGateway`).                                     | `Implemented & Verified` ([WhatsAppGatewayInterface.php](../app/Contracts/WhatsAppGatewayInterface.php))                                             |
| **`D-03`** | Urutan Provider AI           | Urutan fallback AI Planner: **`Gemini` → `Grok` → `OpenRouter` → `Rule-based Fallback`**.                                                                                                             | `Implemented & Verified` ([AiPlannerService.php](../app/Services/AiPlanner/AiPlannerService.php))                                                    |
| **`D-04`** | Model AI                     | Nama model seluruh provider dibuat **configurable** melalui `.env` & `config/services.php` (`GEMINI_MODEL`, `GROK_MODEL`, `OPENROUTER_MODEL`).                                                        | `Implemented & Verified` ([config/services.php](../config/services.php))                                                                             |
| **`D-05`** | Frekuensi Reminder           | Mendukung **beberapa reminder untuk satu task** (`before_24h`, `before_1h`, `ai_recommended`) dan agenda (`event_start`). Pengingat yang tidak relevan otomatis dibatalkan (`cancelled`).             | `Implemented & Verified` ([ReminderSynchronizer.php](../app/Services/Reminder/ReminderSynchronizer.php))                                             |
| **`D-06`** | Quiet Hours Default          | Quiet hours default pukul **`00:00–05:00`** berdasarkan `users.timezone`. Reminder pada periode tersebut ditunda ke waktu pertama yang diizinkan (`05:00` waktu lokal).                               | `Implemented & Verified` ([QuietHoursAdjuster.php](../app/Services/Reminder/QuietHoursAdjuster.php))                                                 |
| **`D-07`** | Penentuan Waktu Reminder     | Backend menghitung slot kosong dari kalender; AI merekomendasikan prioritas, `estimated_minutes`, dan waktu pengingat (`recommended_reminders`).                                                      | `Implemented & Verified` ([AiPlannerService.php](../app/Services/AiPlanner/AiPlannerService.php))                                                    |
| **`D-08`** | Agenda Berulang              | **Agenda berulang masuk MVP.** Mendukung `recurrence_rule` (`DAILY`/`WEEKLY`/`MONTHLY`), override 1 kejadian (`parent_event_id` & `recurrence_id`), seluruh rangkaian, serta `recurrence_exceptions`. | `Implemented & Verified` ([RecurrenceExpanderService.php](../app/Services/Calendar/RecurrenceExpanderService.php))                                   |
| **`D-09`** | Subtugas                     | Mendukung subtugas **maksimal satu tingkat** (`tasks.parent_id`), divalidasi di backend & didukung penuh di UI `/tasks` dan `/projects/{project}`.                                                    | `Implemented & Verified` ([Task.php](../app/Models/Task.php), [task-row-item.tsx](../resources/js/components/tasks/task-row-item.tsx))               |
| **`D-10`** | Kalender Eksternal           | Menggunakan kalender internal sebagai sumber data utama pada MVP 1.                                                                                                                                   | `Implemented & Verified` ([CalendarEvent.php](../app/Models/CalendarEvent.php))                                                                      |
| **`D-11`** | Penghapusan Data             | Menggunakan **soft delete (`deleted_at`)** pada `projects`, `tasks`, dan `calendar_events`. Penghapusan permanen (> 30 hari) dilakukan melalui `php artisan app:prune-retention-data`.                | `Implemented & Verified` ([PruneRetentionDataCommand.php](../app/Console/Commands/PruneRetentionDataCommand.php))                                    |
| **`D-12`** | Pemicu AI Planner            | AI Planner berjalan **otomatis** (Scheduler harian `05:30` & pemicu perubahan data) serta **manual**. **AI dilarang mengubah task, proyek, atau agenda tanpa persetujuan pengguna.**                  | `Implemented & Verified` ([AiPlannerController.php](../app/Http/Controllers/AiPlannerController.php))                                                |
| **`D-13`** | Reliabilitas Penggunaan AI   | Penanganan rate limit (`10/menit`), timeout (`AI_PLANNER_TIMEOUT`), fallback berjenjang, pencatatan di `ai_planner_runs`, dan _circuit breaker_ (3 gagal dalam 300 detik).                            | `Implemented & Verified` ([AiPlannerService.php](../app/Services/AiPlanner/AiPlannerService.php))                                                    |
| **`D-14`** | Verifikasi Nomor WhatsApp    | Verifikasi nomor menggunakan OTP 6 digit (`WhatsAppVerificationController`). Pesan tidak dikirim sebelum `$user->canReceiveWhatsAppReminders()` bernilai `true`.                                      | `Implemented & Verified` ([WhatsAppVerificationController.php](../app/Http/Controllers/Settings/WhatsAppVerificationController.php))                 |
| **`D-15`** | Minimisasi, Retensi & Ekspor | Sanitasi prompt AI, penyimpanan OTP hanya berupa hash di cache, ekspor JSON ter-whitelist (`GET /settings/profile/export`), dan pembersihan log otomatis > 30 hari.                                   | `Implemented & Verified` ([ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php))                                           |

---

## 3. Domain dan Aturan Modul Aktual

### 3.1 Task Management (`tasks`)

- **Status (`App\Enums\TaskStatus`):** `todo`, `in_progress`, `completed`, `cancelled`.
- **Prioritas (`App\Enums\TaskPriority`):** `low`, `medium`, `high`, `urgent`.
- **Kolom Tabel `tasks`:** `id`, `user_id`, `project_id`, `parent_id`, `title`, `description`, `status`, `priority`, `due_at` (UTC), `completed_at` (UTC), `sort_order`, `created_at`, `updated_at`, `deleted_at`.
- **Aturan Bisnis:**
    - `completed_at` otomatis terisi saat `status = completed` dan di-reset ke `null` saat status berubah kembali ke selain `completed`.
    - Subtugas dibatasi maksimal 1 tingkat (`D-09`).
    - Setiap pembuatan/perubahan/penghapusan tugas otomatis menyinkronkan pengingat WhatsApp (`ReminderSynchronizer::syncForTask()`).

### 3.2 Project Management (`projects`)

- **Status (`App\Enums\ProjectStatus`):** `active`, `completed`, `archived`.
- **Rumus Progres (`Project::calculateProgress()`):** `round((completed_tasks / non_cancelled_tasks) * 100)`, atau `0%` jika belum ada tugas non-cancelled.
- Menghapus proyek tidak menghapus tugas di dalamnya (`nullOnDelete` pada `tasks.project_id`).

### 3.3 Calendar & Recurrence (`calendar_events` — `D-08`)

- Mendukung agenda berjam (`is_all_day = false`, `starts_at` & `ends_at` dalam UTC) dan seharian (`is_all_day = true`, `start_date` & `end_date` lokal).
- Mendukung `recurrence_rule` (`FREQ=DAILY`, `FREQ=WEEKLY`, `FREQ=MONTHLY`), `recurrence_until`, `recurrence_exceptions` (JSONB), dan _single-occurrence override_ (`parent_event_id` + `recurrence_id`).
- Deteksi konflik jadwal (`ScheduleConflictDetector`) memberikan peringatan non-blocking.

### 3.4 Dashboard (`/dashboard`)

Menampilkan 8 komponen terpadu dari `DashboardAggregatorService`:

1. Greeting kontekstual & tanggal lokal (`users.timezone`).
2. Tiga prioritas harian AI Planner (`ai_priorities`).
3. Tugas jatuh tempo hari ini (`today_tasks`) dengan aksi cepat centang selesai.
4. Tugas mendekati tenggat & terlambat (`upcoming_tasks`).
5. Agenda kalender hari ini (`today_events`, termasuk ekspansi berulang).
6. Progres proyek aktif (`active_projects`).
7. Status pengingat & verifikasi WhatsApp (`whatsapp_status`).
8. Ringkasan penyelesaian tugas hari ini & 7 hari terakhir (`completed_summary`).

### 3.5 WhatsApp Reminder (`reminders`)

- **Status (`App\Enums\ReminderStatus`):** `pending`, `processing`, `sent`, `failed`, `cancelled`.
- Mendukung driver `fonnte` (`FonnteWhatsAppGateway`), `http` (`HttpWhatsAppGateway`), dan `null` (`NullWhatsAppGateway`).
- `QuietHoursAdjuster` menggeser jadwal yang jatuh pada jam tenang (`00:00–05:00` waktu lokal) ke akhir jam tenang.

### 3.6 AI Planner (`ai_planner_runs`)

- **Status (`App\Enums\AiPlannerRunStatus`):** `pending`, `success`, `failed`.
- **Urutan Provider (`D-03`):** `GeminiPlannerProvider` → `GrokPlannerProvider` → `OpenRouterPlannerProvider` → `FallbackPlannerProvider`.
- **Catatan Penting Status Eksekusi:**
    - Apabila seluruh provider LLM eksternal (`gemini`, `grok`, `openrouter`) gagal atau tidak dikonfigurasi, `FallbackPlannerProvider` menghasilkan rencana deterministik dan menyimpan baris `ai_planner_runs` dengan `status = 'success'` dan `provider = 'fallback'`.
    - Oleh karena itu, untuk memverifikasi keberhasilan koneksi LLM eksternal, periksa bahwa `provider` bernilai `'gemini'`, `'grok'`, atau `'openrouter'` (bukan `'fallback'`).

---

## 4. Riwayat Pengembangan Phase 0 s.d. Phase 7 (`Phase History`)

### Phase 0 & Phase 1 — Repository Audit & Core Data Foundation (Commit `7cfee06`, `c39a4dd`)

- **Objective:** Membangun fondasi autentikasi tertutup (`D-01`), skema database inti (`users`, `projects`, `tasks`), model, enum, policy, FormRequest, dan command provisioning user.
- **Deliverables:** 8 migrasi awal, `User`, `Project`, `Task`, `ProjectPolicy`, `TaskPolicy`, `Store/UpdateProjectRequest`, `Store/UpdateTaskRequest`, `ProvisionUserCommand` (`php artisan user:provision`), serta 57 test awal.

### Phase 2 — Task & Project UI + Settings Timezone/WhatsApp (Commit `76bad5a`, `da12661`, `78709fe`, `8609c32`)

- **Objective:** Menyediakan antarmuka penuh untuk pengelolaan Task, Subtask, Project, dan pengaturan profil/timezone/WhatsApp di atas design system **"The Quiet Desk"**.
- **Deliverables:** `ProjectController`, `TaskController`, halaman `tasks/index.tsx`, `projects/index.tsx`, `projects/show.tsx`, komponen `task-form-dialog.tsx` & `task-row-item.tsx`, perluasan `settings/profile.tsx`, dan `TaskAndProjectCrudTest.php`.

### Phase 3 — Calendar & Agenda Berulang `D-08` (Commit `b50e552`, `8f83540`, `42c5868`, `a8c2271`)

- **Objective:** Mengimplementasikan kalender internal (`Day`, `Week`, `Month`) dengan dukungan agenda berulang, pengecualian/override kejadian tunggal, dan peringatan konflik jadwal.
- **Deliverables:** Migrasi `calendar_events`, model `CalendarEvent`, `CalendarEventPolicy`, `RecurrenceExpanderService`, `ScheduleConflictDetector`, `CalendarEventController`, halaman `calendar/index.tsx`, dan `CalendarEventTest.php`.

### Phase 4 — Dashboard Aggregation (Commit `d6b47cd`, `64f4cb6`)

- **Objective:** Menyajikan pusat kendali harian mahasiswa dalam satu layar bebas N+1 query.
- **Deliverables:** `DashboardAggregatorService`, `DashboardController`, halaman `dashboard.tsx` (8 komponen utama), dan `DashboardTest.php`.

### Phase 5 — WhatsApp Reminder, Quiet Hours & Fonnte Integration (Commit `2826530`, `0b45131`, `ac7cba6`, `f83788c`)

- **Objective:** Mengintegrasikan pengiriman pengingat WhatsApp satu arah yang menghormati persetujuan OTP (`D-14`) dan jam tenang (`00:00–05:00` `D-06`).
- **Deliverables:** Migrasi `reminders`, model `Reminder`, `WhatsAppGatewayInterface`, `FonnteWhatsAppGateway` (terverifikasi live dengan pengiriman OTP nyata via Fonnte), `HttpWhatsAppGateway`, `NullWhatsAppGateway`, `QuietHoursAdjuster`, `ReminderSynchronizer`, `SendWhatsAppMessageJob`, `DispatchDueRemindersCommand`, `WhatsAppVerificationController`, dan `WhatsAppReminderTest.php`.

### Phase 6 — AI Planner Multi-Provider & Fallback (Commit `39a8a4a`, `5b0cd9a`, `db33439`, `4d63802`)

- **Objective:** Menyediakan rekomendasi 3 prioritas harian dengan ketahanan multi-provider (`Gemini → Grok → OpenRouter → Fallback`), _circuit breaker_, dan persetujuan pengingat oleh pengguna.
- **Deliverables:** Migrasi `ai_planner_runs`, model `AiPlannerRun`, `AiPlannerProviderInterface`, `GeminiPlannerProvider` (terverifikasi live), `GrokPlannerProvider`, `OpenRouterPlannerProvider` (terverifikasi live), `FallbackPlannerProvider`, `AiPromptBuilder`, `AiPlannerService`, `GenerateDailyAiPlansCommand`, `AiPlannerController`, halaman `ai-planner/index.tsx`, dan `AiPlannerTest.php`.

### Phase 7 — Hardening, Data Export, Rate Limiting & Retention (Commit `78d2f11`, `eb8a842`, `cb9841f`, `bd15ce7`, `54d9aa1`)

- **Objective:** Menambahkan pembersihan retensi terjadwal (`D-11`, `D-15`), ekspor data JSON pengguna, rate limiting dengan umpan balik UI `429`, dan regresi menyeluruh.
- **Deliverables:** `PruneRetentionDataCommand` (`app:prune-retention-data`), rate limiters (`ai-planner`, `whatsapp-otp`, `data-export`), `ProfileController@export`, hook `use-rate-limit-cooldown.ts`, section **Data & Privasi** di `settings/profile.tsx`, dan `Phase7HardeningTest.php`.

---

## 5. Ringkasan Status Verifikasi & Batasan Operasional

- **Automated Tests Passing:** `82` test lulus (`551` assertions, `2` skipped) di PostgreSQL `managjeh_test`.
- **Static Analysis & Linting Passing:** PHPStan level 7 (`0` errors), Laravel Pint (`passed`), TypeScript `tsc --noEmit` (`0` errors), Vite+ `vp check` (`99` files formatted, `0` lint warnings/errors).
- **External Services Verified:**
    - **Google Gemini (`gemini-flash-latest`):** Terverifikasi live.
    - **OpenRouter:** Terverifikasi live.
    - **Fonnte WhatsApp Gateway (`https://api.fonnte.com/send`):** Terverifikasi live (pengiriman OTP WhatsApp nyata berhasil diterima).
    - **xAI Grok (`grok-3-mini`):** Adapter teruji melalui `Http::fake()`; pada pengujian live sebelumnya akun xAI belum memiliki kredit/kuota sehingga sistem otomatis beralih ke OpenRouter sesuai desain fallback `D-03`.
- **Known Operational Limitations:**
    - Pengiriman pengingat terjadwal dan pembuatan rencana harian otomatis di lingkungan produksi membutuhkan proses `php artisan queue:work` dan cron `* * * * * php artisan schedule:run` yang berjalan aktif.
