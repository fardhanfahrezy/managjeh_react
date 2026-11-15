# Business Rules — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini menetapkan seluruh aturan bisnis yang wajib ditegakkan oleh backend Laravel 13 dan frontend React Inertia pada **Managjeh MVP 1**, mencakup aturan yang **sudah diimplementasikan (`FACT` — Phase 0 & Phase 1)** serta aturan untuk **Phase 2 s.d. Phase 7 (`REQUIREMENT` — terikat keputusan `D-01` s.d. `D-15`)**.

---

## 1. Autentikasi, Provisioning & Kepemilikan Data (`D-01` — `Implemented`)

1. **Registrasi Publik Dinonaktifkan (`D-01`):**
    - `Features::registration()` telah dihapus dari `config/fortify.php`.
    - Endpoint `GET /register` dan `POST /register` mengembalikan `404 Not Found`.
    - Pembuatan akun dilakukan secara manual melalui CLI `php artisan user:provision --name="..." --email="..." --password="..." --timezone="Asia/Jakarta"` ([ProvisionUserCommand.php](../app/Console/Commands/ProvisionUserCommand.php)).
2. **Kepemilikan Data (`user_id` Multi-User-Ready):**
    - Seluruh tabel domain (`projects`, `tasks`, `calendar_events`, `reminders`, `ai_planner_runs`) memiliki kolom `user_id`.
    - Controller **dilarang** menerima `user_id` dari input request; selalu gunakan `$request->user()->id`.
    - Seluruh query wajib difilter dengan `where('user_id', $request->user()->id)` atau melalui relasi `$request->user()->tasks()` / `$request->user()->projects()`.
    - Setiap mutasi data wajib melewati Policy (`ProjectPolicy`, `TaskPolicy`, `CalendarEventPolicy`).

---

## 2. Preferensi Pengguna, Timezone & Jam Tenang (`D-06`, `D-14` — `Implemented` di Phase 1)

1. **Timezone Pengguna (`users.timezone`):**
    - Default awal: `'Asia/Jakarta'`.
    - Wajib divalidasi dengan aturan Laravel `'timezone:all'` (daftar IANA timezone valid di PHP).
    - Mengubah `users.timezone` **tidak boleh** mengubah nilai timestamp UTC yang sudah tersimpan di database; hanya tampilan dan evaluasi waktu lokal yang menyesuaikan.
2. **Jam Tenang / Quiet Hours (`D-06`):**
    - Default: `reminder_quiet_start = '00:00:00'` dan `reminder_quiet_end = '05:00:00'` dalam zona waktu `users.timezone`.
    - Tidak ada pengingat WhatsApp yang boleh dikirim antara pukul `00:00` hingga `05:00` waktu lokal pengguna (atau sesuai rentang `reminder_quiet_start`–`reminder_quiet_end` yang diatur pengguna).
    - Jika jadwal pengiriman `scheduled_at` jatuh di dalam jam tenang, waktu pengiriman otomatis digeser ke akhir jam tenang (`reminder_quiet_end`, mis. `05:00` waktu lokal → konversi ke UTC), **selama waktu tersebut masih relevan** (masih sebelum deadline/acara dimulai). Jika setelah digeser sudah melewati deadline/acara, reminder dibatalkan (`cancelled`).
3. **Verifikasi Nomor WhatsApp & Persetujuan (`D-14`):**
    - Kolom `whatsapp_number` divalidasi dengan format internasional (`regex:/^\+?[1-9]\d{7,14}$/`).
    - Setiap kali pengguna mengubah `whatsapp_number` pada profil, `whatsapp_verified_at` otomatis di-reset menjadi `null` ([ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php)).
    - Jika `whatsapp_number` dikosongkan, `whatsapp_enabled` otomatis menjadi `false`.
    - Pengiriman pesan WhatsApp hanya diizinkan apabila `$user->canReceiveWhatsAppReminders()` bernilai `true` (`whatsapp_enabled === true && whatsapp_number !== null && whatsapp_verified_at !== null`).

---

## 3. Task Management (`tasks`, `D-09`, `D-11` — Core Data `Implemented`)

1. **Status & Prioritas:**
    - Status valid (`App\Enums\TaskStatus`): `todo`, `in_progress`, `completed`, `cancelled`.
    - Prioritas valid (`App\Enums\TaskPriority`): `low`, `medium`, `high`, `urgent`.
