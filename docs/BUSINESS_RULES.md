# Business Rules — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan seluruh aturan bisnis yang **telah diimplementasikan dan terverifikasi (`Implemented & Verified`)** pada backend Laravel 13 dan frontend React Inertia untuk **Managjeh MVP 1** (`D-01` s.d. `D-15`).

---

## 1. Autentikasi, Provisioning & Kepemilikan Data (`D-01` — `Implemented & Verified`)

1. **Registrasi Publik Dinonaktifkan (`D-01`):**
    - `Features::registration()` dihapus dari [config/fortify.php](../config/fortify.php).
    - Endpoint `GET /register` dan `POST /register` mengembalikan `404 Not Found` (diverifikasi di [RegistrationTest.php](../tests/Feature/Auth/RegistrationTest.php)).
    - Pembuatan atau pembaruan akun dilakukan melalui CLI `php artisan user:provision --name="..." --email="..." --password="..." --timezone="Asia/Jakarta"` ([ProvisionUserCommand.php](../app/Console/Commands/ProvisionUserCommand.php)).
2. **Kepemilikan Data (`user_id` Multi-User-Ready):**
    - Seluruh tabel domain (`projects`, `tasks`, `calendar_events`, `reminders`, `ai_planner_runs`) memiliki kolom `user_id`.
    - Controller tidak pernah menerima `user_id` dari input request; pengguna selalu diambil dari `$request->user()`.
    - Setiap operasi baca/tulis/hapus pada resource spesifik wajib melewati Policy ([ProjectPolicy.php](../app/Policies/ProjectPolicy.php), [TaskPolicy.php](../app/Policies/TaskPolicy.php), [CalendarEventPolicy.php](../app/Policies/CalendarEventPolicy.php)).

---

## 2. Preferensi Pengguna, Timezone & Jam Tenang (`D-06`, `D-14` — `Implemented & Verified`)

1. **Timezone Pengguna (`users.timezone`):**
    - Default awal: `'Asia/Jakarta'`.
    - Divalidasi dengan aturan `'timezone:all'` di [ProfileValidationRules.php](../app/Concerns/ProfileValidationRules.php).
    - Mengubah `users.timezone` tidak mengubah nilai timestamp UTC yang tersimpan di database; hanya tampilan lokal, batas "hari ini", dan jam tenang yang menyesuaikan.
2. **Jam Tenang / Quiet Hours (`D-06`):**
    - Default: `reminder_quiet_start = '00:00:00'` dan `reminder_quiet_end = '05:00:00'` dalam zona waktu `users.timezone`.
    - [QuietHoursAdjuster.php](../app/Services/Reminder/QuietHoursAdjuster.php) mengevaluasi apakah waktu pengiriman (`scheduled_at` UTC) jatuh di dalam rentang jam tenang lokal pengguna (mendukung rentang dalam hari yang sama maupun lintas tengah malam). Jika jatuh pada jam tenang, jadwal digeser ke akhir jam tenang (`reminder_quiet_end` waktu lokal → dikonversi ke UTC).
3. **Verifikasi Nomor WhatsApp & Persetujuan (`D-14`):**
    - Kolom `whatsapp_number` divalidasi dengan `regex:/^\+?[1-9]\d{7,14}$/`.
    - Setiap kali pengguna mengubah `whatsapp_number` di [ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php), `whatsapp_verified_at` otomatis di-reset menjadi `null`. Jika `whatsapp_number` dikosongkan, `whatsapp_enabled` otomatis menjadi `false`.
    - [WhatsAppVerificationController.php](../app/Http/Controllers/Settings/WhatsAppVerificationController.php) menghasilkan OTP 6 digit, menyimpan hanya `hash('sha256', $otp)` di cache selama 5 menit (maksimal 5 percobaan), dan mengirim OTP melalui `WhatsAppGatewayInterface::sendVerificationOtp()`.
    - Pengiriman pengingat WhatsApp hanya diizinkan jika `$user->canReceiveWhatsAppReminders()` bernilai `true` (`whatsapp_enabled === true && whatsapp_number !== null && whatsapp_verified_at !== null`).

---

## 3. Task Management (`tasks`, `D-09`, `D-11` — `Implemented & Verified`)

1. **Status & Prioritas:**
    - Status ([TaskStatus.php](../app/Enums/TaskStatus.php)): `todo`, `in_progress`, `completed`, `cancelled`.
    - Prioritas ([TaskPriority.php](../app/Enums/TaskPriority.php)): `low`, `medium`, `high`, `urgent`.
