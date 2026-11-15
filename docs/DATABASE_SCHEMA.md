# Database Schema — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini merupakan referensi skema database **PostgreSQL** (`managjeh` & `managjeh_test`).
Seluruh nama tabel dan kolom **wajib menggunakan Bahasa Inggris dan `snake_case`**.

- **Section 1 & 2:** Tabel yang **sudah diimplementasikan (`FACT` — Phase 0 & Phase 1)**.
- **Section 3:** Spesifikasi tabel yang **akan diimplementasikan pada Phase 3, Phase 5, dan Phase 6 (`REQUIREMENT` — siap salin untuk AI Agent)**.

---

## 1. Konfigurasi Database & Aturan Waktu (`FACT`)

- **Engine:** PostgreSQL (`DB_CONNECTION=pgsql`)
- **Database Aplikasi:** `managjeh`
- **Database Testing (`phpunit.xml`):** `managjeh_test`
- **Penyimpanan Waktu:**
    - `config('app.timezone') === 'UTC'` dan `Date::use(CarbonImmutable::class)` aktif di `AppServiceProvider`.
    - Semua kolom `timestamp` (`due_at`, `completed_at`, `starts_at`, `ends_at`, `scheduled_at`, `sent_at`, `failed_at`, `whatsapp_verified_at`, `created_at`, `updated_at`, `deleted_at`) disimpan dalam **UTC**.
    - Semua kolom `date` (`start_date`, `due_date`, `end_date`, `planning_date`) disimpan sebagai tanggal kalender lokal pengguna (`Y-m-d`).
    - Semua kolom `time` (`reminder_quiet_start`, `reminder_quiet_end`) merepresentasikan jam lokal dalam zona waktu `users.timezone`.

---

## 2. Skema Aktual yang Sudah Diimplementasikan (`FACT` — Phase 0 & Phase 1)

Saat ini terdapat **8 file migrasi** di `database/migrations/`:

1. `0001_01_01_000000_create_users_table.php` (`users`, `password_reset_tokens`, `sessions`)
2. `0001_01_01_000001_create_cache_table.php` (`cache`, `cache_locks`)
3. `0001_01_01_000002_create_jobs_table.php` (`jobs`, `job_batches`, `failed_jobs`)
4. `2024_01_01_000000_create_passkeys_table.php` (`passkeys`)
5. `2025_08_14_170933_add_two_factor_columns_to_users_table.php` (kolom 2FA di `users`)
6. `2026_11_15_000001_add_preferences_to_users_table.php` (preferensi timezone, WA, quiet hours di `users`)
7. `2026_11_15_000002_create_projects_table.php` (`projects`)
8. `2026_11_15_000003_create_tasks_table.php` (`tasks`)

### 2.1 Tabel `users` (`Implemented`)

Model: [app/Models/User.php](../app/Models/User.php)

| Kolom                       | Tipe PostgreSQL         | Nullable | Default          | Keterangan                                           |
| :-------------------------- | :---------------------- | :------- | :--------------- | :--------------------------------------------------- |
| `id`                        | `bigint` (PK)           | Tidak    | Auto             | ID pengguna                                          |
| `name`                      | `varchar(255)`          | Tidak    | —                | Nama lengkap                                         |
| `email`                     | `varchar(255)` (UNIQUE) | Tidak    | —                | Email login (disimpan huruf kecil)                   |
| `email_verified_at`         | `timestamp`             | Ya       | `null`           | Waktu verifikasi email (UTC)                         |
| `password`                  | `varchar(255)`          | Tidak    | —                | Hash bcrypt password                                 |
| `two_factor_secret`         | `text`                  | Ya       | `null`           | Secret TOTP 2FA terenkripsi                          |
| `two_factor_recovery_codes` | `text`                  | Ya       | `null`           | Recovery codes 2FA terenkripsi                       |
| `two_factor_confirmed_at`   | `timestamp`             | Ya       | `null`           | Waktu aktivasi 2FA                                   |
| `remember_token`            | `varchar(100)`          | Ya       | `null`           | Token sesi remember me                               |
| `timezone`                  | `varchar(64)`           | Tidak    | `'Asia/Jakarta'` | IANA timezone pengguna                               |
| `whatsapp_number`           | `varchar(32)` (INDEX)   | Ya       | `null`           | Nomor WA format internasional (mis. `6281234567890`) |
| `whatsapp_verified_at`      | `timestamp`             | Ya       | `null`           | Waktu nomor WA terverifikasi (`D-14`)                |
| `whatsapp_enabled`          | `boolean`               | Tidak    | `false`          | Persetujuan pengiriman pengingat WA                  |
| `reminder_quiet_start`      | `time`                  | Ya       | `'00:00:00'`     | Awal jam tenang lokal (`D-06`)                       |
| `reminder_quiet_end`        | `time`                  | Ya       | `'05:00:00'`     | Akhir jam tenang lokal (`D-06`)                      |
| `created_at`                | `timestamp`             | Ya       | `null`           | UTC                                                  |
| `updated_at`                | `timestamp`             | Ya       | `null`           | UTC                                                  |

