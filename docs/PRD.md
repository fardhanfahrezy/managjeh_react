# Product Requirements & Technical Specification — Managjeh (MVP 1)

- **Status:** **MVP 1 (Phase 0–7) & MVP 2 (Website AI Chat Hub, Internal MCP Server, Proposal Approval & Audit Trail, Perluasan WhatsApp Outbound & Multi-Channel, HTTP Security Headers) Selesai Diimplementasikan & Terverifikasi (`Implemented & Verified`); Arah MVP 3 Terkonfirmasi (`Planned`)**
- **Produk:** Personal OS Mahasiswa
- **Arsitektur:** Single-user sekarang, arsitektur siap multi-user (`user_id` di seluruh tabel domain); Website sebagai pusat AI & manajemen data, WhatsApp sebagai kanal pengingat outbound
- **Database:** PostgreSQL (`managjeh` & `managjeh_test`, 14 migrasi aktif, penamaan **Bahasa Inggris `snake_case`**)
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

### 1.2 Di Luar Cakupan MVP 1 & Arah Pengembangan Lanjutan (`CONFIRMED` / `Planned`)

Sesuai arahan produk terbaru (**Website sebagai Pusat AI dan WhatsApp sebagai Kanal Pengingat**), cakupan pengembangan setelah MVP 1 dibagi secara bertahap (`D-16`–`D-19`) dan **belum diimplementasikan** di kode saat ini:

- **MVP 2 (`Planned` — Prioritas Berikutnya):**
    - **Website AI Chat Hub** (berdampingan dengan `/ai-planner`, termasuk riwayat percakapan & pengaturan preferensi AI).
    - **AI Orchestrator & Internal MCP Server** di dalam monolith Laravel untuk membaca data modul aktif (`Tasks`, `Projects`, `Calendar`, `Dashboard`) dan mengusulkan perubahan data dengan persetujuan eksplisit pengguna + audit trail.
    - **Perluasan WhatsApp Outbound Notification Service** (persiapan sebelum kegiatan, ringkasan aktivitas harian, evaluasi & ringkasan mingguan) serta abstraksi multi-channel yang siap menambah **Telegram** atau kanal lain di masa depan.
- **MVP 3 (`Planned` — Modul Personal OS Lanjutan):**
    - **Habits, Rutinitas & Target Tidur** (`get_my_habits`, analisis kebiasaan & produktivitas, pengingat kebiasaan/tidur).
    - **Finance: Pengeluaran, Anggaran & Tagihan** (`get_my_finances`, pengingat jatuh tempo tagihan & peringatan mendekati batas anggaran dengan minimisasi info finansial sensitif).
    - **Evaluasi & Refleksi Mingguan** terpadu dengan AI Chat dan ringkasan WhatsApp.
- **Di Luar Cakupan Saat Ini / Tetap Dilarang:**
    - Fokus timer terpisah, catatan kuliah (_Learning_), karier/portofolio, manajemen kontak, administrasi dokumen (`Planned / Future Backlog`).
    - Penyimpanan password bank, PIN, OTP, atau kredensial e-wallet (**Dilarang keras**).
    - Sinkronisasi Google Calendar eksternal (`D-10`) serta menjadikan WhatsApp sebagai kanal utama chat AI atau pengelolaan CRUD (`D-02`, `D-16`).

---

## 2. Keputusan Final (`D-01` s.d. `D-19`) & Status Aktual