2. **Definisi Tugas Aktif:**
    - Tugas berstatus `todo` atau `in_progress` adalah **tugas aktif** (`$task->isActive() === true`).
    - Tugas berstatus `completed` atau `cancelled` **bukan** tugas aktif (`$task->isActive() === false`) dan tidak dihitung sebagai beban kerja yang belum selesai.
3. **Konsistensi `completed_at`:**
    - Ketika `status` berubah menjadi `completed`, `completed_at` otomatis diisi `now()` (UTC) jika belum terisi.
    - Ketika `status` berubah ke selain `completed` (`todo`, `in_progress`, `cancelled`), `completed_at` otomatis di-reset menjadi `null`.
4. **Relasi Proyek Lintas-User Ditolak:**
    - `project_id` bersifat opsional (`nullable`).
    - Jika diisi, `project_id` wajib mengacu pada proyek milik `user_id` yang sama (divalidasi di `StoreTaskRequest`, `UpdateTaskRequest`, dan `Task::booted()`).
5. **Aturan Subtugas Maksimal 1 Tingkat (`D-09`):**
    - `parent_id` bersifat opsional (`nullable`) dan wajib mengacu pada task milik `user_id` yang sama.
    - Task tidak boleh menunjuk dirinya sendiri (`parent_id !== id`).
    - Task induk tidak boleh merupakan subtugas (`$parent->parent_id === null`).
    - Task yang sudah memiliki subtugas (`$task->subtasks()->exists()`) tidak boleh dijadikan subtugas dari task lain.
6. **Sinkronisasi Reminder Saat Task Berubah (Phase 5):**
    - Saat `due_at` diubah, seluruh reminder `pending` untuk task tersebut wajib dijadwalkan ulang sesuai `due_at` baru.
    - Saat task diubah menjadi `completed`, `cancelled`, atau di-soft-delete, seluruh reminder `pending` terkait wajib diubah menjadi `cancelled`.

---

## 4. Project Management (`projects`, `D-11` — Core Data `Implemented`)

1. **Status Proyek (`App\Enums\ProjectStatus`):** `active`, `completed`, `archived`.
2. **Validasi Rentang Tanggal:**
    - `start_date` dan `due_date` bersifat opsional (`Y-m-d`).
    - Jika keduanya diisi, `due_date` wajib `>= start_date`.
3. **Rumus Progres Proyek (`Project::calculateProgress()`):**
    - Penyebut (_denominator_): jumlah seluruh tugas dalam proyek yang statusnya **bukan `cancelled`**.
    - Pembilang (_numerator_): jumlah tugas dalam proyek yang statusnya **`completed`**.
    - Jika penyebut = `0` (proyek belum punya tugas atau semua tugasnya `cancelled`), progres = `0%`.
    - Jika penyebut > `0`, progres = `round((completed / non_cancelled) * 100)`.
4. **Kemandirian Status Tugas & Proyek:**
    - Mengubah status proyek menjadi `completed` mengisi `projects.completed_at = now()`, namun **tidak** otomatis mengubah status tugas-tugas di dalamnya menjadi `completed`.
    - Menghapus proyek (soft delete maupun force delete) **tidak boleh** menghapus tugas-tugas di dalamnya (`tasks.project_id` menggunakan `nullOnDelete`).

---

## 5. Calendar & Agenda Berulang (`calendar_events`, `D-08`, `D-10` — Phase 3)

1. **Representasi Waktu Berdasarkan `is_all_day`:**
    - Jika `is_all_day === false` (agenda berjam):
        - `starts_at` dan `ends_at` **wajib diisi** (disimpan dalam UTC) dan `ends_at > starts_at`.
        - `start_date` dan `end_date` **wajib `null`**.
    - Jika `is_all_day === true` (agenda seharian):
        - `start_date` dan `end_date` **wajib diisi** (`date` lokal `Y-m-d`) dan `end_date >= start_date`.
        - `starts_at` dan `ends_at` **wajib `null`**.