### 2.2 Tabel `projects` (`Implemented`)

Model: [app/Models/Project.php](../app/Models/Project.php) | Enum: [app/Enums/ProjectStatus.php](../app/Enums/ProjectStatus.php) (`active`, `completed`, `archived`)

| Kolom          | Tipe PostgreSQL             | Nullable | Default    | Keterangan                                      |
| :------------- | :-------------------------- | :------- | :--------- | :---------------------------------------------- |
| `id`           | `bigint` (PK)               | Tidak    | Auto       | ID proyek                                       |
| `user_id`      | `bigint` (FK -> `users.id`) | Tidak    | —          | `cascadeOnDelete()`                             |
| `name`         | `varchar(150)`              | Tidak    | —          | Nama proyek                                     |
| `description`  | `text`                      | Ya       | `null`     | Deskripsi proyek                                |
| `status`       | `varchar(20)`               | Tidak    | `'active'` | `active` / `completed` / `archived`             |
| `start_date`   | `date`                      | Ya       | `null`     | Tanggal mulai (`Y-m-d`)                         |
| `due_date`     | `date`                      | Ya       | `null`     | Target selesai (`Y-m-d`)                        |
| `completed_at` | `timestamp`                 | Ya       | `null`     | Otomatis terisi saat `status = completed` (UTC) |
| `created_at`   | `timestamp`                 | Ya       | `null`     | UTC                                             |
| `updated_at`   | `timestamp`                 | Ya       | `null`     | UTC                                             |
| `deleted_at`   | `timestamp`                 | Ya       | `null`     | Soft delete (`D-11`)                            |

**Indexes:** `(user_id, status)`, `(user_id, due_date)`.

### 2.3 Tabel `tasks` (`Implemented`)

Model: [app/Models/Task.php](../app/Models/Task.php) | Enums: [app/Enums/TaskStatus.php](../app/Enums/TaskStatus.php) (`todo`, `in_progress`, `completed`, `cancelled`), [app/Enums/TaskPriority.php](../app/Enums/TaskPriority.php) (`low`, `medium`, `high`, `urgent`)

| Kolom          | Tipe PostgreSQL                | Nullable | Default    | Keterangan                                         |
| :------------- | :----------------------------- | :------- | :--------- | :------------------------------------------------- |
| `id`           | `bigint` (PK)                  | Tidak    | Auto       | ID tugas                                           |
| `user_id`      | `bigint` (FK -> `users.id`)    | Tidak    | —          | `cascadeOnDelete()`                                |
| `project_id`   | `bigint` (FK -> `projects.id`) | Ya       | `null`     | `nullOnDelete()` — wajib milik `user_id` yang sama |
| `parent_id`    | `bigint` (FK -> `tasks.id`)    | Ya       | `null`     | `nullOnDelete()` — subtugas maks. 1 level (`D-09`) |
| `title`        | `varchar(200)`                 | Tidak    | —          | Judul tugas                                        |
| `description`  | `text`                         | Ya       | `null`     | Deskripsi tugas                                    |
| `status`       | `varchar(20)`                  | Tidak    | `'todo'`   | `todo` / `in_progress` / `completed` / `cancelled` |
| `priority`     | `varchar(20)`                  | Tidak    | `'medium'` | `low` / `medium` / `high` / `urgent`               |
| `due_at`       | `timestamp`                    | Ya       | `null`     | Deadline absolut dalam **UTC**                     |
| `completed_at` | `timestamp`                    | Ya       | `null`     | Otomatis terisi saat `status = completed` (UTC)    |
| `sort_order`   | `integer`                      | Tidak    | `0`        | Urutan tampilan                                    |
| `created_at`   | `timestamp`                    | Ya       | `null`     | UTC                                                |
| `updated_at`   | `timestamp`                    | Ya       | `null`     | UTC                                                |
| `deleted_at`   | `timestamp`                    | Ya       | `null`     | Soft delete (`D-11`)                               |

