# Development Guidelines — Managjeh (Asisten AI Mahasiswa)

Dokumen ini menetapkan standar pengembangan, struktur direktori, konvensi kode, serta panduan teknis arsitektur untuk **Managjeh (Asisten AI Mahasiswa)** berdasarkan implementasi repository aktual (`FACT`) dan kebutuhan Blueprint/PRD (`REQUIREMENT`).

---

## 1. Pemetaan Struktur Repository (`FACT` & `PLANNED`)

*(Folder hasil generate seperti `node_modules/`, `vendor/`, `public/build/`, `bootstrap/ssr/`, dan `resources/js/{actions,routes,wayfinder}/` diabaikan dari pemetaan utama.)*

### 1.1 Direktori Aktual Saat Ini (`FACT`)
- **Backend (`app/`, `database/`, `routes/`, `config/`):**
  - `app/Actions/Fortify/` — `CreateNewUser.php`, `ResetUserPassword.php`
  - `app/Concerns/` — `PasswordValidationRules.php`, `ProfileValidationRules.php`
  - `app/Http/Controllers/Settings/` — `ProfileController.php`, `SecurityController.php`
  - `app/Http/Middleware/` — `HandleAppearance.php`, `HandleInertiaRequests.php`
  - `app/Http/Requests/Settings/` — `ProfileUpdateRequest`, `ProfileDeleteRequest`, `PasswordUpdateRequest`, `TwoFactorAuthenticationRequest`
  - `app/Models/` — `User.php`
  - `app/Providers/` — `AppServiceProvider.php`, `FortifyServiceProvider.php`
  - `database/migrations/` — 5 migrasi dasar (`users`, `cache`, `jobs`, `passkeys`, `two_factor`)
  - `routes/` — `web.php`, `settings.php`, `console.php`
- **Frontend (`resources/`):**
  - `resources/js/pages/` — `welcome.tsx`, `dashboard.tsx`, `auth/*`, `settings/*`
  - `resources/js/layouts/`, `resources/js/components/`, `resources/js/hooks/`, `resources/js/types/`, `resources/js/lib/`

### 1.2 Penempatan Kode Baru yang Direncanakan (`PLANNED` — Minggu 1–6)
Ikuti struktur konvensi Laravel 13 yang sudah ada saat menambahkan fitur `F1`–`F11`:
- **Models (`app/Models/`):** `Task.php`, `Schedule.php`, `Reminder.php`, `Habit.php`, `HabitLog.php`, `Message.php`, `AiUsage.php`.
- **Services (`app/Services/`):**
  - `AiService.php` (orkestrasi Gemini -> Groq -> OpenRouter `:free` -> Templat tetap, serta pencatatan `ai_usage`).
  - `WhatsAppService.php` (pengiriman pesan keluar ke gateway WhatsApp).
  - `ReminderService.php` (evaluasi jam tenang `F5`, penjadwalan pengingat dari tenggat `F3`, dan snooze 30 menit `F4`).
- **Jobs (`app/Jobs/`):** `SendReminderWhatsAppJob.php` (antrean pengiriman pengingat asinkron).
- **Controllers (`app/Http/Controllers/`):** `DashboardController.php`, `TaskController.php`, `ScheduleController.php`, `HabitController.php`, `ChatController.php`, `WhatsAppWebhookController.php`.
- **Form Requests (`app/Http/Requests/`):** Validasi terpisah untuk setiap mutasi `Task`, `Schedule`, `Habit`, dan `Webhook`.
- **React Pages (`resources/js/pages/`):** `dashboard.tsx` (Beranda `"Hari ini"`), `tasks/index.tsx`, `schedules/index.tsx`, `chat/index.tsx`, `habits/index.tsx`.

---

## 2. Standar Pengembangan Fitur Inti Asisten AI Mahasiswa (`REQUIREMENT` & `RECOMMENDATION`)

### 2.1 Service-Based AI Integration & Provider Fallback Pattern (`F7`–`F10`)
1. **Satu Pintu Masuk (`AiService`):**
   - Controller atau Queue Job **dilarang** memanggil endpoint HTTP Gemini/Groq/OpenRouter secara langsung. Seluruh interaksi AI harus melalui `App\Services\AiService`.
