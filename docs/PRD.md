# Product Requirements & Technical Specification — Managjeh (MVP 1)

- **Status:** Spesifikasi Teknis Final MVP 1 (seluruh keputusan `D-01` s.d. `D-15` telah dikunci)
- **Produk:** Personal OS Mahasiswa
- **Arsitektur:** Single-user sekarang, arsitektur siap multi-user (`user_id` di seluruh tabel domain)
- **Database:** PostgreSQL (`managjeh` & `managjeh_test`, penamaan **Bahasa Inggris `snake_case`**)
- **Backend:** Laravel `13.42.0` (PHP `^8.3` / `8.5.12`)
- **Frontend:** React `19.2.0` + Inertia.js v3 (`3.7.0`) + TypeScript + Tailwind CSS v4
- **Timezone:** User-configurable (`users.timezone`, default `'Asia/Jakarta'`), seluruh timestamp disimpan dalam **UTC**

---

## 1. Ringkasan Produk

Managjeh adalah aplikasi web **Personal OS** untuk membantu mahasiswa mengelola **tugas (Tasks)**, **proyek (Projects)**, **agenda kalender & jadwal berulang (Calendar)**, dan **prioritas harian (AI Planner & Dashboard)**.

- **AI Planner** menganalisis tugas yang belum selesai, prioritas, deadline, agenda kalender, hubungan tugas dengan proyek, dan slot waktu kosong, lalu menyarankan maksimal **tiga prioritas utama harian**.
- **WhatsApp** menjadi kanal pengiriman pengingat satu arah pada MVP, sedangkan seluruh pengelolaan dan perubahan data dilakukan melalui website.

### 1.1 Tujuan MVP 1

Pengguna harus dapat:
1. Membuat dan mengelola tugas (beserta subtugas maksimal 1 tingkat sesuai `D-09`).
2. Mengelompokkan tugas ke dalam proyek dan memantau progres proyek secara akurat.
3. Membuat dan melihat agenda kalender (berjam, *all-day*, maupun berulang sesuai `D-08`) dalam tampilan harian, mingguan, dan bulanan.
4. Melihat ringkasan aktivitas terpadu pada Dashboard.
5. Menerima pengingat melalui WhatsApp (mendukung multi-reminder per task sesuai `D-05` & `D-07`, serta menghormati jam tenang `00:00–05:00` sesuai `D-06`).
6. Mendapatkan maksimal tiga prioritas harian dari AI Planner (`Gemini → Grok → OpenRouter → Rule-based Fallback` sesuai `D-03`, `D-04`, `D-12`).
7. Mengatur zona waktu (`users.timezone`), verifikasi nomor WhatsApp (`D-14`), dan preferensi notifikasi.

### 1.2 Di Luar Cakupan MVP (DILARANG DIBANGUN)

Fitur berikut **tidak boleh** ikut dibangun pada MVP 1:
- Keuangan, transaksi, anggaran, dan tagihan.
- Habit tracker dan kesehatan.
- Pelacakan tidur dan mood.
- Pembelajaran dan catatan kuliah.
- Karier dan portofolio.
- Relasi dan kontak.
- Administrasi dokumen.
- Jurnal dan refleksi mingguan.
- AI yang menghubungkan seluruh modul kehidupan di luar Task/Project/Calendar.
- Integrasi langsung bank atau e-wallet.
- Integrasi Google Calendar eksternal (`D-10`).
- Input dan perubahan data melalui balasan pesan WhatsApp.

---

## 2. Keputusan Final (`D-01` s.d. `D-15`)

Seluruh poin pada tabel di bawah ini bersifat **FINAL** dan wajib dipatuhi oleh setiap AI coding agent:

