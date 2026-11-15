# Frontend Architecture — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan arsitektur frontend **React 19 + Inertia.js v3 + TypeScript + Tailwind CSS v4** untuk **Managjeh MVP 1**, lengkap dengan kontrak TypeScript, daftar 8 halaman MVP, dan panduan **PAGE ARCHITECT** agar AI Coding Agent dapat mengimplementasikan UI Phase 2–6 secara konsisten.

---

## 1. Technology Stack & Konvensi (`FACT`)

- **React:** `^19.2.0` (`babel-plugin-react-compiler` aktif, `strictMode: true`)
- **Inertia.js v3:** `@inertiajs/react` `^3.0.0`, `@inertiajs/vite` `^3.0.0` (menggunakan `<Form>`, `<Link>`, `router`, dan `useHttp()`; **Axios tidak digunakan**)
- **Routing TypeScript:** **Laravel Wayfinder** (`0.1.23`) menghasilkan fungsi route/action bertipe di `@/routes/*` dan `@/actions/*`
- **Styling & Primitives:** Tailwind CSS v4 (`resources/css/app.css`), Radix UI primitives di `resources/js/components/ui/*` (`card`, `button`, `dialog`, `input`, `select`, `badge`, `checkbox`, `sidebar`, `skeleton`, `sonner`, `tooltip`, `tabs`/`toggle-group`), dan Lucide React icons

---

## 2. Kontrak TypeScript (`resources/js/types/`)

### 2.1 Tipe `User` di `resources/js/types/auth.ts` (`Implemented` di Phase 1)

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

### 2.2 Tipe Domain MVP 1 (Untuk dibuat di `resources/js/types/models.ts` pada Phase 2–6)

```ts
export type ProjectStatus = 'active' | 'completed' | 'archived';
export type TaskStatus = 'todo' | 'in_progress' | 'completed' | 'cancelled';
export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';
export type ReminderStatus =
    | 'pending'
    | 'processing'
    | 'sent'
    | 'failed'
    | 'cancelled';
export type AiPlannerRunStatus = 'pending' | 'success' | 'failed';

export type Project = {
    id: number;
    user_id: number;
    name: string;
    description: string | null;
    status: ProjectStatus;
    start_date: string | null; // YYYY-MM-DD
    due_date: string | null; // YYYY-MM-DD
    completed_at: string | null;
    progress: number; // 0 - 100
    completed_tasks_count?: number;
    non_cancelled_tasks_count?: number;
    tasks?: Task[];
    created_at: string;
    updated_at: string;
};

export type Task = {
    id: number;
    user_id: number;
    project_id: number | null;
    parent_id: number | null;
    title: string;
    description: string | null;
    status: TaskStatus;
    priority: TaskPriority;
    due_at: string | null; // ISO UTC
    due_at_local?: string | null; // Formatted in user's timezone
    completed_at: string | null;
    sort_order: number;
    project?: Pick<Project, 'id' | 'name' | 'status'> | null;
    subtasks?: Task[];
    created_at: string;
    updated_at: string;
};

export type CalendarEvent = {
    id: number;
    user_id: number;
    project_id: number | null;
    task_id: number | null;
    parent_event_id: number | null;
    recurrence_id: string | null;
    title: string;
    description: string | null;
    starts_at: string | null; // ISO UTC (when is_all_day = false)
    ends_at: string | null; // ISO UTC (when is_all_day = false)
    start_date: string | null; // YYYY-MM-DD (when is_all_day = true)
    end_date: string | null; // YYYY-MM-DD (when is_all_day = true)
    is_all_day: boolean;
    is_cancelled: boolean;
    recurrence_rule: string | null;
    recurrence_until: string | null;
    project?: Pick<Project, 'id' | 'name'> | null;
    task?: Pick<Task, 'id' | 'title' | 'status'> | null;
};

export type AiPlannerPriorityItem = {
    task_id: number;
    task_title?: string;
    reason: string;
    suggested_action: string;
    estimated_minutes: number;
};

export type AiPlannerWarning = {
    type: string;
    message: string;
};

export type AiPlannerResult = {
    summary: string;
    priorities: AiPlannerPriorityItem[];
    recommended_reminders?: Array<{
        task_id: number;
        remind_at_local: string;
        reason: string;
    }>;
    warnings: AiPlannerWarning[];
};

export type AiPlannerRun = {
    id: number;
    planning_date: string;
    provider: string;
    model: string | null;
    status: AiPlannerRunStatus;
    result: AiPlannerResult | null;
    error_message: string | null;
    created_at: string;
};
```

---

