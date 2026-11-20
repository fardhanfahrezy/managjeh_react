# Security & Privacy Documentation — Managjeh (Personal OS Mahasiswa — MVP 1 & MVP 2)

Dokumen ini mendokumentasikan seluruh mekanisme keamanan, isolasi kepemilikan data, proteksi kredensial, rate limiting, HTTP security headers (CSP nonce + HSTS), ekspor data, dan retensi privasi yang **telah diimplementasikan dan terverifikasi (`Implemented & Verified`)** pada **Managjeh MVP 1 & MVP 2** (`D-01` s.d. `D-19`).

---

## 1. Autentikasi, Provisioning & Kontrol Akses (`D-01`)

1. **Penonaktifan Registrasi Publik (`D-01`):**
    - `Features::registration()` dihapus dari [config/fortify.php](../config/fortify.php).
    - Permintaan ke `GET /register` maupun `POST /register` mengembalikan `404 Not Found` (diverifikasi oleh [RegistrationTest.php](../tests/Feature/Auth/RegistrationTest.php)).
    - Pembuatan akun hanya dapat dilakukan melalui CLI `php artisan user:provision` ([ProvisionUserCommand.php](../app/Console/Commands/ProvisionUserCommand.php)).
2. **Autentikasi Berlapis (Laravel Fortify `1.42.0` & Passkeys):**
    - Session guard berbasis PostgreSQL (`http_only => true`, `same_site => 'lax'`).
    - Mendukung Two-Factor Authentication (TOTP + recovery codes terenkripsi) dan WebAuthn Passkeys (`@laravel/passkeys`).
    - Halaman pengaturan keamanan (`GET /settings/security`) dilindungi oleh middleware `RequirePassword`.
3. **Password Policy:**
    - Di lingkungan produksi (`app()->isProduction()`), `AppServiceProvider` menegakkan password minimal 12 karakter dengan kombinasi huruf besar/kecil, angka, simbol, dan pemeriksaan `uncompromised()`.

---

## 2. Isolasi Kepemilikan Data (`user_id` Authorization)

1. **Enforced Ownership Policies:**
    - [ProjectPolicy.php](../app/Policies/ProjectPolicy.php), [TaskPolicy.php](../app/Policies/TaskPolicy.php), dan [CalendarEventPolicy.php](../app/Policies/CalendarEventPolicy.php) memverifikasi `$user->id === $model->user_id` pada setiap operasi baca, ubah, dan hapus.
2. **Validasi Kepemilikan Lintas-Relasi:**
    - [StoreTaskRequest.php](../app/Http/Requests/Tasks/StoreTaskRequest.php), [UpdateTaskRequest.php](../app/Http/Requests/Tasks/UpdateTaskRequest.php), dan `Task::booted()` menolak keras pengaitan `project_id` atau `parent_id` yang bukan milik `user_id` yang sama.
    - [StoreCalendarEventRequest.php](../app/Http/Requests/Calendar/StoreCalendarEventRequest.php), [UpdateCalendarEventRequest.php](../app/Http/Requests/Calendar/UpdateCalendarEventRequest.php), dan `CalendarEvent::booted()` menolak pengaitan `project_id` atau `task_id` lintas pengguna.
3. **Proteksi Mass Assignment:**
    - Seluruh model (`User`, `Project`, `Task`, `CalendarEvent`, `Reminder`, `AiPlannerRun`) mendefinisikan daftar kolom `#[Fillable([...])]` atau `$fillable` secara eksplisit. `user_id` selalu ditetapkan dari `$request->user()->id`.

---

## 3. Keamanan OTP & Persetujuan WhatsApp (`D-14`, `D-15`)

1. **Penyimpanan OTP Tanpa Plain-Text (`D-15`):**
    - [WhatsAppVerificationController.php](../app/Http/Controllers/Settings/WhatsAppVerificationController.php) menghasilkan kode OTP 6 digit (`random_int(100000, 999999)`), namun **hanya menyimpan hash SHA-256 (`hash('sha256', $otp)`)** di cache (`whatsapp_otp:{user_id}`) dengan TTL **5 menit** dan batas maksimal **5 percobaan verifikasi**.
    - Verifikasi kode menggunakan perbandingan waktu konstan `hash_equals()`.
2. **Reset Verifikasi Saat Nomor Berubah (`D-14`):**
    - Mengubah `whatsapp_number` di [ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php) otomatis mereset `whatsapp_verified_at = null`.
    - Mengosongkan `whatsapp_number` otomatis menonaktifkan `whatsapp_enabled = false`.
    - [SendWhatsAppMessageJob.php](../app/Jobs/SendWhatsAppMessageJob.php) selalu memverifikasi `$user->canReceiveWhatsAppReminders()` tepat sebelum mengirim pesan ke gateway.

---

## 4. Sanitasi Prompt AI, Rate Limiting & Circuit Breaker (`D-13`, `D-15`)

