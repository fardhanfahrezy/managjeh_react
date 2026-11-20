# Managjeh (Personal OS Mahasiswa) — Panduan Utama AI Coding Agent

> **Sumber Kebenaran Tertinggi (`Source of Truth`):** Source code aktual di repository (`app/`, `database/`, `routes/`, `resources/`, `config/`) dan hasil pengujian otomatis (`tests/`) adalah sumber kebenaran tertinggi untuk perilaku aplikasi. Selalu inspeksi implementasi yang sudah ada sebelum memodifikasi kode.

---

## 1. Project Overview & Status Implementasi (`FACT`)

- **Produk:** Aplikasi web **Personal OS Mahasiswa** (_single-user sekarang, arsitektur siap multi-user_) dengan **Website Managjeh sebagai pusat kendali & interaksi AI** dan **WhatsApp sebagai kanal pengingat outbound**.
- **Status Proyek:** **MVP 1 (Phase 0 s.d. Phase 7 + Optimasi Performa), MVP 2 (Website AI Chat Hub, Internal MCP Server, Proposal Approval & Audit Trail, Perluasan WhatsApp Outbound & Multi-Channel, serta HTTP Security Headers CSP Nonce), dan Offline-First PWA (`D-20`–`D-25`) telah selesai diimplementasikan dan terverifikasi (`Implemented & Verified` — 16 migrasi aktif, 116 Pest tests lulus dengan 839 assertions & 2 skipped `RegistrationTest`, PHPStan level 7 0 error, `tsc --noEmit` 0 error, `vp check` lulus).** Arah **MVP 3** (Habits/Tidur, Finance/Anggaran/Tagihan, Refleksi Mingguan) telah dikonfirmasi (`CONFIRMED`) dan berstatus **`Planned`**.