| ID | Area | Keputusan Final | Status Implementasi (`FACT`) |
| :--- | :--- | :--- | :--- |
| **`D-01`** | Registrasi Publik | **Registrasi publik dinonaktifkan.** Akun dibuat melalui provisioning manual (`php artisan user:provision`) atau undangan administrator. | `Implemented` (Phase 1) |
| **`D-02`** | WhatsApp Gateway | Menggunakan gateway pihak ketiga berbasis API melalui adapter `WhatsAppGatewayInterface` agar vendor dapat diganti tanpa mengubah business logic. | `Planned` (Phase 5) |
| **`D-03`** | Urutan Provider AI | Urutan fallback AI Planner: **`Gemini` → `Grok` → `OpenRouter` → `Rule-based Fallback`**. | `Planned` (Phase 6) |
| **`D-04`** | Model AI | Gemini menjadi provider utama dengan model seimbang antara kualitas, kecepatan, dan biaya. Nama model seluruh provider dibuat **configurable** melalui `.env` & `config/services.php`. | `Planned` (Phase 6) |
| **`D-05`** | Frekuensi Reminder | Mendukung **beberapa reminder untuk satu task** (mis. H-1 hari, H-1 jam sebelum deadline, serta pengingat tambahan berdasarkan urgensi). Pengingat yang tidak relevan lagi wajib dibatalkan (`cancelled`). | `Planned` (Phase 5) |
| **`D-06`** | Quiet Hours Default | Quiet hours ditetapkan pukul **`00:00–05:00`** berdasarkan `users.timezone`. Reminder yang jatuh pada periode tersebut ditunda ke waktu pertama yang diizinkan (`05:00` waktu lokal) selama masih relevan. | Kolom & default DB `Implemented` (Phase 1); evaluator `Planned` (Phase 5) |
| **`D-07`** | Penentuan Waktu Reminder | Perhitungan slot kosong dilakukan oleh backend dari kalender, sedangkan AI membantu menentukan prioritas, waktu/konteks reminder, dan rekomendasi aktivitas berdasarkan deadline, prioritas, dan estimasi durasi. | `Planned` (Phase 5–6) |
| **`D-08`** | Agenda Berulang | **Agenda berulang masuk MVP.** Pengguna dapat membuat aturan pengulangan (`recurrence_rule`), mengubah satu kejadian atau seluruh rangkaian, menambahkan pengecualian (`recurrence_exceptions`), serta membatalkan kejadian secara idempotent. | `Planned` (Phase 3) |
| **`D-09`** | Subtugas | Database mendukung subtugas **maksimal satu tingkat** (`tasks.parent_id`) sejak awal dan backend memvalidasi kedalaman hierarki. UI pengelolaan subtugas dapat dikembangkan setelah fitur task utama stabil. | Backend `Implemented` (Phase 1); UI `Planned` (Phase 2) |
| **`D-10`** | Kalender Eksternal | Menggunakan kalender internal sebagai sumber data utama. Integrasi Google Calendar tidak masuk MVP, tetapi skema tidak boleh menghambat penambahan integrasi eksternal di masa depan. | `Planned` (Phase 3) |
| **`D-11`** | Penghapusan Data | Menggunakan **soft delete (`deleted_at`)** pada `projects`, `tasks`, dan `calendar_events`. Penghapusan permanen dilakukan melalui kebijakan retensi dan command pembersihan terkontrol. | `projects` & `tasks` `Implemented` (Phase 1); cleanup `Planned` (Phase 7) |
| **`D-12`** | Pemicu AI Planner | AI Planner berjalan **otomatis** melalui Scheduler dan pemicu perubahan data relevan, serta dapat dipicu **manual** oleh pengguna. **AI dilarang mengubah task, proyek, atau agenda tanpa persetujuan pengguna.** | `Planned` (Phase 6) |
| **`D-13`** | Batas Penggunaan AI | Tidak menerapkan batas penggunaan harian buatan aplikasi untuk pemakaian pribadi, namun **wajib** ada penanganan rate limit, retry terbatas, fallback provider, pemantauan pemakaian di `ai_planner_runs`, dan *circuit breaker*. | `Planned` (Phase 6–7) |
| **`D-14`** | Verifikasi Nomor WhatsApp | Verifikasi nomor menggunakan OTP (atau mekanisme verifikasi alternatif dari gateway). **Nomor tidak boleh digunakan untuk mengirim pesan sebelum `whatsapp_verified_at !== null` dan `whatsapp_enabled === true`.** | Guard `User::canReceiveWhatsAppReminders()` `Implemented` (Phase 1); alur OTP `Planned` (Phase 5) |
| **`D-15`** | Retensi Log AI & WA | Mengikuti prinsip minimisasi data: **dilarang** menyimpan OTP mentah, kredensial, API key, atau percakapan sensitif. Simpan metadata minimum di `reminders` & `ai_planner_runs` dan tetapkan penghapusan otomatis berdasarkan masa retensi. | `Planned` (Phase 5–7) |

