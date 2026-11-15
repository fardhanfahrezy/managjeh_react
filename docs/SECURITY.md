# Security & Privacy Documentation — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan mekanisme keamanan dan privasi yang telah diterapkan (`FACT` — Phase 0 & Phase 1) serta kewajiban keamanan untuk Phase 2 s.d. Phase 7 (`REQUIREMENT` — terikat keputusan `D-01` s.d. `D-15`).

---

## 1. Keamanan yang Sudah Diterapkan (`FACT` — Phase 0 & Phase 1)

1. **Penonaktifan Registrasi Publik (`D-01`):**
    - `Features::registration()` telah dihapus dari [config/fortify.php](../config/fortify.php).
    - Pembuatan akun hanya dapat dilakukan melalui CLI `php artisan user:provision` ([ProvisionUserCommand.php](../app/Console/Commands/ProvisionUserCommand.php)).
2. **Autentikasi Berlapis (Fortify `1.42.0`):**
    - Session guard berbasis PostgreSQL (`http_only => true`, `same_site => 'lax'`), TOTP 2FA, dan WebAuthn Passkeys.
    - Rate limiting aktif pada login (`5/menit`), 2FA (`5/menit`), passkeys (`10/menit`), dan update password (`6/menit`).
3. **Isolasi Kepemilikan Data (`user_id` Authorization):**
    - [ProjectPolicy.php](../app/Policies/ProjectPolicy.php) dan [TaskPolicy.php](../app/Policies/TaskPolicy.php) memverifikasi `$user->id === $resource->user_id`.
    - [StoreTaskRequest.php](../app/Http/Requests/Tasks/StoreTaskRequest.php), [UpdateTaskRequest.php](../app/Http/Requests/Tasks/UpdateTaskRequest.php), dan `Task::booted()` menolak keras pengaitan `project_id` atau `parent_id` lintas pengguna.
4. **Proteksi Mass Assignment:**
    - Seluruh model (`User`, `Project`, `Task`) menggunakan atribut `#[Fillable([...])]` eksplisit. `user_id` pada request tidak pernah diambil dari input form pengguna.
5. **Verifikasi Nomor WhatsApp (`D-14`):**
    - Perubahan `whatsapp_number` pada [ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php) otomatis mereset `whatsapp_verified_at = null`.
    - Method `$user->canReceiveWhatsAppReminders()` menjamin pesan tidak dikirim sebelum nomor diverifikasi dan `whatsapp_enabled === true`.

---

## 2. Kewajiban Keamanan & Privasi untuk Phase 2 s.d. Phase 7 (`REQUIREMENT`)

1. **Sanitasi Konteks Prompt AI (`D-15`):**
    - Hanya kirimkan data minimal yang diperlukan ke provider AI (`Gemini`, `Grok`, `OpenRouter`): `task.id`, `task.title`, `task.priority`, `task.due_at`, `project.name`, dan rentang waktu agenda kalender.
    - **DILARANG KERAS** mengirimkan `password`, `two_factor_secret`, `remember_token`, `whatsapp_number`, `email`, kode OTP, atau API key ke dalam prompt AI maupun `ai_planner_runs.input_snapshot`.
2. **Keamanan OTP & Log (`D-14`, `D-15`):**
    - Kode OTP verifikasi WhatsApp **tidak boleh** disimpan dalam bentuk plain-text di database atau log; simpan hanya hash (`Hash::make($otp)`) di cache dengan TTL pendek (mis. 5 menit) dan batas percobaan verifikasi.
    - Saat mencatat error dari Gemini/Grok/OpenRouter atau WhatsApp Gateway ke `ai_planner_runs.error_message`, `reminders.failure_reason`, atau `laravel.log`, pastikan header `Authorization` dan query `?key=...` telah disensor.
3. **Keamanan Webhook Delivery WhatsApp (Phase 5):**
    - Jika vendor gateway mengirimkan webhook status pengiriman, verifikasi signature/token rahasia menggunakan `hash_equals()`.
    - Jangan pernah mengeksekusi perintah perubahan data tugas/agenda dari pesan masuk WhatsApp pada MVP 1.
4. **Rate Limiting & Circuit Breaker AI (`D-13`):**
    - Terapkan rate limit pada endpoint manual `POST /ai-planner/generate` dan `POST /settings/whatsapp/send-otp`.
    - Terapkan *circuit breaker* pada `AiPlannerService` agar kegagalan beruntun pada satu provider tidak memicu banjir request keluar.
5. **Retensi Data & Ekspor/Penghapusan (`D-11`, `D-15`):**
    - Sediakan command terjadwal di Phase 7 untuk menghapus permanen data soft-deleted dan log `reminders` / `ai_planner_runs` yang melewati batas masa retensi.
