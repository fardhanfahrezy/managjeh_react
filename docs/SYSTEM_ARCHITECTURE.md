# System Architecture — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan arsitektur sistem **Managjeh MVP 1** yang telah diimplementasikan dan terverifikasi penuh (`Implemented & Verified` — Phase 0 s.d. Phase 7).

---

## 1. Gambaran Umum Arsitektur (`FACT`)

- **Monolith Full-Stack Modern:** Laravel `13.42.0` (PHP `8.5.12`) + Inertia.js v3 (`3.7.0`) + React `19.2.0` + TypeScript + Tailwind CSS v4 + Laravel Wayfinder `0.1.23`.
- **Database & Penyimpanan:** PostgreSQL (`managjeh` untuk runtime aplikasi, `managjeh_test` untuk pengujian otomatis). Seluruh timestamp absolut disimpan dalam **UTC** (`CarbonImmutable`), sementara tampilan dan evaluasi jadwal harian mengikuti `users.timezone` (IANA timezone, default `Asia/Jakarta`).
- **Model Kepemilikan:** _Single-user sekarang, arsitektur siap multi-user_. Seluruh tabel domain memiliki `user_id` dan dilindungi Policy. Registrasi publik dinonaktifkan (`D-01`).

---

## 2. Diagram Konteks & Komponen Sistem

```mermaid
flowchart TB
    User(["Mahasiswa (Pengguna)\nWeb Browser & Penerima Notifikasi WhatsApp"])

    subgraph App["Managjeh Application (Laravel 13 + React 19 + Inertia v3)"]
        subgraph Frontend["React 19 + TypeScript + Tailwind v4 ('The Quiet Desk')"]
            Pages["Halaman Inertia:\nDashboard, Tasks, Projects, Project Show,\nCalendar, AI Planner, Settings (Profile/Security/Appearance)"]
            Hooks["Custom Hooks:\nuseRateLimitCooldown, useFlashToast, useAppearance"]
        end

        subgraph HttpLayer["HTTP Layer (Controllers, FormRequests, Policies, RateLimiters)"]
            AuthCtrl["Fortify Auth (Login, 2FA TOTP, Passkeys)\nPublic Registration Disabled (D-01)"]
            DomainCtrl["Controllers:\nDashboardController, ProjectController, TaskController,\nCalendarEventController, AiPlannerController,\nProfileController, WhatsAppVerificationController"]
        end

        subgraph DomainLayer["Domain Services & Models"]
            Models["Eloquent Models:\nUser, Project, Task, CalendarEvent, Reminder, AiPlannerRun"]
            CalSvc["Calendar Services:\nRecurrenceExpanderService & ScheduleConflictDetector (D-08)"]
            RemSvc["Reminder Services:\nReminderSynchronizer & QuietHoursAdjuster (00:00-05:00, D-05..D-07)"]
            DashSvc["DashboardAggregatorService"]
            PlannerSvc["AiPlannerService + AiPromptBuilder\n+ Cache Circuit Breaker + JSON Validator (D-03, D-12, D-13)"]
        end

        subgraph AsyncLayer["Laravel Scheduler & Queue Worker"]
            Scheduler["Console Commands (routes/console.php):\n- reminders:dispatch-due (everyMinute)\n- ai-planner:generate-daily (dailyAt 05:30)\n- app:prune-retention-data (dailyAt 02:30)"]
            Jobs["Queue Job:\n- SendWhatsAppMessageJob (tries=3, backoff=15,60,180)"]
        end
    end

    DB[("PostgreSQL\n(11 Migrations, UTC Timestamps)")]
    WaGateway["WhatsApp Gateway\n(FonnteWhatsAppGateway / HttpWhatsAppGateway / NullWhatsAppGateway)"]
    Gemini["1. GeminiPlannerProvider (Primary - D-03)"]
    Grok["2. GrokPlannerProvider (Fallback 1 - D-03)"]
    OpenRouter["3. OpenRouterPlannerProvider (Fallback 2 - D-03)"]
    RuleFallback["4. FallbackPlannerProvider (Deterministic Local - D-03)"]

    User <-->|"HTTPS (Inertia v3 SPA)"| Pages
    Pages --> Hooks
    Pages <--> DomainCtrl
    DomainCtrl --> DomainLayer
    DomainLayer --> DB
    Scheduler --> Jobs
    Scheduler --> PlannerSvc
    Scheduler --> DB
    Jobs --> RemSvc
    Jobs --> WaGateway
    WaGateway -->|"Pengingat & OTP Satu Arah (D-14)"| User
    PlannerSvc --> Gemini
    PlannerSvc -.->|"Fallback on error/timeout/429"| Grok
    PlannerSvc -.->|"Fallback on error/timeout/429"| OpenRouter
    PlannerSvc -.->|"Fallback when all LLMs fail"| RuleFallback
```