---

## 3. Domain dan Aturan Modul

### 3.1 Task Management (`tasks`)
- **Status (`App\Enums\TaskStatus`):** `todo`, `in_progress`, `completed`, `cancelled`.
- **Prioritas (`App\Enums\TaskPriority`):** `low`, `medium`, `high`, `urgent`.
- **Aturan Bisnis:**
    1. `title` wajib diisi (maks. 200 karakter).
    2. Tugas dapat berdiri sendiri (`project_id = null`) atau terhubung ke proyek milik user yang sama.
    3. `due_at` bersifat opsional (disimpan dalam UTC).
    4. Tugas `completed` dan `cancelled` bukan tugas aktif (`isActive() === false`).
    5. `completed_at` otomatis diisi saat status menjadi `completed` dan dikosongkan (`null`) saat status berubah ke selain `completed`.
    6. Subtugas maksimal 1 tingkat (`D-09`): tugas dengan `parent_id !== null` tidak boleh menjadi induk dari tugas lain, dan tugas yang sudah punya subtugas tidak boleh diberi `parent_id`.
    7. Penghapusan proyek tidak menghapus tugas (`nullOnDelete` pada FK `project_id`).
    8. Perubahan `due_at` atau status menjadi `completed`/`cancelled` wajib memperbarui atau membatalkan (`cancelled`) pengingat `pending` yang terkait (Phase 5).

### 3.2 Project Management (`projects`)
- **Status (`App\Enums\ProjectStatus`):** `active`, `completed`, `archived`.
- **Aturan Bisnis:**
    1. `name` wajib diisi (maks. 150 karakter).
    2. Progres proyek (`Project::calculateProgress()`) dihitung dari:
       `round((jumlah tugas completed / jumlah tugas yang statusnya != cancelled) * 100)`.
    3. Proyek tanpa tugas (atau semua tugasnya `cancelled`) memiliki progres `0%`.
    4. Menyelesaikan proyek (`status = completed`) mengatur `completed_at = now()`, namun **tidak** otomatis menyelesaikan tugas-tugas di dalamnya.

### 3.3 Calendar & Recurrence (`calendar_events` — `D-08`, `D-10`)
- **Jenis Waktu Agenda:**
    - Agenda berjam (`is_all_day = false`): menggunakan `starts_at` dan `ends_at` (UTC), di mana `ends_at > starts_at`.
    - Agenda seharian (`is_all_day = true`): menggunakan `start_date` dan `end_date` (`date` kalender lokal pengguna), di mana `end_date >= start_date`, sedangkan `starts_at` & `ends_at` bernilai `null`.
- **Agenda Berulang (`D-08`):**
    - Mendukung aturan pengulangan (`recurrence_rule`, mis. `FREQ=DAILY`, `FREQ=WEEKLY;BYDAY=MO,WE`, `FREQ=MONTHLY`, beserta `recurrence_until`).
    - Mendukung pengubahan atau pembatalan **satu kejadian tunggal** (*single occurrence override* melalui baris exception dengan `parent_event_id` & `original_start_at` atau daftar tanggal pengecualian di `recurrence_exceptions` JSONB) maupun **seluruh rangkaian**.
- **Integrasi Kalender:**
    - Menampilkan agenda manual/berulang sekaligus menampilkan deadline tugas (`tasks.due_at`) dalam tampilan harian, mingguan, dan bulanan sesuai `users.timezone`.
    - Konflik jadwal ditampilkan sebagai peringatan (*warning*), bukan memblokir penyimpanan.