| ID         | Area                          | Keputusan Final                                                                                                                                                                                                                                                                        | Status Implementasi (`FACT`)                                                                                                                         |
| :--------- | :---------------------------- | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :--------------------------------------------------------------------------------------------------------------------------------------------------- |
| **`D-01`** | Registrasi Publik             | **Registrasi publik dinonaktifkan.** Akun dibuat melalui CLI `php artisan user:provision`.                                                                                                                                                                                             | `Implemented & Verified` ([config/fortify.php](../config/fortify.php), [ProvisionUserCommand.php](../app/Console/Commands/ProvisionUserCommand.php)) |
| **`D-02`** | WhatsApp Gateway              | Menggunakan gateway pihak ketiga berbasis API melalui adapter `WhatsAppGatewayInterface` (`FonnteWhatsAppGateway`, `HttpWhatsAppGateway`, `NullWhatsAppGateway`).                                                                                                                      | `Implemented & Verified` ([WhatsAppGatewayInterface.php](../app/Contracts/WhatsAppGatewayInterface.php))                                             |
| **`D-03`** | Urutan Provider AI            | Urutan fallback AI Planner: **`Gemini` → `Grok` → `OpenRouter` → `Rule-based Fallback`**.                                                                                                                                                                                              | `Implemented & Verified` ([AiPlannerService.php](../app/Services/AiPlanner/AiPlannerService.php))                                                    |
| **`D-04`** | Model AI                      | Nama model seluruh provider dibuat **configurable** melalui `.env` & `config/services.php` (`GEMINI_MODEL`, `GROK_MODEL`, `OPENROUTER_MODEL`).                                                                                                                                         | `Implemented & Verified` ([config/services.php](../config/services.php))                                                                             |
| **`D-05`** | Frekuensi Reminder            | Mendukung **beberapa reminder untuk satu task** (`before_24h`, `before_1h`, `ai_recommended`) dan agenda (`event_start`). Pengingat yang tidak relevan otomatis dibatalkan (`cancelled`).                                                                                              | `Implemented & Verified` ([ReminderSynchronizer.php](../app/Services/Reminder/ReminderSynchronizer.php))                                             |
| **`D-06`** | Quiet Hours Default           | Quiet hours default pukul **`00:00–05:00`** berdasarkan `users.timezone`. Reminder pada periode tersebut ditunda ke waktu pertama yang diizinkan (`05:00` waktu lokal).                                                                                                                | `Implemented & Verified` ([QuietHoursAdjuster.php](../app/Services/Reminder/QuietHoursAdjuster.php))                                                 |
| **`D-07`** | Penentuan Waktu Reminder      | Backend menghitung slot kosong dari kalender; AI merekomendasikan prioritas, `estimated_minutes`, dan waktu pengingat (`recommended_reminders`).                                                                                                                                       | `Implemented & Verified` ([AiPlannerService.php](../app/Services/AiPlanner/AiPlannerService.php))                                                    |
| **`D-08`** | Agenda Berulang               | **Agenda berulang masuk MVP.** Mendukung `recurrence_rule` (`DAILY`/`WEEKLY`/`MONTHLY`), override 1 kejadian (`parent_event_id` & `recurrence_id`), seluruh rangkaian, serta `recurrence_exceptions`.                                                                                  | `Implemented & Verified` ([RecurrenceExpanderService.php](../app/Services/Calendar/RecurrenceExpanderService.php))                                   |
| **`D-09`** | Subtugas                      | Mendukung subtugas **maksimal satu tingkat** (`tasks.parent_id`), divalidasi di backend & didukung penuh di UI `/tasks` dan `/projects/{project}`.                                                                                                                                     | `Implemented & Verified` ([Task.php](../app/Models/Task.php), [task-row-item.tsx](../resources/js/components/tasks/task-row-item.tsx))               |
| **`D-10`** | Kalender Eksternal            | Menggunakan kalender internal sebagai sumber data utama pada MVP 1.                                                                                                                                                                                                                    | `Implemented & Verified` ([CalendarEvent.php](../app/Models/CalendarEvent.php))                                                                      |
| **`D-11`** | Penghapusan Data              | Menggunakan **soft delete (`deleted_at`)** pada `projects`, `tasks`, dan `calendar_events`. Penghapusan permanen (> 30 hari) dilakukan melalui `php artisan app:prune-retention-data`.                                                                                                 | `Implemented & Verified` ([PruneRetentionDataCommand.php](../app/Console/Commands/PruneRetentionDataCommand.php))                                    |
| **`D-12`** | Pemicu AI Planner             | AI Planner berjalan **otomatis** (Scheduler harian `05:30` & pemicu perubahan data) serta **manual**. **AI dilarang mengubah task, proyek, atau agenda tanpa persetujuan pengguna.**                                                                                                   | `Implemented & Verified` ([AiPlannerController.php](../app/Http/Controllers/AiPlannerController.php))                                                |
| **`D-13`** | Reliabilitas Penggunaan AI    | Penanganan rate limit (`10/menit`), timeout (`AI_PLANNER_TIMEOUT`), fallback berjenjang, pencatatan di `ai_planner_runs`, dan _circuit breaker_ (3 gagal dalam 300 detik).                                                                                                             | `Implemented & Verified` ([AiPlannerService.php](../app/Services/AiPlanner/AiPlannerService.php))                                                    |
| **`D-14`** | Verifikasi Nomor WhatsApp     | Verifikasi nomor menggunakan OTP 6 digit (`WhatsAppVerificationController`). Pesan tidak dikirim sebelum `$user->canReceiveWhatsAppReminders()` bernilai `true`.                                                                                                                       | `Implemented & Verified` ([WhatsAppVerificationController.php](../app/Http/Controllers/Settings/WhatsAppVerificationController.php))                 |
| **`D-15`** | Minimisasi, Retensi & Ekspor  | Sanitasi prompt AI, penyimpanan OTP hanya berupa hash di cache, ekspor JSON ter-whitelist (`GET /settings/profile/export`), dan pembersihan log otomatis > 30 hari.                                                                                                                    | `Implemented & Verified` ([ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php))                                           |
| **`D-16`** | Website AI Hub & Internal MCP | **Website** adalah pusat interaksi AI; **WhatsApp** hanya kanal outbound notifikasi. **AI Orchestrator & MCP Server** dibangun di dalam monolith Laravel (autentikasi sesi Laravel, tanpa akses DB langsung oleh AI, tanpa menerima `user_id` dari AI).                                | `Planned` (`CONFIRMED`)                                                                                                                              |
| **`D-17`** | Posisi AI Chat vs AI Planner  | Halaman `/ai-planner` tetap dipertahankan untuk 3 prioritas harian, **berdampingan** dengan antarmuka **AI Chat** baru (riwayat percakapan, rencana tindakan _propose → approve → execute_, audit trail, dan preferensi AI).                                                           | `Planned` (`CONFIRMED`)                                                                                                                              |
| **`D-18`** | Urutan Roadmap MVP 2 & MVP 3  | **MVP 2** memprioritaskan Website AI Chat + AI Orchestrator + Internal MCP di atas modul aktif (`Tasks`, `Projects`, `Calendar`, `Dashboard`) dan perluasan notifikasi WA + kesiapan multi-channel (**Telegram**). Modul **Habits/Sleep** & **Finance/Budgets/Bills** masuk **MVP 3**. | `Planned` (`CONFIRMED`)                                                                                                                              |
| **`D-19`** | Retensi Chat & Kanal WA       | Riwayat percakapan AI mengikuti retensi otomatis **30 hari** (`D-15`) ditambah opsi hapus manual oleh pengguna. WhatsApp tetap memakai abstraksi adapter (`WhatsAppGatewayInterface`: Fonnte aktif, siap tambah Meta Cloud API & kanal Telegram).                                      | `Planned` (`CONFIRMED`)                                                                                                                              |

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