---

## 3. Pemisahan Tanggung Jawab Backend (`app/`)

1. **Controllers (`app/Http/Controllers/`):** Menerima request, menjalankan FormRequest & Policy (`Gate::authorize(...)`), memanggil Service atau Model, dan mengembalikan response Inertia, redirect dengan flash toast, atau unduhan JSON (`ProfileController@export`).
2. **Form Requests (`app/Http/Requests/`):** Menangani validasi input, pemeriksaan kepemilikan relasi (`project_id`, `parent_id`, `task_id`), dan konversi waktu input lokal (`users.timezone`) ke UTC (`normalizedValidated()`).
3. **Policies (`app/Policies/`):** `ProjectPolicy`, `TaskPolicy`, dan `CalendarEventPolicy` memastikan pengguna hanya dapat mengakses resource dengan `user_id === $user->id`.
4. **Services (`app/Services/`):**
    - `Calendar\RecurrenceExpanderService` & `Calendar\ScheduleConflictDetector`: Mengekspansi kejadian berulang (`DAILY`/`WEEKLY`/`MONTHLY`), menerapkan `recurrence_exceptions` serta _single-occurrence overrides_, dan mendeteksi konflik waktu.
    - `Reminder\QuietHoursAdjuster` & `Reminder\ReminderSynchronizer`: Menyesuaikan waktu pengingat di luar jam tenang (`00:00–05:00` lokal) dan menyinkronkan reminder otomatis saat tugas/agenda berubah.
    - `DashboardAggregatorService`: Mengagregasi 8 bagian utama Dashboard secara efisien.
    - `AiPlanner\AiPlannerService` & `AiPromptBuilder`: Membentuk konteks tersanitasi (`AiPlannerContext`), mengelola _circuit breaker_ cache per provider, menjalankan rantai fallback, memvalidasi output JSON terhadap ID tugas aktif, dan membuat reminder yang disetujui pengguna (`approveReminder()`).
5. **Contracts & Adapters (`app/Contracts/`, `app/Services/WhatsApp/`, `app/Services/AiPlanner/`):**
    - `WhatsAppGatewayInterface` → `FonnteWhatsAppGateway`, `HttpWhatsAppGateway`, `NullWhatsAppGateway`.
    - `AiPlannerProviderInterface` → `GeminiPlannerProvider`, `GrokPlannerProvider`, `OpenRouterPlannerProvider`, `FallbackPlannerProvider`.
6. **Jobs & Console Commands (`app/Jobs/`, `app/Console/Commands/`):**
    - `SendWhatsAppMessageJob`: Mengirim pesan WhatsApp secara asynchronous dengan pemeriksaan ulang persetujuan user, keaktifan sumber, dan `idempotency_key`.
    - `ProvisionUserCommand` (`user:provision`), `DispatchDueRemindersCommand` (`reminders:dispatch-due`), `GenerateDailyAiPlansCommand` (`ai-planner:generate-daily`), dan `PruneRetentionDataCommand` (`app:prune-retention-data`).