**Indexes:** `(user_id, status)`, `(user_id, due_at)`, `(project_id, status)`, `(user_id, priority)`.

---

## 3. Spesifikasi Skema Target untuk Phase 3, Phase 5, dan Phase 6 (`PLANNED`)

> **Instruksi untuk AI Agent:** Saat mengimplementasikan Phase 3, Phase 5, dan Phase 6, gunakan definisi kolom, tipe data, foreign key, dan index di bawah ini secara presisi.

### 3.1 Tabel `calendar_events` (Phase 3 — termasuk Agenda Berulang `D-08`)

Nama file migrasi yang direkomendasikan: `database/migrations/2026_11_15_000004_create_calendar_events_table.php`

| Kolom                   | Tipe PostgreSQL                       | Nullable | Default | Keterangan                                                                                                                          |
| :---------------------- | :------------------------------------ | :------- | :------ | :---------------------------------------------------------------------------------------------------------------------------------- |
| `id`                    | `bigint` (PK)                         | Tidak    | Auto    | ID agenda                                                                                                                           |
| `user_id`               | `bigint` (FK -> `users.id`)           | Tidak    | —       | `cascadeOnDelete()`                                                                                                                 |
| `project_id`            | `bigint` (FK -> `projects.id`)        | Ya       | `null`  | `nullOnDelete()` — wajib milik `user_id` yang sama                                                                                  |
| `task_id`               | `bigint` (FK -> `tasks.id`)           | Ya       | `null`  | `nullOnDelete()` — wajib milik `user_id` yang sama                                                                                  |
| `parent_event_id`       | `bigint` (FK -> `calendar_events.id`) | Ya       | `null`  | `cascadeOnDelete()` — diisi jika baris ini adalah perubahan 1 kejadian (_single-occurrence override_) dari agenda berulang (`D-08`) |
| `recurrence_id`         | `varchar(64)`                         | Ya       | `null`  | Identifier tanggal/waktu kejadian asli yang di-override (mis. `'2026-11-20'` atau ISO UTC string) (`D-08`)                          |
| `title`                 | `varchar(200)`                        | Tidak    | —       | Judul agenda                                                                                                                        |
| `description`           | `text`                                | Ya       | `null`  | Deskripsi agenda                                                                                                                    |
| `starts_at`             | `timestamp`                           | Ya       | `null`  | Waktu mulai UTC (wajib jika `is_all_day = false`, `null` jika `is_all_day = true`)                                                  |
| `ends_at`               | `timestamp`                           | Ya       | `null`  | Waktu selesai UTC (`ends_at > starts_at` jika `is_all_day = false`)                                                                 |
| `start_date`            | `date`                                | Ya       | `null`  | Tanggal awal lokal (wajib jika `is_all_day = true`, `null` jika `is_all_day = false`)                                               |
| `end_date`              | `date`                                | Ya       | `null`  | Tanggal akhir lokal (`end_date >= start_date` jika `is_all_day = true`)                                                             |
| `is_all_day`            | `boolean`                             | Tidak    | `false` | Penanda agenda sepanjang hari                                                                                                       |
| `is_cancelled`          | `boolean`                             | Tidak    | `false` | Penanda jika kejadian tunggal dibatalkan (`D-08`)                                                                                   |
| `recurrence_rule`       | `varchar(255)`                        | Ya       | `null`  | Aturan pengulangan RFC 5545 ringkas, mis. `FREQ=DAILY;INTERVAL=1`, `FREQ=WEEKLY;BYDAY=MO,WE`, `FREQ=MONTHLY` (`D-08`)               |
| `recurrence_until`      | `date`                                | Ya       | `null`  | Batas akhir pengulangan tanggal lokal (`D-08`)                                                                                      |
| `recurrence_exceptions` | `jsonb`                               | Ya       | `null`  | Array tanggal/waktu kejadian yang dikecualikan/dihapus dari rangkaian, mis. `["2026-11-25"]` (`D-08`)                               |
| `created_at`            | `timestamp`                           | Ya       | `null`  | UTC                                                                                                                                 |
| `updated_at`            | `timestamp`                           | Ya       | `null`  | UTC                                                                                                                                 |
| `deleted_at`            | `timestamp`                           | Ya       | `null`  | Soft delete (`D-11`)                                                                                                                |