- **Automated Tests Passing (`FACT`):** `84` test lulus (`569` assertions, `2` skipped pada `RegistrationTest` karena `Features::registration()` nonaktif sesuai `D-01`) di PostgreSQL `managjeh_test`.
- **Static Analysis & Linting Passing (`FACT`):** PHPStan level 7 (`0` errors), Laravel Pint (`passed`), TypeScript `tsc --noEmit` (`0` errors), Vite+ `vp check` (`0` lint warnings/errors).
- **External Services Verified:**
    - **Google Gemini (`gemini-flash-latest`):** Terverifikasi live.
    - **OpenRouter:** Terverifikasi live.
    - **Fonnte WhatsApp Gateway (`https://api.fonnte.com/send`):** Terverifikasi live (pengiriman OTP WhatsApp nyata berhasil diterima).
    - **xAI Grok (`grok-3-mini`):** Adapter teruji melalui `Http::fake()`; pada pengujian live sebelumnya akun xAI belum memiliki kredit/kuota sehingga sistem otomatis beralih ke OpenRouter sesuai desain fallback `D-03`.
- **Known Operational Limitations:**
    - Pengiriman pengingat terjadwal dan pembuatan rencana harian otomatis di lingkungan produksi membutuhkan proses `php artisan queue:work` dan cron `* * * * * php artisan schedule:run` yang berjalan aktif.