| Phase                 | Cakupan                                                                                                                            | Status Aktual (`FACT`)   | Implementasi Aktual di Repository                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| :-------------------- | :--------------------------------------------------------------------------------------------------------------------------------- | :----------------------- | :-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Phase 0**           | Repository Audit & Baseline                                                                                                        | `Implemented & Verified` | Audit struktur, sinkronisasi spesifikasi MVP 1 & keputusan `D-01`–`D-15`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| **Phase 1**           | Core Data (`users`, `projects`, `tasks`, Auth `D-01`, Policy, Request, Test)                                                       | `Implemented & Verified` | Migrasi awal, model `User`, `Project`, `Task`, enum `ProjectStatus`, `TaskStatus`, `TaskPriority`, `ProjectPolicy`, `TaskPolicy`, FormRequests, dan command `php artisan user:provision`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| **Phase 2**           | Task & Project UI + Settings Timezone/WA                                                                                           | `Implemented & Verified` | `ProjectController`, `TaskController`, halaman `tasks/index.tsx`, `projects/index.tsx`, `projects/show.tsx`, komponen `task-form-dialog.tsx` & `task-row-item.tsx`, serta perluasan `settings/profile.tsx`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| **Phase 3**           | Calendar & Agenda Berulang (`D-08`)                                                                                                | `Implemented & Verified` | Migrasi `calendar_events`, model `CalendarEvent`, `CalendarEventPolicy`, `RecurrenceExpanderService`, `ScheduleConflictDetector`, `CalendarEventController`, dan halaman `calendar/index.tsx` (`Day`/`Week`/`Month`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| **Phase 4**           | Dashboard Aggregation                                                                                                              | `Implemented & Verified` | `DashboardAggregatorService`, `DashboardController`, dan halaman `dashboard.tsx` dengan 8 bagian ringkasan terpadu.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| **Phase 5**           | WhatsApp Reminder & OTP Verification (`D-02`, `D-05`–`D-07`, `D-14`)                                                               | `Implemented & Verified` | Migrasi `reminders`, model `Reminder`, `WhatsAppGatewayInterface` (`FonnteWhatsAppGateway`, `HttpWhatsAppGateway`, `NullWhatsAppGateway`), `QuietHoursAdjuster`, `ReminderSynchronizer`, `SendWhatsAppMessageJob`, `DispatchDueRemindersCommand`, dan `WhatsAppVerificationController`.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| **Phase 6**           | AI Planner (`Gemini → Grok → OpenRouter → Fallback`, `D-03`, `D-04`, `D-12`)                                                       | `Implemented & Verified` | Migrasi `ai_planner_runs`, model `AiPlannerRun`, `AiPlannerProviderInterface` (`GeminiPlannerProvider`, `GrokPlannerProvider`, `OpenRouterPlannerProvider`, `FallbackPlannerProvider`), `AiPromptBuilder`, `AiPlannerService` (circuit breaker & validator), `GenerateDailyAiPlansCommand`, `AiPlannerController`, dan halaman `ai-planner/index.tsx`.                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| **Phase 7**           | Hardening, Retensi Log (`D-11`, `D-15`), Data Export & Regression                                                                  | `Implemented & Verified` | `PruneRetentionDataCommand` (`app:prune-retention-data`), rate limiters (`ai-planner`, `whatsapp-otp`, `data-export`), hook `use-rate-limit-cooldown.ts`, endpoint `ProfileController@export`, UI **Data & Privasi**, serta optimasi query/indeks (12 migrasi aktif) & **84 Pest tests lulus (`569` assertions, `2` skipped `RegistrationTest`)**.                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| **MVP 2**             | Website AI Chat + AI Orchestrator + Internal MCP + Outbound Multi-Channel + Security Headers (`D-16`–`D-19`)                       | `Implemented & Verified` | 2 migrasi baru (`ai_preferences`, `notification_preferences`, `ai_chat_conversations`, `ai_chat_messages`, `ai_action_proposals`), Internal MCP Server (`app/Mcp/` — 6 read tools + 4 write tools non-destruktif tanpa `user_id`), `AiChatOrchestrator` (`Gemini → Grok → OpenRouter`), `AiActionProposalService` (TTL 24 jam + batch approval + audit trail), `OutboundChannelManager` + `MetaCloudWhatsAppGateway`, `event_prep` H-15m, `reminders:sync-recurring`, `reminders:schedule-digests`, UI `/ai-chat` & `/settings/ai`, dan `AddSecurityHeaders` (CSP nonce + HSTS). **109 Pest tests lulus (`787` assertions, `2` skipped)**.                                                                                                                                                    |
| **Offline-First PWA** | PWA Manifest, Service Worker, IndexedDB (`Dexie.js`), Outbox Sync API, OCC Conflict Resolution & Account Isolation (`D-20`–`D-25`) | `Implemented & Verified` | 2 migrasi baru (`uuid` + `version` pada `projects`/`tasks`/`calendar_events`, serta `sync_operations` & `sync_tombstones`), `SyncController` (`POST /sync/push`, `GET /sync/pull`, `GET /sync/status`), `SyncService` (idempotency, OCC + field-level auto-merge, tombstone anti-zombie), `public/manifest.webmanifest` + `public/sw.js` + `/offline-shell`, `resources/js/lib/offline/*` (`Dexie.js` per user `managjeh_offline_u{id}`, generic `OfflineEntityRepository`, TS port `RecurrenceExpander` & `ScheduleConflictDetector`, `LocalDashboardAggregator`, `LocalPriorityPlanner`, `SyncCoordinator`), `OfflineProvider`, `SyncStatusBadge`, `ConflictResolutionDialog`, `LogoutOutboxDialog`, `OfflineLeaseExpiredScreen`. **116 Pest tests lulus (`839` assertions, `2` skipped)**. |
| **MVP 3**             | Modul Personal OS Lanjutan: Habits/Tidur, Finance & Refleksi Mingguan (`D-18`)                                                     | `Planned` (`CONFIRMED`)  | Belum diimplementasikan. Mencakup `get_my_habits`, `get_my_finances`, pengingat kebiasaan/tidur/tagihan/anggaran aman (tanpa info finansial berlebihan & dilarang menyimpan kredensial bank/e-wallet), serta evaluasi mingguan.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |

---

## 2. Technology Stack (`FACT`)

- **Backend:** PHP `^8.3` (teruji pada `8.5.12`), Laravel Framework `^13.17` (`13.42.0`), Inertia.js Laravel `^3.0` (`3.7.0`), Laravel Fortify `^1.37.2` (`1.42.0` — 2FA TOTP & Passkeys/WebAuthn, registrasi publik **dinonaktifkan** sesuai `D-01`), Laravel Wayfinder `^0.1.14` (`0.1.23`), `@laravel/passkeys` `^0.2.0`.
- **Database:** PostgreSQL (`DB_CONNECTION=pgsql`), database utama `managjeh`, database pengujian `managjeh_test` (`phpunit.xml`).
- **Frontend:** React `^19.2.0` (`babel-plugin-react-compiler`), `@inertiajs/react` `^3.0.0`, TypeScript `^5.7.2`, Tailwind CSS `v4` (`@tailwindcss/vite`), Radix UI primitives, Lucide React icons, Sonner toast.
- **Tooling & Quality:** Vite+ (`vite-plus` `1.4.0` / `vp`), Pest `^5.3` (`5.3.5`), Larastan/PHPStan `^3.9` (`3.15.0`, level 7), Laravel Pint `^1.27` (`1.32.3`).

---

## 3. Repository Architecture

- `app/Contracts/` — Interface abstraksi eksternal: [AiPlannerProviderInterface.php](app/Contracts/AiPlannerProviderInterface.php), [WhatsAppGatewayInterface.php](app/Contracts/WhatsAppGatewayInterface.php), dan [OutboundChannelInterface.php](app/Contracts/OutboundChannelInterface.php).
- `app/Mcp/` — Internal MCP Server dalam monolith (`McpToolRegistry`, `McpToolExecutor`, `McpToolResult`, serta 6 read tools & 4 write tools non-destruktif di `app/Mcp/Tools/`).
- `app/Enums/` — Backed string enums (`ProjectStatus`, `TaskStatus`, `TaskPriority`, `ReminderStatus`, `AiPlannerRunStatus`, `AiChatRole`, `AiActionProposalStatus`).
- `app/Models/` — Model Eloquent (`User`, `Project`, `Task`, `CalendarEvent`, `Reminder`, `AiPlannerRun`, `AiChatConversation`, `AiChatMessage`, `AiActionProposal`) dengan atribut `#[Fillable([...])]` atau `$fillable` eksplisit dan invariant domain di `booted()`.
- `app/Policies/` — Otorisasi kepemilikan (`ProjectPolicy`, `TaskPolicy`, `CalendarEventPolicy`, `AiChatConversationPolicy`, `AiActionProposalPolicy`).
- `app/Http/Requests/` — FormRequest terpisah per domain (`Projects/*`, `Tasks/*`, `Calendar/*`, `AiChat/*`, `Settings/*`).
- `app/Http/Controllers/` — Controller tipis yang memanggil FormRequest, Policy, dan Service, lalu mengembalikan `Inertia::render()`, `RedirectResponse`, atau `JsonResponse` (AI Chat & ekspor data).
- `app/Services/` — Logika bisnis domain (`Ai/*`, `AiChat/*`, `AiPlanner/*`, `Calendar/*`, `Notifications/*`, `Reminder/*`, `WhatsApp/*`, `DashboardAggregatorService.php`).
- `app/Jobs/` & `app/Console/Commands/` — Pemrosesan latar belakang (`SendWhatsAppMessageJob`) dan CLI commands (`user:provision`, `reminders:dispatch-due`, `reminders:sync-recurring`, `reminders:schedule-digests`, `ai-planner:generate-daily`, `app:prune-retention-data`).
- `resources/js/` — Aplikasi SPA React 19 + Inertia v3 (`pages/`, `components/`, `hooks/`, `layouts/`, `types/`, serta `actions/` & `routes/` hasil generate Wayfinder).

---

## 4. Coding & Naming Conventions

1. **Bahasa Kode & Database:** Seluruh nama tabel (`users`, `projects`, `tasks`, `calendar_events`, `reminders`, `ai_planner_runs`), kolom, class, method, variabel, dan route wajib menggunakan **Bahasa Inggris** (`snake_case` untuk database/route payload, `camelCase` untuk method/properti PHP & JS, `PascalCase` untuk class/komponen/tipe). Jangan pernah menggunakan nama tabel/kolom Bahasa Indonesia seperti `schedules`, `judul`, `tenggat`, atau `kirim_pada`.
2. **Bahasa Antarmuka (UI Copy):** Label antarmuka, pesan toast, dan teks bantuan pengguna menggunakan **Bahasa Indonesia** yang jelas dan ringkas.
3. **Strict Types & Return Types:** Gunakan explicit parameter type hints, return types, dan PHPDoc array shapes yang lolos **PHPStan Level 7** dan `tsc --noEmit`.

---

## 5. Frontend Architecture (`resources/js/`)

- **Wayfinder Routing:** Jangan menulis URL hardcoded di komponen React. Gunakan helper yang di-generate oleh Laravel Wayfinder dari `@/routes/*` atau `@/actions/*`. Jika route/controller berubah, jalankan `php artisan wayfinder:generate --with-form`.
- **Layout Wrapper:** Halaman utama dirender di dalam `AppLayout` (sidebar kiri + area konten utama). Konsistensi wrapper konten utama halaman adalah `<div className="flex flex-1 flex-col gap-5 p-4 md:p-6">` (atau `gap-6`).
- **Rate Limit Cooldown UX:** Untuk aksi POST sensitif yang dibatasi throttle (seperti kirim OTP WhatsApp dan generate AI Planner), gunakan hook [use-rate-limit-cooldown.ts](resources/js/hooks/use-rate-limit-cooldown.ts) pada callback `onHttpException` Inertia agar respons HTTP `429` menampilkan hitung mundur inline (`role="status" aria-live="polite"`) alih-alih modal error mentah.

---

## 6. Backend Architecture (`app/`)

- **Immutable Dates:** `Date::use(CarbonImmutable::class)` aktif secara global di `AppServiceProvider`.
- **Service & Adapter Pattern:**
    - Pengiriman WhatsApp wajib melalui `WhatsAppGatewayInterface` (diikat di `AppServiceProvider` ke `FonnteWhatsAppGateway`, `HttpWhatsAppGateway`, atau `NullWhatsAppGateway` sesuai `config('services.whatsapp.driver')`).
    - Pembuatan rencana AI wajib melalui `AiPlannerService` yang mengorkestrasi `GeminiPlannerProvider` → `GrokPlannerProvider` → `OpenRouterPlannerProvider` → `FallbackPlannerProvider`.
- **Sinkronisasi Otomatis:** Perubahan `Task` atau `CalendarEvent` otomatis memicu `ReminderSynchronizer` untuk menjadwalkan ulang atau membatalkan reminder `pending`.

---

## 7. Database & Timezone Conventions

- **UTC di Database:** `config('app.timezone') === 'UTC'`. Seluruh kolom `timestamp` (`due_at`, `completed_at`, `starts_at`, `ends_at`, `scheduled_at`, `sent_at`, `failed_at`, `whatsapp_verified_at`) wajib disimpan dalam **UTC**.
- **Tanggal Lokal:** Kolom `date` (`start_date`, `due_date`, `end_date`, `recurrence_until`, `planning_date`) disimpan sebagai tanggal kalender lokal pengguna (`Y-m-d`).
- **Zona Waktu Pengguna:** Gunakan `$user->timezone` (IANA timezone, default `'Asia/Jakarta'`) saat mengonversi input waktu lokal ke UTC (mis. di `StoreTaskRequest::normalizedValidated()`, `StoreCalendarEventRequest::normalizedValidated()`) dan saat mengevaluasi "hari ini" atau _quiet hours_ (`00:00–05:00`).
- **Soft Deletes (`D-11`):** Digunakan pada `Project`, `Task`, dan `CalendarEvent`. Penghapusan permanen (`forceDelete`) dilakukan oleh `PruneRetentionDataCommand` setelah > 30 hari.

---

## 8. API, Routing & Rate Limiting Conventions

- Seluruh route aplikasi didefinisikan di [routes/web.php](routes/web.php) dan [routes/settings.php](routes/settings.php) (lihat daftar lengkap 27 route di [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md)).
- Named rate limiters didefinisikan di [AppServiceProvider.php](app/Providers/AppServiceProvider.php):
    - `ai-planner`: 10 request / menit per user ID (`POST /ai-planner/generate`).
    - `ai-chat`: 20 request / menit per user ID (`POST /ai-chat/messages`).
    - `whatsapp-otp`: 5 request / menit per user ID (`POST /settings/whatsapp/send-otp`).
    - `data-export`: 5 request / menit per user ID (`GET /settings/profile/export`).
    - `sync`: 60 request / menit per user ID (`POST /sync/push`, `GET /sync/pull`, `GET /sync/status`).

---

## 9. Validation & Authorization Rules

1. **Jangan pernah mempercayai `user_id` dari request frontend.** Selalu ambil pengguna dari `$request->user()`.
2. **Batasi setiap query** pada data milik `$request->user()->id` (atau gunakan relasi `$request->user()->tasks()`, `$request->user()->projects()`, `$request->user()->calendarEvents()`).
3. **Wajib panggil Policy** (`$this->authorize('view'|'update'|'delete', $model)`) pada setiap aksi controller yang mengakses resource spesifik.
4. **Cegah relasi lintas-user:** Pengaitan `project_id`, `parent_id`, atau `task_id` wajib memverifikasi bahwa resource target dimiliki oleh `user_id` yang sama.

---

## 10. Security & Privacy Requirements (`D-01`, `D-14`, `D-15`)

- **Registrasi Publik Nonaktif (`D-01`):** `Features::registration()` tidak diaktifkan di `config/fortify.php`. Akun baru dibuat melalui `php artisan user:provision`.
- **Verifikasi WhatsApp (`D-14`):** Jangan pernah mengirim pengingat WhatsApp jika `$user->canReceiveWhatsAppReminders()` bernilai `false`. Mengubah `whatsapp_number` wajib mereset `whatsapp_verified_at = null`.
- **Perlindungan Rahasia & Sanitasi Prompt (`D-15`):**
    - Jangan pernah menyimpan kode OTP mentah di database atau log (simpan hanya hash SHA-256 di cache dengan TTL 5 menit).
    - Jangan pernah mengirim `email`, `whatsapp_number`, `password`, token, atau kredensial ke provider AI (`AiPlannerContext::toSanitizedSnapshot()`).
    - Endpoint ekspor data (`ProfileController@export`) hanya boleh mengekspor kolom yang di-whitelist secara eksplisit.

---

## 11. Testing & Code Quality Requirements

Setiap perubahan kode **wajib** lulus seluruh pemeriksaan berikut sebelum dinyatakan selesai:

```bash
# 1. Format kode PHP (Laravel Pint)
php vendor/bin/pint --dirty --format agent

# 2. Static analysis PHPStan Level 7 (wajib 0 error)
php vendor/bin/phpstan analyse --memory-limit=512M

# 3. Pemeriksaan tipe TypeScript (wajib 0 error)
npx.cmd tsc --noEmit

# 4. Pemeriksaan format & lint frontend/markdown (Vite+ vp check)
npx.cmd vp check --fix

# 5. Seluruh test suite Pest (PostgreSQL managjeh_test)
php artisan test

# Atau jalankan seluruh pipeline CI sekaligus:
composer ci:check
```

---

## 12. UI/UX & Accessibility Requirements (`DESIGN.md` — "The Quiet Desk")

- Ikuti spesifikasi visual di [DESIGN.md](DESIGN.md): keluarga font tunggal lokal **Inter** dengan `tabular-nums` pada angka/waktu/persentase, kanvas hangat _Bright Snow_ (`#fafaf9`), permukaan kartu _Pure White_ (`#ffffff`) berborder `1px` _Hairline Stone_ (`#e7e5e4`), aksen utama _Shamrock Emerald_ (`#059669`) hanya untuk aksi primer, serta _Ochre Amber_ (`#d97706`) / _Crimson Alert_ (`#dc2626`) khusus untuk sinyal status nyata.
- Setiap tombol yang hanya berisi ikon (`size="icon"`) **wajib** memiliki atribut `aria-label` yang deskriptif.
- Pastikan tata letak responsif mulai dari lebar mobile `375px` hingga desktop tanpa _horizontal overflow_.

---

## 13. Ringkasan Keputusan Final (`D-01` s.d. `D-25`) — Mengikat Seluruh Pengembangan

| ID         | Keputusan Final (`Implemented & Verified` / `Planned`)                                                                                                                                                                                                                                                                            |
| :--------- | :-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **`D-01`** | Registrasi publik dinonaktifkan (`config/fortify.php`). Akun dibuat melalui `php artisan user:provision`.                                                                                                                                                                                                                         |
| **`D-02`** | WhatsApp Gateway berbasis adapter `WhatsAppGatewayInterface` (`FonnteWhatsAppGateway`, `HttpWhatsAppGateway`, `NullWhatsAppGateway`). Satu arah (notifikasi & OTP).                                                                                                                                                               |
| **`D-03`** | Urutan fallback AI Planner: `Gemini` → `Grok` → `OpenRouter` → `Rule-based Fallback`.                                                                                                                                                                                                                                             |
| **`D-04`** | Model AI configurable melalui `.env` & `config/services.php` (`GEMINI_MODEL`, `GROK_MODEL`, `OPENROUTER_MODEL`).                                                                                                                                                                                                                  |
| **`D-05`** | Multi-reminder per task (`before_24h`, `before_1h`, `ai_recommended`) dan per agenda (`event_start`). Reminder yang tidak relevan otomatis dibatalkan (`cancelled`).                                                                                                                                                              |
| **`D-06`** | Quiet hours default `00:00–05:00` waktu lokal pengguna (`users.timezone`); jadwal yang jatuh pada jam tenang digeser ke akhir jam tenang oleh `QuietHoursAdjuster`.                                                                                                                                                               |
| **`D-07`** | Backend menghitung slot waktu kosong dari kalender; AI merekomendasikan prioritas & waktu pengingat berdasarkan deadline, prioritas, dan slot kosong.                                                                                                                                                                             |
| **`D-08`** | Agenda berulang (`recurrence_rule`, `recurrence_until`, `recurrence_exceptions`, serta override satu kejadian via `parent_event_id` & `recurrence_id`).                                                                                                                                                                           |
| **`D-09`** | Subtugas maksimal 1 tingkat (`tasks.parent_id`), divalidasi di `StoreTaskRequest`, `UpdateTaskRequest`, dan `Task::booted()`.                                                                                                                                                                                                     |
| **`D-10`** | Kalender internal sebagai sumber utama pada MVP 1 (tanpa sinkronisasi Google Calendar eksternal).                                                                                                                                                                                                                                 |
| **`D-11`** | Soft delete (`deleted_at`) pada `projects`, `tasks`, dan `calendar_events`; pembersihan permanen > 30 hari oleh `app:prune-retention-data`.                                                                                                                                                                                       |
| **`D-12`** | AI Planner berjalan otomatis (Scheduler harian `05:30`) maupun manual; AI tidak pernah memodifikasi task/project/agenda tanpa klik persetujuan pengguna.                                                                                                                                                                          |
| **`D-13`** | Reliabilitas AI dengan timeout (`AI_PLANNER_TIMEOUT`), circuit breaker cache (3 gagal / 300 dtk), rate limit (`10/menit`), dan pencatatan di `ai_planner_runs`.                                                                                                                                                                   |
| **`D-14`** | Verifikasi nomor WhatsApp wajib via OTP (`WhatsAppVerificationController`) sebelum `$user->canReceiveWhatsAppReminders()` bernilai `true`.                                                                                                                                                                                        |
| **`D-15`** | Minimisasi data, sanitasi prompt AI, whitelist ekspor JSON (`ProfileController@export`), dan pemangkasan log otomatis > 30 hari.                                                                                                                                                                                                  |
| **`D-16`** | **(`Planned`)** Website adalah pusat interaksi AI; WhatsApp hanya kanal pengingat outbound. **AI Orchestrator & Internal MCP Server** dibangun di dalam monolith Laravel memakai autentikasi sesi backend (AI dilarang akses DB langsung & dilarang menentukan `user_id`).                                                        |
| **`D-17`** | **(`Planned`)** Halaman `/ai-planner` tetap dipertahankan berdampingan dengan **AI Chat** baru (riwayat percakapan, rencana tindakan _propose → approve → execute_, audit trail tindakan penting, dan preferensi AI).                                                                                                             |
| **`D-18`** | **(`Planned`)** Urutan roadmap: **MVP 2** memprioritaskan Website AI Chat + Internal MCP di atas modul aktif (`Tasks`, `Projects`, `Calendar`, `Dashboard`) & perluasan WA/multi-channel (**Telegram**); **MVP 3** mencakup modul **Habits/Tidur**, **Finance/Anggaran/Tagihan**, dan **Refleksi Mingguan**.                      |
| **`D-19`** | **(`Implemented & Verified`)** Riwayat percakapan AI mengikuti retensi **30 hari** (`D-15`) + hapus manual; WA tetap outbound via adapter (`WhatsAppGatewayInterface`: Fonnte & Meta Cloud aktif, siap tambah Telegram); larangan menyimpan kredensial bank/PIN/OTP/e-wallet dan minimisasi info finansial di WA.                 |
| **`D-20`** | **(`Implemented & Verified`)** Arsitektur **Full Local-First** untuk `Dashboard`, `Tasks`, `Projects`, dan `Calendar`: seluruh baca & tulis melalui IndexedDB (`Dexie.js`) + Outbox atomik; `SyncCoordinator` menyinkronkan otomatis ke `POST /sync/push` & `GET /sync/pull`.                                                     |
| **`D-21`** | **(`Implemented & Verified`)** **Offline Session Lease 7 hari** (`managjeh_offline_registry`, tanpa menyimpan password/OTP/token); jika habis saat offline layar terkunci tanpa menghapus Outbox tertunda; jika `401`/`419` saat online sync dijeda tanpa menghapus Outbox.                                                       |
| **`D-22`** | **(`Implemented & Verified`)** Isolasi multi-akun via DB IndexedDB terpisah per user (`managjeh_offline_u{id}`). Saat Logout dengan Outbox kosong DB langsung dihapus; bila ada perubahan tertunda muncul `LogoutOutboxDialog`; pergantian akun otomatis menghapus DB akun sebelumnya.                                            |
| **`D-23`** | **(`Implemented & Verified`)** Porting penuh `RecurrenceExpander` & `ScheduleConflictDetector` ke TypeScript (`resources/js/lib/offline/calendar/`) sehingga `Day`/`Week`/`Month`, `DAILY`/`WEEKLY`/`MONTHLY`, exception/override kejadian tunggal, dan deteksi konflik berjalan identik saat offline.                            |
| **`D-24`** | **(`Implemented & Verified`)** Optimistic Concurrency (`version`) + **Hybrid Field-Level Auto-Merge** + `ConflictResolutionDialog` (auto-merge untuk field tidak bertabrakan; konflik tabrakan field & Edit-vs-Delete diselesaikan pengguna) + `sync_tombstones` (retensi 180 hari) anti-zombie pasca `app:prune-retention-data`. |
| **`D-25`** | **(`Implemented & Verified`)** Perilaku fitur online-only saat offline: `/ai-planner` menampilkan snapshot terakhir + tombol **Susun Prioritas Lokal (Offline)**; `/ai-chat` mode read-only dengan composer nonaktif; WhatsApp OTP & Settings Security nonaktif dengan banner jelas.                                              |

---

## 14. Documentation Maintenance Rules & Indeks Dokumen

Setiap kali Anda mengubah arsitektur, skema database, route, variabel `.env`, atau command CLI, **Anda wajib memperbarui dokumentasi terkait pada giliran kerja yang sama**:

1. [README.md](README.md) — Dokumentasi utama instalasi, konfigurasi, command, dan arsitektur.
2. [CLAUDE.md](CLAUDE.md) — Ringkasan orientasi cepat untuk Claude Code.
3. [DESIGN.md](DESIGN.md) & [PRODUCT.md](PRODUCT.md) — Design System **"The Quiet Desk"** & konteks produk.
4. [docs/PRD.md](docs/PRD.md) — Spesifikasi Teknis MVP 1, Arah MVP 2–3, Keputusan `D-01`–`D-19`, & Riwayat Phase 0–7.
5. [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — Skema PostgreSQL (12 migrasi aktif) & kebijakan retensi.
6. [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — Aturan bisnis terverifikasi untuk seluruh modul.
7. [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) — Arsitektur modular Laravel 13 + Inertia v3 + Queue/Scheduler.
8. [docs/FRONTEND_ARCHITECTURE.md](docs/FRONTEND_ARCHITECTURE.md) — Kontrak TypeScript `models.ts`, halaman React, dan hooks.
9. [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md) — Daftar 27 route aplikasi, `WhatsAppGatewayInterface`, & `AiPlannerProviderInterface`.
10. [docs/USER_FLOW.md](docs/USER_FLOW.md) — Diagram alur pengguna & background jobs.
11. [docs/SECURITY.md](docs/SECURITY.md) — Otorisasi `user_id`, OTP hashing, sanitasi prompt AI, rate limiting, & privasi data.
12. [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Konfigurasi produksi, Queue Worker, Scheduler, & CI/CD.
13. [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) — Referensi implementasi Phase 0–7 & playbook pengembangan fitur baru bagi AI Agent.

---

## 15. Mandatory Rules for AI Coding Agents

- **Inspect existing implementation before modifying code.**
- **Follow existing architecture and conventions.**
- **Avoid unnecessary dependencies.**
- **Do not duplicate existing services, hooks, or components.**
- **Do not modify unrelated modules.**
- **Preserve backward compatibility unless explicitly requested.**
- **Never expose credentials, tokens, OTPs, or sensitive user information.**
- **Never claim tests passed without executing them.**
- **Update relevant documentation whenever architecture, behavior, configuration, or commands change.**
- **Run relevant tests and quality checks (`composer ci:check`) before declaring work complete.**
- **Report assumptions, limitations, and unverified behavior honestly.**

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