## 3. Daftar 8 Halaman MVP & Spesifikasi PAGE ARCHITECT

Setiap halaman wajib menyediakan: **Loading state**, **Empty state**, **Error state**, **Success toast (`sonner`)**, **Validation feedback (`InputError`)**, **Responsive layout**, dan **Keyboard accessibility**.

| No    | Halaman MVP                        | Path Komponen (`resources/js/pages/`)        | Phase         | Status Aktual (`FACT`)                                                                              |
| :---- | :--------------------------------- | :------------------------------------------- | :------------ | :-------------------------------------------------------------------------------------------------- |
| **1** | **Dashboard**                      | `dashboard.tsx` (`/dashboard`)               | Phase 4       | `Partially Implemented` (masih `PlaceholderPattern`)                                                |
| **2** | **Tasks List**                     | `tasks/index.tsx` (`/tasks`)                 | Phase 2       | `Not Implemented`                                                                                   |
| **3** | **Task Detail / Form Modal**       | `components/tasks/task-form-dialog.tsx`      | Phase 2       | `Not Implemented`                                                                                   |
| **4** | **Projects List**                  | `projects/index.tsx` (`/projects`)           | Phase 2       | `Not Implemented`                                                                                   |
| **5** | **Project Detail**                 | `projects/show.tsx` (`/projects/{project}`)  | Phase 2       | `Not Implemented`                                                                                   |
| **6** | **Calendar**                       | `calendar/index.tsx` (`/calendar`)           | Phase 3       | `Not Implemented`                                                                                   |
| **7** | **AI Planner**                     | `ai-planner/index.tsx` (`/ai-planner`)       | Phase 6       | `Not Implemented`                                                                                   |
| **8** | **Settings (Timezone & WhatsApp)** | `settings/profile.tsx` (`/settings/profile`) | Phase 1–2 & 5 | `Partially Implemented` (Backend siap di Phase 1; input UI ditambahkan di Phase 2 & OTP di Phase 5) |

### Detail Komponen Per Halaman

1. **Dashboard (`dashboard.tsx` — Phase 4):**
    - Menampilkan 8 bagian: (1) Greeting & tanggal lokal pengguna, (2) Ringkasan tugas hari ini, (3) Tugas mendekati deadline, (4) Agenda kalender hari ini, (5) Progres proyek aktif, (6) Tiga prioritas AI Planner, (7) Status koneksi WhatsApp, (8) Ringkasan tugas selesai.
2. **Tasks (`tasks/index.tsx` & `task-form-dialog.tsx` — Phase 2):**
    - Filter bar berdasarkan `status` (`todo`, `in_progress`, `completed`, `cancelled`), `priority` (`low`, `medium`, `high`, `urgent`), dan `project_id`.
    - Mendukung aksi cepat ubah status dan tampilan subtugas 1 tingkat (`D-09`).
3. **Projects (`projects/index.tsx` & `projects/show.tsx` — Phase 2):**
    - Grid kartu proyek dengan progress bar (`calculateProgress()`), rentang tanggal `start_date`–`due_date`, dan badge status (`active`, `completed`, `archived`).
    - Halaman detail (`projects/show.tsx`) menampilkan daftar tugas proyek terkait dan tombol tambah tugas ke proyek tersebut.
4. **Calendar (`calendar/index.tsx` — Phase 3):**
    - Switcher tampilan `Day`, `Week`, `Month` berdasarkan `auth.user.timezone`.
    - Menampilkan agenda berjam, agenda _all-day_, kejadian agenda berulang (`D-08`), dan deadline tugas (`tasks.due_at`).
    - Saat mengedit/menghapus agenda berulang, tampilkan pilihan: **"Hanya kejadian ini"** (`edit_scope = 'single'`) atau **"Seluruh rangkaian"** (`edit_scope = 'series'`).
5. **AI Planner (`ai-planner/index.tsx` — Phase 6):**
    - Menampilkan rencana hari ini (`summary`, maksimal 3 `priorities` beserta `reason`, `suggested_action`, `estimated_minutes`, dan `warnings`).
    - Tombol **"Generate / Perbarui Rencana Hari Ini"** serta badge provider yang digunakan (`gemini`, `grok`, `openrouter`, atau `fallback`).
6. **Settings Timezone & WhatsApp (`settings/profile.tsx` — Phase 2 & Phase 5):**
    - Pilih IANA `timezone`, input `whatsapp_number`, toggle `whatsapp_enabled`, input `reminder_quiet_start` (`00:00`) & `reminder_quiet_end` (`05:00`), serta alur kirim & verifikasi OTP WhatsApp (`D-14`).