---

## 6. Inventaris Fitur Lanjutan & Status (`MVP 2 & MVP 3` — `CONFIRMED` / `Planned`)

| Feature ID     | Nama Fitur                                                                                  | Aktor Target                       | Sumber      | Prioritas  | Dependensi                                                                 | Status Implementasi |
| :------------- | :------------------------------------------------------------------------------------------ | :--------------------------------- | :---------- | :--------- | :------------------------------------------------------------------------- | :------------------ |
| **`F-AI-01`**  | Website AI Chat Interface (`/ai-chat`) & Riwayat Percakapan                                 | Pengguna Terautentikasi            | `CONFIRMED` | P0 (MVP 2) | Auth, `F-AI-02`, `F-AI-03`                                                 | `Planned`           |
| **`F-AI-02`**  | AI Orchestrator (Native Tool Calling, Max 4 Putaran, Multi-Provider, Sync JSON)             | Sistem / Backend                   | `CONFIRMED` | P0 (MVP 2) | `AiPlannerService` (`D-03`, `D-04`), `F-AI-03`                             | `Planned`           |
| **`F-AI-03`**  | Internal Managjeh MCP Server (`app/Mcp/`, 6 Read + 4 Write Non-Destruktif)                  | AI Orchestrator (via Backend Auth) | `CONFIRMED` | P0 (MVP 2) | `Task`, `Project`, `CalendarEvent`, `DashboardAggregatorService`, Policies | `Planned`           |
| **`F-AI-04`**  | Approval Workflow Mutasi AI (Checkbox Selektif, Cek Konflik, TTL 24j) & Audit Trail         | Pengguna Terautentikasi            | `CONFIRMED` | P0 (MVP 2) | `F-AI-01`, `F-AI-03`, `ScheduleConflictDetector`                           | `Planned`           |
| **`F-AI-05`**  | Pengaturan Preferensi AI (`/settings/ai`) & Hapus Manual / Retensi 30 Hari Chat             | Pengguna Terautentikasi            | `CONFIRMED` | P1 (MVP 2) | `F-AI-01`, `PruneRetentionDataCommand` (`D-15`, `D-19`)                    | `Planned`           |
| **`F-WA-01`**  | Perluasan Outbound WA (H-15m, Ringkasan Harian/Mingguan Deterministik, Rolling Sync 48j)    | Sistem / Pengguna Terverifikasi    | `CONFIRMED` | P1 (MVP 2) | `WhatsAppGatewayInterface`, `QuietHoursAdjuster`, `ReminderSynchronizer`   | `Planned`           |
| **`F-WA-02`**  | Abstraksi Multi-Channel Outbound (`OutboundChannelManager`, Meta Cloud API, Siap Telegram)  | Sistem / Backend                   | `CONFIRMED` | P1 (MVP 2) | `WhatsAppGatewayInterface` (`D-02`, `D-19`)                                | `Planned`           |
| **`F-SEC-01`** | Hardening MVP 2: Rate Limit `ai-chat` (`20/m`), Sanitasi Konteks & HTTP Security Headers    | Sistem / Backend                   | `CONFIRMED` | P1 (MVP 2) | `AppServiceProvider`, Middleware HTTP Security Headers                     | `Planned`           |
| **`F-HAB-01`** | Modul Habits, Rutinitas & Target Tidur + Tool `get_my_habits` + Pengingat WA                | Pengguna Terautentikasi            | `CONFIRMED` | P2 (MVP 3) | MVP 2 (`F-AI-03`, `F-WA-01`)                                               | `Planned`           |
| **`F-FIN-01`** | Modul Finance (Pengeluaran, Anggaran, Tagihan) + Tool `get_my_finances` + Pengingat WA Aman | Pengguna Terautentikasi            | `CONFIRMED` | P2 (MVP 3) | MVP 2 (`F-AI-03`, `F-WA-01`), Aturan Privasi Finansial                     | `Planned`           |
| **`F-REF-01`** | Evaluasi & Refleksi Mingguan (AI Chat + Ringkasan WA Mingguan)                              | Pengguna Terautentikasi            | `CONFIRMED` | P2 (MVP 3) | `F-AI-01`, `F-WA-01`, modul aktif/MVP 3                                    | `Planned`           |