1. **Sanitasi Konteks Prompt AI (`D-15`):**
    - [AiPlannerContext.php](../app/Services/AiPlanner/AiPlannerContext.php) (`toSanitizedSnapshot()`) hanya menyertakan data operasional minimum: `planning_date`, `timezone`, daftar tugas aktif (`id`, `title`, `priority`, `status`, `due_at_local`, `is_overdue`, `project_name`), daftar agenda hari ini (`id`, `title`, `starts_at_local`, `ends_at_local`, `is_all_day`, `has_conflict`), dan `free_slots`.
    - Data sensitif seperti `email`, `whatsapp_number`, `password`, `two_factor_secret`, `remember_token`, kode OTP, maupun token API **tidak pernah** dikirim ke provider LLM maupun disimpan di `ai_planner_runs.input_snapshot`.
2. **Circuit Breaker Provider AI (`D-13`):**
    - [AiPlannerService.php](../app/Services/AiPlanner/AiPlannerService.php) mencatat kegagalan per provider di cache (`ai_planner_circuit:{provider}`). Jika sebuah provider gagal **3 kali dalam 300 detik**, _circuit breaker_ terbuka dan provider tersebut dilewati selama 300 detik untuk mencegah banjir request keluar.
3. **Rate Limiting Endpoint Sensitif:**
    - Dikonfigurasi di [AppServiceProvider.php](../app/Providers/AppServiceProvider.php) dan [routes/settings.php](../routes/settings.php):
        - `POST /ai-planner/generate` → `throttle:ai-planner` (**10 request / menit** per user).
        - `POST /ai-chat/messages` → `throttle:ai-chat` (**20 request / menit** per user).
        - `POST /settings/whatsapp/send-otp` → `throttle:whatsapp-otp` (**5 request / menit** per user).
        - `POST /settings/whatsapp/verify-otp` → `throttle:10,1` (**10 request / menit**).
        - `GET /settings/profile/export` → `throttle:data-export` (**5 request / menit** per user).
        - `PUT /settings/password` → `throttle:6,1` (**6 request / menit**).
        - `POST /sync/push`, `GET /sync/pull`, `GET /sync/status` → `throttle:sync` (**60 request / menit** per user).
4. **HTTP Security Headers (`AddSecurityHeaders`):**
    - Terdaftar pada grup middleware `web` di [bootstrap/app.php](../bootstrap/app.php) melalui [AddSecurityHeaders.php](../app/Http/Middleware/AddSecurityHeaders.php) dan nonce script di [app.blade.php](../resources/views/app.blade.php):
        - `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Cross-Origin-Opener-Policy: same-origin`.
        - `Strict-Transport-Security: max-age=31536000; includeSubDomains` pada request HTTPS atau lingkungan `production`.
        - `Content-Security-Policy` memakai `Vite::useCspNonce()` (`script-src 'self' 'nonce-{nonce}'`, `worker-src 'self'`, `manifest-src 'self'`, `frame-ancestors 'none'`, `object-src 'none'`) pada lingkungan non-local, dengan kelonggaran origin Vite dev server khusus `app()->isLocal()`.
5. **Keamanan Offline-First PWA (`D-20`–`D-25` — `Implemented & Verified`):**
    - **Zero Private Cache di Service Worker (`public/sw.js`):** Endpoint autentikasi (`/login`, `/logout`, `/two-factor*`, `/passkeys*`), pengaturan keamanan/WA, `/sync/*`, request non-`GET`, dan respons `X-Inertia: true` masuk daftar _Network-Only_. Fallback navigasi hanya menyimpan `/offline-shell` yang secara eksplisit mengirim `auth.user = null`.
    - **Offline Lease 7 Hari Tanpa Kredensial (`D-21`):** `managjeh_offline_registry` hanya menyimpan metadata profil non-sensitif & masa berlaku lease 7 hari; tidak pernah menyimpan password, OTP, recovery code, passkey secret, atau API token.
    - **Isolasi Multi-Akun & Logout Guard (`D-22`):** Data lokal dipisahkan per akun (`managjeh_offline_u{userId}`). Logout dengan Outbox kosong langsung menghapus DB lokal; pergantian akun otomatis menghapus DB akun sebelumnya.
    - **Sync Trust Boundary (`SyncService`):** Identitas selalu diambil dari `$request->user()`, Policy divalidasi per item, `project_uuid`/`parent_uuid`/`task_uuid` lintas user ditolak keras, dan `user_id` dari payload client diabaikan sepenuhnya.

---

## 5. Ekspor Data Ter-Whitelist & Retensi Data (`D-11`, `D-15`)

1. **Whitelist Ekspor Data JSON ([ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php)):**
    - Endpoint `GET /settings/profile/export` hanya mengekspor kolom yang di-whitelist secara eksplisit dari data milik pengguna yang sedang login (`user`, `projects`, `tasks`, `calendar_events`, `reminders`, `ai_planner_runs`).
    - Kolom rahasia (`password`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, serta detail internal `input_snapshot`, `error_message`, `failure_reason`, `provider_message_id`) dikecualikan dari hasil ekspor.