**Indexes Wajib `calendar_events`:**

- `(user_id, starts_at, ends_at)`
- `(user_id, start_date, end_date)`
- `(parent_event_id, recurrence_id)`

### 3.2 Tabel `reminders` (Phase 5 — Multi-Reminder `D-05` & Idempotency)

Nama file migrasi yang direkomendasikan: `database/migrations/2026_11_15_000005_create_reminders_table.php`
Enum yang direkomendasikan: `App\Enums\ReminderStatus` (`pending`, `processing`, `sent`, `failed`, `cancelled`).

| Kolom                 | Tipe PostgreSQL                       | Nullable | Default      | Keterangan                                                                                                |
| :-------------------- | :------------------------------------ | :------- | :----------- | :-------------------------------------------------------------------------------------------------------- |
| `id`                  | `bigint` (PK)                         | Tidak    | Auto         | ID reminder                                                                                               |
| `user_id`             | `bigint` (FK -> `users.id`)           | Tidak    | —            | `cascadeOnDelete()`                                                                                       |
| `task_id`             | `bigint` (FK -> `tasks.id`)           | Ya       | `null`       | `cascadeOnDelete()` — tugas terkait                                                                       |
| `calendar_event_id`   | `bigint` (FK -> `calendar_events.id`) | Ya       | `null`       | `cascadeOnDelete()` — agenda terkait                                                                      |
| `channel`             | `varchar(20)`                         | Tidak    | `'whatsapp'` | Kanal notifikasi (`whatsapp`)                                                                             |
| `reminder_type`       | `varchar(40)`                         | Tidak    | `'custom'`   | Jenis trigger, mis. `before_24h`, `before_1h`, `ai_recommended`, `event_start`, `custom` (`D-05`, `D-07`) |
| `scheduled_at`        | `timestamp`                           | Tidak    | —            | Waktu pengiriman dalam **UTC**                                                                            |
| `status`              | `varchar(20)`                         | Tidak    | `'pending'`  | `pending` / `processing` / `sent` / `failed` / `cancelled`                                                |
| `message`             | `text`                                | Tidak    | —            | Konten pesan pengingat (tanpa data sensitif `D-15`)                                                       |
| `provider_message_id` | `varchar(150)`                        | Ya       | `null`       | ID pesan dari WhatsApp gateway                                                                            |
| `sent_at`             | `timestamp`                           | Ya       | `null`       | Waktu berhasil dikirim (UTC)                                                                              |
| `failed_at`           | `timestamp`                           | Ya       | `null`       | Waktu gagal dikirim (UTC)                                                                                 |
| `failure_reason`      | `text`                                | Ya       | `null`       | Ringkasan error aman (tanpa token/kredensial `D-15`)                                                      |
| `idempotency_key`     | `varchar(100)` (UNIQUE)               | Tidak    | —            | Kunci unik pengiriman (mis. `task:{id}:{type}:{timestamp}`)                                               |
| `created_at`          | `timestamp`                           | Ya       | `null`       | UTC                                                                                                       |
| `updated_at`          | `timestamp`                           | Ya       | `null`       | UTC                                                                                                       |