2. **Definisi Tugas Aktif:**
    - Tugas berstatus `todo` atau `in_progress` adalah tugas aktif (`$task->isActive() === true`).
    - Tugas berstatus `completed` atau `cancelled` bukan tugas aktif (`$task->isActive() === false`).
3. **Konsistensi `completed_at`:**
    - Diatur otomatis pada `Task::booted()`: ketika `status` menjadi `completed`, `completed_at` diisi `now()` (UTC); ketika berubah ke selain `completed`, `completed_at` di-reset ke `null`.
4. **Relasi Proyek & Subtugas Maksimal 1 Tingkat (`D-09`):**
    - `project_id` dan `parent_id` wajib milik `user_id` yang sama (divalidasi di [StoreTaskRequest.php](../app/Http/Requests/Tasks/StoreTaskRequest.php), [UpdateTaskRequest.php](../app/Http/Requests/Tasks/UpdateTaskRequest.php), dan `Task::booted()`).
    - Tugas tidak boleh menunjuk dirinya sendiri (`parent_id !== id`), tugas induk tidak boleh merupakan subtugas (`$parent->parent_id === null`), dan tugas yang sudah memiliki subtugas tidak boleh dijadikan subtugas dari tugas lain.
5. **Sinkronisasi Reminder Otomatis (`D-05`):**
    - [TaskController.php](../app/Http/Controllers/TaskController.php) memanggil `ReminderSynchronizer::syncForTask($task)` saat tugas dibuat/diperbarui dan `cancelPendingForTask($task)` saat dihapus.
    - Tugas aktif yang memiliki `due_at` menghasilkan pengingat `before_24h` (H-24 jam) dan `before_1h` (H-1 jam) selama waktu pengingat masih di masa depan dan sebelum `due_at`.

---

## 4. Project Management (`projects`, `D-11` — `Implemented & Verified`)

1. **Status Proyek ([ProjectStatus.php](../app/Enums/ProjectStatus.php)):** `active`, `completed`, `archived`.
2. **Rumus Progres Proyek (`Project::calculateProgress()`):**
    - `round((completed_tasks_count / non_cancelled_tasks_count) * 100)`, atau `0` jika belum ada tugas non-cancelled.
3. **Kemandirian Status Tugas & Proyek:**
    - Menyelesaikan proyek (`status = completed`) mengisi `projects.completed_at = now()`, namun tidak mengubah status tugas di dalamnya.
    - Menghapus proyek tidak menghapus tugas di dalamnya (`tasks.project_id` memiliki `nullOnDelete()`).

---

## 5. Calendar & Agenda Berulang (`calendar_events`, `D-08`, `D-10` — `Implemented & Verified`)

1. **Representasi Waktu Berdasarkan `is_all_day` ([CalendarEvent.php](../app/Models/CalendarEvent.php)):**
    - `is_all_day === false`: wajib memiliki `starts_at` dan `ends_at` (UTC, `ends_at > starts_at`), sedangkan `start_date` dan `end_date` wajib `null`.
    - `is_all_day === true`: wajib memiliki `start_date` dan `end_date` (`Y-m-d`, `end_date >= start_date`), sedangkan `starts_at` dan `ends_at` wajib `null`.
2. **Aturan Agenda Berulang (`D-08` — [RecurrenceExpanderService.php](../app/Services/Calendar/RecurrenceExpanderService.php) & [CalendarEventController.php](../app/Http/Controllers/CalendarEventController.php)):**
    - Mendukung `recurrence_rule` (`FREQ=DAILY`, `FREQ=WEEKLY`, `FREQ=MONTHLY`) dan `recurrence_until`.
    - **Edit/Hapus seluruh rangkaian (`edit_scope = 'series'`):** memperbarui atau menghapus agenda induk beserta overrides-nya.
    - **Edit satu kejadian (`edit_scope = 'single'`):** membuat/memperbarui baris override dengan `parent_event_id = $series->id` dan `recurrence_id = 'YYYY-MM-DD'`.
    - **Hapus satu kejadian (`edit_scope = 'single'`):** menambahkan tanggal kejadian ke array JSONB `recurrence_exceptions` pada agenda induk dan menandai override terkait dengan `is_cancelled = true`.
3. **Deteksi Konflik Jadwal ([ScheduleConflictDetector.php](../app/Services/Calendar/ScheduleConflictDetector.php)):**
    - Mengecek irisan waktu (`starts_at < other.ends_at && ends_at > other.starts_at`) antar agenda berjam non-cancelled milik pengguna; konflik ditandai dengan `has_conflict = true` dan ditampilkan di banner peringatan tanpa memblokir penyimpanan.