### Spesifikasi Desain Teknis Terkunci MVP 2 (`CONFIRMED` — Menutup `TODO-01` s.d. `TODO-03`)

Seluruh item desain teknis MVP 2 berikut telah disepakati dan **belum diimplementasikan** di kode saat ini:

- **Internal MCP Server (`TODO-01` Selesai):** Implementasi ringan tanpa dependensi baru di `app/Mcp/` (`McpToolInterface`, `McpToolRegistry`, `McpToolExecutor`) dengan kontrak JSON Schema kompatibel MCP, dieksekusi **in-process** memakai objek `User` dari sesi Laravel (tanpa endpoint HTTP `/mcp` eksternal di MVP 2). Katalog MVP 2 terdiri dari **6 Read Tools** (`get_my_tasks`, `get_upcoming_tasks`, `get_today_schedule`, `get_schedule_range` termasuk slot kosong, `get_my_projects`, `get_daily_overview`) dan **4 Write Tools non-destruktif** (`create_task`, `update_task`, `create_schedule`, `update_schedule` — tanpa `delete_*` dan tanpa mutasi proyek).
- **AI Orchestrator, Mode Respons & Konteks (`TODO-02` Selesai):**
    - Memakai _native function/tool calling_ masing-masing adapter (`Gemini` `functionDeclarations`, `Grok` & `OpenRouter` OpenAI-compatible `tools`) dengan batas **maksimal 4 putaran tool-call** per pesan. Write tools selalu dicegat menjadi Proposal (tidak dieksekusi di loop LLM); jika seluruh provider gagal, tampilkan pesan error ramah tanpa chat fallback tiruan.
    - Pengiriman respons memakai **synchronous JSON request-response** (`fetch` / Inertia `useHttp`) dengan optimistic user bubble + indikator status memproses (stabil untuk FrankenPHP `GOMAXPROCS=1` di Railway dan bebas jeda `queue:work --sleep=15`).
    - Halaman khusus **`/ai-chat`** di sidebar utama ("AI Chat") berdampingan dengan "AI Planner" (`D-17`), layout 2 kolom desktop + drawer riwayat di mobile `375px` + starter prompt chips. Konteks LLM memakai _sliding window_ **16 pesan terakhir** (8 pasang user–assistant), judul otomatis dari potongan pesan pertama (maks 60 karakter, bisa di-rename/hapus manual), retensi otomatis 30 hari (`D-19`).
- **Persetujuan Mutasi, Audit Trail & Preferensi AI (`F-AI-04`, `F-AI-05`):**
    - Kartu Rencana Aksi (Action Plan Card) mendukung **checkbox per item**, pratinjau bentrok jadwal otomatis (`ScheduleConflictDetector`), dan tombol **"Setujui Terpilih"** / **"Tolak Semua"** (tanpa form edit inline di kartu). Proposal pending kedaluwarsa otomatis setelah **24 jam**; eksekusi memvalidasi ulang kepemilikan & Policy secara idempoten. Hasil tampil inline + panel/tab ringkas **"Riwayat Aksi AI"** di `/ai-chat`, masuk ekspor `GET /settings/profile/export`, dan ikut retensi 30 hari.
    - Preferensi AI disimpan pada kolom JSONB `users.ai_preferences` di tab Settings baru **`/settings/ai`** (gaya respons `ringkas`/`seimbang`/`mendetail`, jam belajar favorit, durasi blok fokus default `30`/`45`/`60`/`90` menit, dan instruksi singkat maks 300 karakter) yang dibaca oleh AI Chat maupun AI Planner.
