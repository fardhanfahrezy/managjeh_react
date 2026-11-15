# System Architecture — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan arsitektur sistem **Managjeh MVP 1**, memisahkan antara komponen yang **sudah berjalan (`FACT` — Phase 0 & Phase 1)** dan komponen yang **direncanakan (`REQUIREMENT` — Phase 2 s.d. Phase 7)**.

---

## 1. Gambaran Umum Arsitektur

- **Monorepo Full-Stack:** Laravel `13.42.0` (PHP `8.5.12`) + Inertia.js v3 (`3.7.0`) + React `19.2.0` + TypeScript + Tailwind CSS v4 + Laravel Wayfinder `0.1.23`.
- **Database:** PostgreSQL (`managjeh` & `managjeh_test`). Semua timestamp absolut disimpan dalam **UTC**, sedangkan tampilan dan evaluasi jadwal mengikuti `users.timezone` (IANA timezone, default `Asia/Jakarta`).
- **Model Kepemilikan:** *Single-user sekarang, arsitektur siap multi-user*. Seluruh tabel bisnis memiliki `user_id` dan dilindungi Policy. Registrasi publik dinonaktifkan (`D-01`).

---

## 2. Diagram Konteks & Komponen Sistem (MVP 1)

```mermaid
flowchart TB
    User(["Mahasiswa (Pengguna)\nWeb Browser & Penerima Notifikasi WhatsApp"])

    subgraph App["Managjeh Application (Laravel 13 + React 19 + Inertia v3)"]
        subgraph Frontend["React 19 + TypeScript + Tailwind v4"]
            Pages["8 Halaman MVP:\nDashboard, Tasks, Task Form,\nProjects, Project Detail, Calendar,\nAI Planner, Settings"]
        end

        subgraph HttpLayer["HTTP Layer (Controllers, FormRequests, Policies)"]
            AuthCtrl["Fortify Auth (Login, 2FA, Passkeys)\nPublic Registration Disabled (D-01)"]
            DomainCtrl["Domain Controllers:\nProject, Task, CalendarEvent,\nDashboard, AiPlanner, Profile/WhatsApp"]
        end

        subgraph DomainLayer["Domain Services & Models"]
            CoreModels["Models (Phase 1 Implemented):\nUser, Project, Task\n+ Planned: CalendarEvent, Reminder, AiPlannerRun"]
            CalSvc["Calendar & RecurrenceExpanderService (D-08)"]
            RemSvc["ReminderSynchronizer & QuietHoursAdjuster (00:00-05:00, D-05..D-07)"]
            PlannerSvc["AiPlannerService + Circuit Breaker + Schema Validator (D-03, D-12, D-13)"]
        end

        subgraph AsyncLayer["Laravel Scheduler & Queue Worker"]
            Scheduler["Scheduler:\n- Dispatch due reminders (everyMinute)\n- Auto AI Planner run (D-12)\n- Prune retention data (D-11, D-15)"]
            Jobs["Queue Jobs:\n- SendWhatsAppMessageJob\n- GenerateAiDailyPlanJob"]
        end
    end

    DB[("PostgreSQL 18\n(UTC Timestamps)")]
    WaGateway["WhatsApp Gateway API\n(via WhatsAppGatewayInterface - D-02)"]
    Gemini["1. Google Gemini API (Primary - D-03)"]
    Grok["2. Grok API (Fallback 1 - D-03)"]
    OpenRouter["3. OpenRouter API (Fallback 2 - D-03)"]
    RuleFallback["4. Deterministic Rule-Based Planner (Fallback 3 - D-03)"]

    User <-->|"HTTPS (Inertia SPA)"| Pages
    Pages <--> DomainCtrl
    DomainCtrl --> DomainLayer
    DomainLayer --> DB
    Scheduler --> Jobs
    Jobs --> RemSvc
    Jobs --> PlannerSvc
    RemSvc --> WaGateway
    WaGateway -->|"Notifikasi Pengingat & OTP (D-14)"| User
    PlannerSvc --> Gemini
    PlannerSvc -.->|"Fallback on failure"| Grok
    PlannerSvc -.->|"Fallback on failure"| OpenRouter
    PlannerSvc -.->|"Fallback on failure"| RuleFallback
```

---

## 3. Pemisahan Tanggung Jawab Backend

1. **Controller (`app/Http/Controllers/`):** Menerima request, memanggil FormRequest & Policy (`$this->authorize(...)`), memanggil Service/Model, dan mengembalikan response Inertia atau redirect dengan flash toast.
2. **Form Request (`app/Http/Requests/`):** Menangani validasi input, pemeriksaan kepemilikan relasi (`project_id`, `parent_id`, `task_id`), dan normalisasi timezone input ke UTC.
3. **Policy (`app/Policies/`):** Memastikan pengguna hanya dapat mengakses dan memodifikasi resource dengan `user_id === $user->id`.
4. **Service (`app/Services/`):** Menangani logika lintas-model seperti ekspansi agenda berulang (`D-08`), sinkronisasi pengingat & jam tenang (`00:00–05:00` `D-06`), agregasi dashboard, serta orkestrasi fallback AI Planner (`D-03`).
5. **Job (`app/Jobs/`):** Menjalankan pengiriman WhatsApp dan pemanggilan AI secara asynchronous dengan idempotency, retry terbatas, dan backoff.
6. **Contract / Provider (`app/Contracts/`):** Mengisolasi vendor WhatsApp (`WhatsAppGatewayInterface`) dan provider AI (`AiPlannerProviderInterface`).