**Indexes Wajib `reminders`:**

- `(status, scheduled_at)`
- `(user_id, status)`
- Unique index pada `idempotency_key`

### 3.3 Tabel `ai_planner_runs` (Phase 6 — AI Planner `D-03`, `D-04`, `D-12`, `D-15`)

Nama file migrasi yang direkomendasikan: `database/migrations/2026_11_15_000006_create_ai_planner_runs_table.php`
Enum yang direkomendasikan: `App\Enums\AiPlannerRunStatus` (`pending`, `success`, `failed`).

| Kolom            | Tipe PostgreSQL             | Nullable | Default     | Keterangan                                                                            |
| :--------------- | :-------------------------- | :------- | :---------- | :------------------------------------------------------------------------------------ |
| `id`             | `bigint` (PK)               | Tidak    | Auto        | ID eksekusi planner                                                                   |
| `user_id`        | `bigint` (FK -> `users.id`) | Tidak    | —           | `cascadeOnDelete()`                                                                   |
| `planning_date`  | `date`                      | Tidak    | —           | Tanggal rencana berdasarkan `users.timezone` (`Y-m-d`)                                |
| `trigger_type`   | `varchar(30)`               | Tidak    | `'manual'`  | `manual` / `scheduled` / `data_change` (`D-12`)                                       |
| `provider`       | `varchar(50)`               | Tidak    | —           | `gemini` / `grok` / `openrouter` / `fallback` (`D-03`)                                |
| `model`          | `varchar(100)`              | Ya       | `null`      | Nama model yang digunakan (`D-04`)                                                    |
| `status`         | `varchar(20)`               | Tidak    | `'pending'` | `pending` / `success` / `failed`                                                      |
| `input_snapshot` | `jsonb`                     | Ya       | `null`      | Konteks ringkas yang dikirim (tanpa nomor WA/token/kredensial `D-15`)                 |
| `result`         | `jsonb`                     | Ya       | `null`      | Hasil JSON tervalidasi (`summary`, `priorities`, `recommended_reminders`, `warnings`) |
| `error_message`  | `text`                      | Ya       | `null`      | Ringkasan error yang telah disanitasi (`D-15`)                                        |
| `created_at`     | `timestamp`                 | Ya       | `null`      | UTC                                                                                   |
| `updated_at`     | `timestamp`                 | Ya       | `null`      | UTC                                                                                   |

**Indexes Wajib `ai_planner_runs`:**

- `(user_id, planning_date)`
- `(status, created_at)`

---

## 4. Diagram Relasi Entitas Lengkap (ERD MVP 1)

```mermaid
erDiagram
    USERS ||--o{ PROJECTS : "hasMany (cascadeOnDelete)"
    USERS ||--o{ TASKS : "hasMany (cascadeOnDelete)"
    USERS ||--o{ CALENDAR_EVENTS : "hasMany (cascadeOnDelete)"
    USERS ||--o{ REMINDERS : "hasMany (cascadeOnDelete)"
    USERS ||--o{ AI_PLANNER_RUNS : "hasMany (cascadeOnDelete)"
    USERS ||--o{ PASSKEYS : "hasMany (cascadeOnDelete)"

    PROJECTS ||--o{ TASKS : "hasMany (nullOnDelete)"
    PROJECTS ||--o{ CALENDAR_EVENTS : "hasMany (nullOnDelete)"

    TASKS ||--o{ TASKS : "subtasks via parent_id (nullOnDelete, max depth 1)"
    TASKS ||--o{ CALENDAR_EVENTS : "hasMany (nullOnDelete)"
    TASKS ||--o{ REMINDERS : "hasMany (cascadeOnDelete)"

    CALENDAR_EVENTS ||--o{ CALENDAR_EVENTS : "overrides via parent_event_id (cascadeOnDelete)"
    CALENDAR_EVENTS ||--o{ REMINDERS : "hasMany (cascadeOnDelete)"
```