2. **Aturan Agenda Berulang (`D-08`):**
    - Agenda induk menyimpan `recurrence_rule` (mis. `FREQ=DAILY`, `FREQ=WEEKLY;BYDAY=MO,WE`, `FREQ=MONTHLY`) dan opsional `recurrence_until` (`date`).
    - Ekspansi kejadian (_occurrences_) pada rentang tanggal kalender yang diminta dilakukan secara idempotent oleh `RecurrenceExpanderService`.
    - **Mengubah seluruh rangkaian:** memperbarui baris agenda induk.
    - **Mengubah satu kejadian (_single occurrence_):** membuat/memperbarui baris override di `calendar_events` dengan `parent_event_id = $master->id` dan `recurrence_id = '<tanggal/waktu kejadian asli>'`.
    - **Membatalkan/menghapus satu kejadian:** menambahkan identifier kejadian ke `recurrence_exceptions` (JSONB array) pada agenda induk atau menandai baris override dengan `is_cancelled = true`.
3. **Deteksi Konflik Jadwal:**
    - Apabila pengguna membuat/mengubah agenda berjam yang beririsan (`starts_at < existing.ends_at && ends_at > existing.starts_at`) dengan agenda lain milik pengguna tersebut, sistem **tetap mengizinkan penyimpanan** dan menyertakan informasi konflik sebagai peringatan (_warning_).

---

## 6. WhatsApp Reminder (`reminders`, `D-02`, `D-05`, `D-06`, `D-07`, `D-14` — Phase 5)

1. **Satu Arah (Notification Channel Only pada MVP):**
    - WhatsApp hanya digunakan untuk mengirim pengingat dan kode verifikasi OTP.
    - Tidak ada pemrosesan perintah perubahan data (seperti `"selesai"` atau `"tunda"`) dari pesan masuk WhatsApp pada MVP 1.
2. **Multi-Reminder per Task (`D-05`, `D-07`):**
    - Satu tugas yang memiliki `due_at` dapat menghasilkan beberapa entri di `reminders` (default: **H-24 jam** [`before_24h`] dan **H-1 jam** [`before_1h`] sebelum `due_at`, ditambah opsional waktu rekomendasi AI [`ai_recommended`]).
    - Jika waktu pengingat (mis. H-24 jam) sudah lewat pada saat tugas dibuat, pengingat tersebut tidak perlu dibuat (hanya buat pengingat yang jadwalnya masih di masa depan).
3. **Idempotency & Siklus Status:**
    - `idempotency_key` bersifat `UNIQUE` (contoh format: `task:{task_id}:{reminder_type}:{scheduled_at_timestamp}`).
    - Scheduler setiap menit mengambil reminder dengan `status = 'pending'` dan `scheduled_at <= now()`, mengubah statusnya secara atomik menjadi `'processing'`, lalu mendispatch `SendWhatsAppMessageJob`.
    - Sebelum mengirim ke gateway, `SendWhatsAppMessageJob` memeriksa ulang bahwa reminder masih berstatus `'processing'`, sumber task/event belum selesai/dihapus, dan `$user->canReceiveWhatsAppReminders()` bernilai `true`.

---

## 7. AI Planner (`ai_planner_runs`, `D-03`, `D-04`, `D-12`, `D-13`, `D-15` — Phase 6)

1. **Urutan Fallback Provider (`D-03`):**
    - `GeminiPlannerProvider` → `GrokPlannerProvider` → `OpenRouterPlannerProvider` → `FallbackPlannerProvider`.
    - Perpindahan ke provider berikutnya dipicu oleh: HTTP `429` (rate limit), HTTP `5xx`, koneksi/request timeout, circuit breaker terbuka, atau struktur JSON output tidak valid.
2. **Validasi Output AI:**
    - Output wajib berupa JSON dengan key `summary` (string), `priorities` (array maks. 3 item), dan `warnings` (array).
    - Setiap item di `priorities` wajib memiliki `task_id` (int), `reason` (string), `suggested_action` (string), dan `estimated_minutes` (int > 0).
    - `task_id` wajib diverifikasi ada di dalam kumpulan ID task aktif milik user yang dikirim pada `input_snapshot`. Jika AI mengembalikan `task_id` fiktif, output dianggap **tidak valid** dan memicu fallback.
3. **Larangan Mutasi Otomatis (`D-12`) & Privasi (`D-15`):**
    - AI Planner **dilarang** mengubah status/deadline task, proyek, atau agenda secara langsung tanpa aksi persetujuan eksplisit pengguna.
    - Prompt AI hanya boleh berisi daftar task aktif (`id`, `title`, `priority`, `due_at`, `project_name`), agenda kalender hari terkait, dan slot waktu kosong; **dilarang keras** menyertakan `whatsapp_number`, `email`, `password`, atau token/kredensial.