---

## 6. WhatsApp Reminder (`reminders`, `D-02`, `D-05`, `D-06`, `D-07`, `D-14` — `Implemented & Verified`)

1. **Kanal Notifikasi Satu Arah:**
    - Pengiriman melalui `WhatsAppGatewayInterface` ([FonnteWhatsAppGateway.php](../app/Services/WhatsApp/FonnteWhatsAppGateway.php), [HttpWhatsAppGateway.php](../app/Services/WhatsApp/HttpWhatsAppGateway.php), [NullWhatsAppGateway.php](../app/Services/WhatsApp/NullWhatsAppGateway.php)).
2. **Klaim Atomik & Pengiriman Idempotent:**
    - [DispatchDueRemindersCommand.php](../app/Console/Commands/DispatchDueRemindersCommand.php) (`reminders:dispatch-due`) mengklaim baris `pending` yang jatuh tempo (`scheduled_at <= now()`) menjadi `processing` dengan query `WHERE status = 'pending'` sebelum mendispatch [SendWhatsAppMessageJob.php](../app/Jobs/SendWhatsAppMessageJob.php).
    - `SendWhatsAppMessageJob` (`$tries = 3`, `$backoff = [15, 60, 180]`) memeriksa ulang `$user->canReceiveWhatsAppReminders()`, memastikan tugas/agenda terkait masih aktif, menyesuaikan ulang jika masuk jam tenang, lalu mengirim pesan dengan `idempotency_key`.

---

## 7. AI Planner (`ai_planner_runs`, `D-03`, `D-04`, `D-12`, `D-13`, `D-15` — `Implemented & Verified`)

1. **Urutan Fallback Provider & Circuit Breaker ([AiPlannerService.php](../app/Services/AiPlanner/AiPlannerService.php)):**
    - Urutan: `GeminiPlannerProvider` → `GrokPlannerProvider` → `OpenRouterPlannerProvider` → `FallbackPlannerProvider`.
    - Circuit breaker berbasis cache (`CIRCUIT_FAILURE_THRESHOLD = 3`, `CIRCUIT_WINDOW_SECONDS = 300`): jika sebuah provider eksternal gagal 3 kali dalam 5 menit, provider tersebut dilewati selama 5 menit berikutnya.
2. **Validasi Output & Persetujuan Pengguna (`D-12`):**
    - `validateAndNormalizeResult()` memfilter `priorities` (maksimal 3 item) dan `recommended_reminders` (maksimal 5 item) agar hanya memuat `task_id` yang benar-benar ada di dalam daftar tugas aktif milik pengguna.
    - Klik tombol **Jadwalkan WA** pada rekomendasi pengingat AI memanggil `POST /ai-planner/reminders/approve` (`AiPlannerService::approveReminder()`), yang membuat reminder bertipe `ai_recommended` secara idempotent setelah melewati `QuietHoursAdjuster`.

---

## 8. Hardening, Rate Limiting, Ekspor Data & Retensi (`D-11`, `D-15` — `Implemented & Verified`)

1. **Rate Limiting ([AppServiceProvider.php](../app/Providers/AppServiceProvider.php)):**
    - `ai-planner`: maksimal 10 request/menit per pengguna pada `POST /ai-planner/generate`.
    - `whatsapp-otp`: maksimal 5 request/menit per pengguna pada `POST /settings/whatsapp/send-otp`.
    - `data-export`: maksimal 5 request/menit per pengguna pada `GET /settings/profile/export`.
2. **Whitelist Ekspor Data JSON ([ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php)):**
    - Endpoint `GET /settings/profile/export` hanya mengekspor kolom yang di-whitelist dari `user`, `projects`, `tasks`, `calendar_events`, `reminders`, dan `ai_planner_runs` milik pengguna yang sedang login. Field sensitif (`password`, `remember_token`, `two_factor_*`, `input_snapshot`, `error_message`, `failure_reason`) tidak disertakan.
3. **Retensi Terjadwal ([PruneRetentionDataCommand.php](../app/Console/Commands/PruneRetentionDataCommand.php)):**
    - Menghapus permanen data _soft-deleted_ (`tasks`, `calendar_events`, `projects`) dan log terminal (`reminders` berstatus `sent`/`failed`/`cancelled` serta `ai_planner_runs`) yang berusia > 30 hari.