2. **Urutan Fallback Berjenjang (`REQUIREMENT`):**
   - Prioritas 1: **Gemini** (`GEMINI_API_KEY`).
   - Prioritas 2: **Groq** (`GROQ_API_KEY`) — dipicu otomatis saat Gemini mengembalikan HTTP `429`, error `5xx`, exception koneksi, atau timeout.
   - Prioritas 3: **OpenRouter** (`OPENROUTER_API_KEY`, wajib model berlabel `:free`) — dipicu saat Groq juga gagal.
   - Prioritas 4: **Templat Tetap Tanpa AI** — dipicu saat ketiga provider gagal atau kuota harian di tabel `ai_usage` telah mencapai batas aman.
3. **Kontrak Output JSON & Privasi:**
   - Untuk Perencana Harian (`F7`) dan Pemecah Tugas (`F10`), minta respons JSON terstruktur dan validasi hasilnya dengan `json_decode` + pengecekan key wajib sebelum disimpan ke database.
   - **Data Minimization Wajib:** Hanya teruskan data `tasks` dan `schedules` ke prompt AI. Jangan pernah menyertakan catatan harian, mood, atau `nomor_wa`.

### 2.2 Queue-Based Notification Delivery & Scheduler Conventions (`F3`, `F5`)
1. **Scheduler Ringan (`routes/console.php`):**
   - Command yang dijadwalkan setiap menit (`everyMinute()`) hanya bertugas memeriksa pengingat jatuh tempo (`status = 'pending'` dan `kirim_pada <= now()`), mengevaluasi jam tenang (`F5`), mengubah status ke `processing`, lalu mendispatch Job ke Queue.
   - Jangan melakukan panggilan HTTP lambat ke WhatsApp atau AI secara sinkron di dalam loop utama Scheduler.
2. **Queue Job dengan Retry & Backoff:**
   - Setiap Job pengiriman WhatsApp harus mendefinisikan `$tries` (mis. `3`), `$backoff` (mis. `[15, 60, 180]`), dan `$timeout` (mis. `30`).
   - Implementasikan method `failed(Throwable $exception)` pada Job untuk mengubah `reminders.status` menjadi `'failed'` agar kegagalan terlihat di Beranda `"Hari ini"`.

### 2.3 Webhook Security (`F4`, `F9`)
1. **Verifikasi Token & Nomor Pengirim:**
   - Gunakan middleware khusus atau pemeriksaan awal di `WhatsAppWebhookController` yang memvalidasi `WHATSAPP_WEBHOOK_SECRET` menggunakan `hash_equals()`.
   - Cocokkan nomor pengirim dengan `users.nomor_wa` pengguna tunggal. Tolak seluruh nomor lain dengan HTTP `403` sebelum memproses logika bisnis.
2. **Normalisasi Perintah Satu Kata:**
   - Gunakan `Str::lower(trim($incomingText))` saat mencocokkan perintah `"selesai"` dan `"tunda"`.

### 2.4 Database Transactions & Idempotency
1. **Transaksi Database (`DB::transaction`):**
   - Gunakan `DB::transaction()` saat:
     - Menyimpan subtugas hasil pemecahan AI (`F10`) ke tabel `tasks`.
     - Memproses balasan webhook `"selesai"` (mengubah `tasks.status` sekaligus membatalkan/memperbarui `reminders` terkait).
     - Mengambil pengingat `pending` di Scheduler dan mengubahnya menjadi `processing` (gunakan `lockForUpdate()` bila perlu).
2. **Idempotency:**
   - Pastikan pengingat yang sedang diproses (`processing`) atau sudah terkirim (`sent`) tidak dikirim ulang oleh Scheduler menit berikutnya.
   - Simpan `external_message_id` pada pesan webhook masuk untuk mencegah eksekusi ganda apabila gateway mengirim ulang webhook yang sama.