2. **Pembersihan Retensi Terjadwal ([PruneRetentionDataCommand.php](../app/Console/Commands/PruneRetentionDataCommand.php)):**
    - Command `php artisan app:prune-retention-data` (berjalan otomatis setiap hari pukul `02:30` UTC) menghapus permanen (`forceDelete()`) data _soft-deleted_ (`tasks`, `calendar_events`, `projects`) serta memangkas log `reminders` terminal (`sent`, `failed`, `cancelled`) dan `ai_planner_runs` yang berusia > 30 hari.

---

## 6. Aturan Penanganan Kredensial & Environment

- Jangan pernah melakukan commit file `.env` ke Git (sudah dilindungi di `.gitignore`).
- Jangan pernah menulis API key (`GEMINI_API_KEY`, `XAI_API_KEY`, `GROK_API_KEY`, `OPENROUTER_API_KEY`, `FONNTE_TOKEN`, `WHATSAPP_API_TOKEN`) secara hardcoded di source code, test, maupun dokumentasi.
- Selalu gunakan placeholder aman pada dokumentasi dan gunakan `Http::fake()` beserta `WHATSAPP_DRIVER=null` pada pengujian otomatis.

---

## 7. Persyaratan Keamanan & Privasi Terencana (`CONFIRMED` / `Planned` — `D-16` s.d. `D-19`)

> **Status Implementasi:** `Planned` (desain keamanan MVP 2 telah terkunci; belum diimplementasikan di kode saat ini).

1. **Trust Boundary Internal MCP Server & AI Orchestrator (`D-16`):**
    - Autentikasi sesi website & backend Laravel menjadi satu-satunya dasar otorisasi untuk seluruh permintaan AI Chat dan pemanggilan tool MCP in-process di `app/Mcp/` (tanpa endpoint HTTP `/mcp` eksternal di MVP 2).
    - **Anti-Spoofing `user_id`:** MCP Server dilarang mempercayai atau menerima parameter `user_id` dari input prompt maupun argumen tool yang dihasilkan model AI.
    - AI dilarang mengakses database PostgreSQL secara langsung; seluruh akses baca/tulis wajib melalui service & Policy backend yang sama dengan fitur web.
    - **Batasan Eksekusi & Rate Limit:** Rate limiter `ai-chat` (**20 request/menit** per user), batas panjang pesan `2.000` karakter, batas instruksi preferensi AI `300` karakter, serta batas maksimal **4 putaran tool-call** per pesan.
2. **Persetujuan Mutasi, TTL 24 Jam & Jejak Audit (`D-12`, `D-17`):**
    - Write tools MVP 2 dibatasi pada operasi **non-destruktif** (`create_task`, `update_task`, `create_schedule`, `update_schedule`; tanpa `delete_*`).
    - Seluruh pemanggilan write tool dicegat menjadi baris `ai_action_proposals` (kedaluwarsa otomatis setelah **24 jam**) dan tidak pernah dieksekusi di dalam loop LLM.
    - Eksekusi setelah klik persetujuan pengguna wajib memvalidasi ulang kepemilikan, `TaskPolicy`/`CalendarEventPolicy`, dan aturan validasi secara idempoten serta mencatat hasil audit yang ikut whitelist ekspor `GET /settings/profile/export`.
3. **Privasi Riwayat Percakapan AI (`D-19`):**
    - Riwayat percakapan (`ai_chat_conversations`, `ai_chat_messages`) dan `ai_action_proposals` diisolasi ketat per `user_id`, dipangkas otomatis setelah **30 hari** (`app:prune-retention-data`), dan menyediakan aksi hapus manual oleh pengguna.
    - Kredensial, token API, email, nomor WhatsApp, password, maupun data rahasia tetap dilarang dikirim ke provider AI eksternal (`D-15`).
4. **Privasi Notifikasi & Data Finansial Masa Depan (MVP 3):**
    - Pengiriman notifikasi WhatsApp wajib berdasarkan persetujuan pengguna (`opt-in`) dan pengguna harus bisa mengaktifkan/menonaktifkan notifikasi kapan saja.
    - Pesan WhatsApp tidak boleh memuat informasi keuangan sensitif secara berlebihan.
    - Sistem **dilarang keras** menyimpan password bank, PIN, OTP, atau kredensial e-wallet dalam database, cache, maupun log.
5. **Paket Hardening HTTP Security Headers (`CONFIRMED` / `Planned` — Masuk Cakupan MVP 2):**
    - Menindaklanjuti temuan audit Lighthouse produksi (`2026-11-16`), MVP 2 menyertakan middleware **HTTP Security Headers** untuk memasang `Strict-Transport-Security` (HSTS), `X-Frame-Options` (XFO), `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Cross-Origin-Opener-Policy` (COOP), dan baseline `Content-Security-Policy` (CSP) yang kompatibel dengan Vite & Inertia.