- **Perluasan Outbound WhatsApp & Multi-Channel (`TODO-03` Selesai, `F-WA-01`, `F-WA-02`):**
    - Memanfaatkan tabel `reminders` yang ada dengan 3 perluasan: **Ringkasan Harian** (default `07:00` lokal), **Ringkasan Mingguan** (default `Minggu 19:00` lokal), dan **Persiapan Kegiatan (`event_prep`, H-15 menit)** + format pesan lebih kaya (status, saran singkat, tautan ke website) + **rolling sync 48 jam** harian via `RecurrenceExpanderService` agar agenda berulang (jadwal kuliah mingguan) ikut menerima pengingat.
    - Isi Ringkasan Harian & Mingguan disusun secara **deterministik (rule-based)** dari `DashboardAggregatorService`, statistik mingguan, dan prioritas `AiPlannerRun` hari ini bila ada + tautan ke `/ai-chat`. Toggle aktif/nonaktif & jam kirim dikustomisasi per pengguna pada JSONB `users.notification_preferences` (tetap menghormati `QuietHoursAdjuster` `D-06` & verifikasi OTP `D-14`).
    - Abstraksi `OutboundChannelInterface` + `OutboundChannelManager` (routing via `reminders.channel`, mendelegasikan `whatsapp` ke `WhatsAppGatewayInterface`) + driver adapter `MetaCloudWhatsAppGateway` (`meta_cloud`), sementara UI/integrasi Telegram aktif ditunda hingga diperlukan.
- **Skema Minimal & Hardening Keamanan (`F-SEC-01`):**
    - Skema baru: **3 tabel** (`ai_chat_conversations`, `ai_chat_messages`, `ai_action_proposals`) + **2 kolom JSONB** pada `users` (`ai_preferences`, `notification_preferences`).
    - Rate limiter `ai-chat` (`20 req/menit` per user dengan `useRateLimitCooldown`), batas pesan `2.000` karakter, sanitasi konteks tanpa PII/rahasia (`D-15`), serta middleware **HTTP Security Headers** (`HSTS`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `COOP`, dan baseline `CSP` kompatibel Vite/Inertia).

---

## 9. Spesifikasi Terimplementasi — Offline-First PWA (`Implemented & Verified` — `D-20` s.d. `D-25`)

- **`D-20` (Full Local-First):** Seluruh baca & tulis pada `Dashboard`, `Tasks`, `Projects`, dan `Calendar` melalui IndexedDB (`Dexie.js`) + antrean Outbox atomik (respons 0ms); `SyncCoordinator` mendorong `POST /sync/push` & menarik `GET /sync/pull` secara otomatis saat online.
- **`D-21` (Offline Session Lease 7 Hari):** Metadata lease non-sensitif (tanpa password/OTP/token) diperpanjang otomatis tiap sinkronisasi sukses; jika habis saat offline layar terkunci sementara Outbox tetap aman hingga login ulang.
- **`D-22` (Isolasi Multi-Akun & Logout Guard):** Database IndexedDB terpisah per user (`managjeh_offline_u{id}`); logout saat Outbox kosong langsung menghapus DB lokal, sedangkan logout saat ada perubahan tertunda memunculkan `LogoutOutboxDialog`; pergantian akun otomatis menghapus DB akun sebelumnya.
- **`D-23` (Kalender Berulang Offline):** Porting penuh `RecurrenceExpander` & `ScheduleConflictDetector` ke TypeScript agar `Day`/`Week`/`Month`, `DAILY`/`WEEKLY`/`MONTHLY`, exception/override kejadian tunggal, dan deteksi konflik berjalan identik saat offline.
- **`D-24` (OCC Hybrid Auto-Merge + Tombstones):** Optimistic concurrency berbasis `version`, penggabungan otomatis per-field untuk perubahan tidak bertabrakan, `ConflictResolutionDialog` untuk tabrakan field & Edit-vs-Delete, serta `sync_tombstones` (retensi 180 hari) anti-zombie pasca `app:prune-retention-data`.
- **`D-25` (Perilaku Fitur Online-Only):** `/ai-planner` menampilkan snapshot terakhir + tombol **Susun Prioritas Lokal (Offline)**; `/ai-chat` mode hanya-baca dengan composer nonaktif; WhatsApp OTP & Settings Security nonaktif dengan banner jelas.