### 3.4 Dashboard (`/dashboard`)
Menampilkan 8 komponen ringkasan terpadu (tanpa menduplikasi seluruh CRUD modul):
1. Greeting dan tanggal lokal pengguna (`users.timezone`).
2. Ringkasan tugas hari ini.
3. Tugas yang mendekati deadline (*upcoming deadlines* & *overdue*).
4. Agenda kalender hari ini (termasuk ekspansi agenda berulang hari ini).
5. Progres proyek aktif (`ProjectStatus::Active` beserta persentase progres).
6. Tiga prioritas harian dari AI Planner (`ai_planner_runs` terbaru untuk `planning_date` hari ini).
7. Status koneksi & verifikasi WhatsApp (`canReceiveWhatsAppReminders()`).
8. Ringkasan tugas yang sudah selesai hari ini/minggu ini.

### 3.5 WhatsApp Reminder (`reminders` — `D-02`, `D-05`, `D-06`, `D-07`, `D-14`)
- **Status (`ReminderStatus`):** `pending`, `processing`, `sent`, `failed`, `cancelled`.
- **Aturan Penting:**
    - Hanya mengirim jika `$user->canReceiveWhatsAppReminders()` bernilai `true` (`whatsapp_enabled && whatsapp_number && whatsapp_verified_at`).
    - Mendukung beberapa reminder untuk satu task (`D-05`, mis. H-24 jam dan H-1 jam sebelum `due_at`).
    - Menghormati jam tenang (`reminder_quiet_start` s.d. `reminder_quiet_end`, default `00:00–05:00` waktu lokal pengguna `D-06`): jadwal yang jatuh pada jam tenang digeser ke akhir jam tenang selama masih sebelum/relevan dengan waktu acara/deadline.
    - Wajib memiliki `idempotency_key` (`UNIQUE`) agar retry Queue tidak pernah mengirim pesan ganda.

### 3.6 AI Planner (`ai_planner_runs` — `D-03`, `D-04`, `D-07`, `D-12`, `D-13`)
- **Urutan Provider (`D-03`):**
    1. `GeminiPlannerProvider` (utama)
    2. `GrokPlannerProvider` (fallback 1 saat timeout / HTTP 429 / 5xx / invalid JSON)
    3. `OpenRouterPlannerProvider` (fallback 2)
    4. `FallbackPlannerProvider` (algoritma deterministik berbasis aturan prioritas, deadline terdekat, dan slot kosong kalender tanpa panggilan API eksternal)
- **Kontrak JSON Output Terkontrol:**
    ```json
    {
      "summary": "Ringkasan singkat kondisi hari ini.",
      "priorities": [
        {
          "task_id": 123,
          "reason": "Deadline paling dekat dan prioritas tinggi.",
          "suggested_action": "Selesaikan bagian pertama tugas pada slot kosong pukul 09:00.",
          "estimated_minutes": 45
        }
      ],
      "recommended_reminders": [
        {
          "task_id": 123,
          "remind_at_local": "2026-11-16 08:45:00",
          "reason": "15 menit sebelum slot kosong pagi."
        }
      ],
      "warnings": [
        {
          "type": "schedule_conflict",
          "message": "Ada dua agenda yang waktunya berdekatan."
        }
      ]
    }
    ```
- **Validasi Ketat:**
    - Maksimal 3 item pada `priorities`.
    - Setiap `task_id` **wajib** ada di daftar tugas aktif milik pengguna yang dikirim sebagai konteks (tolak jika AI mengarang `task_id`).
    - AI tidak boleh mengubah database `tasks`, `projects`, atau `calendar_events` secara langsung.

---

## 4. Urutan Implementasi Bertahap (Phase 0 s.d. Phase 7)