### 2.5 Timezone Handling (`UTC` <-> `Asia/Jakarta`) (`REQUIREMENT`)
1. **Penyimpanan Backend:**
   - `config/app.php` menggunakan `'timezone' => 'UTC'` (`FACT`) dan `CarbonImmutable` sudah aktif secara global (`FACT`).
   - Seluruh kolom timestamp di PostgreSQL disimpan dalam **UTC**.
2. **Konversi Input & Evaluasi Lokal:**
   - Saat menerima input tenggat atau jam dari pengguna, parse menggunakan zona waktu pengguna (`$user->zona_waktu ?? 'Asia/Jakarta'`) lalu panggil `->utc()` sebelum disimpan ke database.
   - Saat mengevaluasi batas "Hari ini" (`F6`), jam tenang (`F5`), atau tanggal kebiasaan (`F11`), hitung rentang waktu berdasarkan `CarbonImmutable::now($userTimezone)` lalu konversikan batas `startOfDay()` / `endOfDay()` ke UTC untuk query database.
   - **Wajib diuji pada jam kritis:** sekitar pukul `00:00 WIB` (`17:00 UTC` hari sebelumnya).

---

## 3. Coding Conventions & Tooling (`FACT`)

### 3.1 PHP / Laravel Standards
- Gunakan explicit type hints parameter & return type, serta kurung kurawal `{}` pada seluruh control structures.
- Gunakan `php artisan make:* --no-interaction` untuk membuat file baru.
- Jalankan `vendor/bin/pint --dirty --format agent` (atau `composer lint`) setelah memodifikasi file PHP.
- Pertahankan kepatuhan PHPStan/Larastan level 7 (`composer types:check`).

### 3.2 React / TypeScript / Inertia v3 Standards
- TypeScript `strict: true` (`npm run types:check`).
- Gunakan komponen `<Form>`, `<Link>`, `router`, dan `useHttp()` dari `@inertiajs/react` (Axios tidak digunakan).
- Selalu gunakan route/action helper dari **Laravel Wayfinder** (`@/routes/*`, `@/actions/*`).
- Nama file frontend menggunakan `kebab-case` dan mengikuti konfigurasi formatter di `vite.config.ts` (`npm run check`).

---

## 4. Testing Requirements (`REQUIREMENT` & `FACT`)

Setiap penambahan atau perubahan fitur `F1`–`F11` wajib disertai pengujian otomatis menggunakan **Pest v5** terhadap database `managjeh_test`:
1. **Isolasi Layanan Eksternal (`Http::fake()`):**
   - Jangan pernah memanggil API Gemini, Groq, OpenRouter, atau WhatsApp Gateway sungguhan di dalam automated test.
   - Gunakan `Http::fake()` untuk menguji:
     - Sukses di Gemini (provider utama).
     - Gemini HTTP `429` / timeout -> otomatis fallback ke Groq (`F8`).
     - Gemini & Groq gagal -> fallback ke OpenRouter (`:free`).
     - Ketiga provider gagal -> fallback ke templat tetap tanpa AI (`100%` pengingat tetap terkirim).
2. **Pengujian Waktu & Jam Tenang (`CarbonImmutable::setTestNow()`):**
   - Uji pengiriman pengingat di luar jam tenang vs di dalam jam tenang (`F5`), serta pergeseran waktu sekitar tengah malam (`23:30 WIB` – `00:30 WIB`).
3. **Pengujian Webhook (`F4`, `F9`):**
   - Uji request dengan token salah (`401`/`403`), nomor pengirim asing (`403`), balasan `"selesai"`, balasan `"tunda"` (+30 menit), dan balasan teks bebas.

---

## 5. Documentation Maintenance (`REQUIREMENT`)

Setiap perubahan pada skema database, aturan bisnis, alur pengingat, integrasi provider AI/WhatsApp, atau struktur halaman frontend wajib diikuti pembaruan dokumen terkait di `docs/`, `README.md`, `AGENTS.md`, dan `CLAUDE.md` agar dokumentasi tetap menjadi **Single Source of Truth**.

---

## 6. TODO

- [ ] Menetapkan konvensi commit/branching (mis. Conventional Commits) dan aturan branch protection untuk `main`.