| Phase | Nama Tahap | Status Saat Ini (`FACT`) | Deliverables Utama |
| :--- | :--- | :--- | :--- |
| **Phase 0** | Repository Audit | `Implemented` | Audit kode aktual & penyelarasan seluruh dokumentasi dengan MVP 1 (`D-01`–`D-15`). |
| **Phase 1** | Core Data | `Implemented` | Migrasi preferensi `users`, `projects`, `tasks`; Model, Enum, Factory, Policy, FormRequest; `user:provision`; 57 Pest tests lulus. |
| **Phase 2** | Task & Project UI + Settings | `Not Implemented` | `ProjectController`, `TaskController`, perluasan `settings/profile.tsx` (timezone, WA, quiet hours), halaman `tasks/index.tsx`, `projects/index.tsx`, `projects/show.tsx`, serta feature tests CRUD. |
| **Phase 3** | Calendar & Recurrence (`D-08`) | `Not Implemented` | Migrasi `calendar_events`, model `CalendarEvent`, `RecurrenceExpanderService`, `CalendarEventPolicy`, `CalendarEventController`, halaman `calendar/index.tsx` (day/week/month view + task deadlines), test timezone & recurrence. |
| **Phase 4** | Dashboard | `Partially Implemented` | `DashboardAggregatorService`, `DashboardController`, pembaruan `resources/js/pages/dashboard.tsx` dengan 8 komponen ringkasan, empty/loading/error states, dan feature tests. |
| **Phase 5** | WhatsApp Reminder (`D-02`, `D-05`–`D-07`, `D-14`) | `Not Implemented` | Migrasi `reminders` (+ kolom OTP verifikasi WA bila diperlukan), `WhatsAppGatewayInterface`, `ReminderSchedulerService` (quiet hours `00:00–05:00`), `SendWhatsAppMessageJob`, Scheduler per menit, webhook delivery status, dan feature tests dengan `Http::fake()`. |
| **Phase 6** | AI Planner (`D-03`, `D-04`, `D-12`, `D-13`) | `Not Implemented` | Migrasi `ai_planner_runs`, `AiPlannerProviderInterface`, `GeminiPlannerProvider`, `GrokPlannerProvider`, `OpenRouterPlannerProvider`, `FallbackPlannerProvider`, circuit breaker, Scheduler otomatis & trigger perubahan data, halaman `ai-planner/index.tsx`, dan tests fallback. |
| **Phase 7** | Hardening (`D-11`, `D-15`) | `Not Implemented` | Command retensi log AI/WA & purge soft-deleted records, rate limiting endpoint sensitif, fitur ekspor data pengguna, audit aksesibilitas/responsif, dan regresi menyeluruh. |

---

## 5. Acceptance Criteria MVP 1

1. **Task:** Pengguna dapat membuat, membaca, mengubah, menyelesaikan, membatalkan, dan mengarsipkan/menghapus (soft delete) tugas & subtugas (maks. 1 tingkat); tidak dapat mengakses tugas pengguna lain.
2. **Project:** Pengguna dapat membuat, mengubah, mengarsipkan, dan menghapus proyek; progres dihitung akurat (`completed / non-cancelled`); menghapus proyek tidak menghapus histori tugas.
3. **Calendar:** Pengguna dapat mengelola agenda berjam (UTC), *all-day* (`date` lokal), dan agenda berulang (`D-08` termasuk edit/batal 1 kejadian atau seluruh rangkaian); deadline tugas tampil di kalender sesuai `users.timezone`.
4. **Dashboard:** Menampilkan greeting lokal, tugas hari ini, deadline terdekat, agenda hari ini, progres proyek aktif, 3 prioritas AI Planner, status WhatsApp, dan tugas selesai.
5. **WhatsApp:** Nomor wajib diverifikasi (`D-14`) dan `whatsapp_enabled = true`; mendukung multi-reminder (`D-05`); menghormati quiet hours `00:00–05:00` (`D-06`); idempotent saat retry; perubahan deadline/status tugas memperbarui/membatalkan reminder `pending`.
6. **AI Planner:** Menghasilkan maksimal 3 prioritas valid yang mengacu pada `task_id` nyata; berurutan `Gemini → Grok → OpenRouter → Fallback` (`D-03`); berjalan otomatis & manual (`D-12`); tidak memodifikasi data pengguna secara langsung.
7. **Timezone:** Pengguna dapat mengubah `timezone` IANA; timestamp UTC di database tidak berubah saat timezone diganti; seluruh tampilan dan evaluasi jadwal mengikuti timezone pengguna.
