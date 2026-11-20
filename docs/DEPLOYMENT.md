# Deployment Guide — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan kebutuhan infrastruktur, variabel environment produksi, langkah deployment, konfigurasi Queue Worker & Scheduler, serta pipeline CI/CD untuk **Managjeh MVP 1**.

---

## 1. Komponen Runtime Produksi

| Komponen                                                | Peran                                                                                                                              | Kebutuhan Produksi                                                           |
| :------------------------------------------------------ | :--------------------------------------------------------------------------------------------------------------------------------- | :--------------------------------------------------------------------------- |
| **1. Web Server (PHP `^8.3` + Nginx/FrankenPHP/Caddy)** | Melayani aplikasi Laravel 13 + Inertia v3 SPA                                                                                      | Wajib **HTTPS** (syarat mutlak untuk WebAuthn Passkeys dan cookie aman)      |
| **2. PostgreSQL (`>= 16`)**                             | Database utama (`DB_CONNECTION=pgsql`) untuk tabel domain, sesi, cache, dan antrean                                                | Timezone aplikasi menggunakan **UTC** (`config('app.timezone') === 'UTC'`)   |
| **3. Laravel Queue Worker**                             | Menjalankan `SendWhatsAppMessageJob` secara asynchronous                                                                           | `php artisan queue:work database --tries=3 --backoff=15,60,180 --timeout=30` |
| **4. Laravel Scheduler**                                | Menjalankan `reminders:dispatch-due` (tiap menit), `ai-planner:generate-daily` (`05:30`), dan `app:prune-retention-data` (`02:30`) | Cron `* * * * * php artisan schedule:run >> /dev/null 2>&1`                  |

---

## 2. Variabel Environment Produksi (`.env`)

Jangan pernah menyimpan kredensial asli di dalam repository. Gunakan template berikut pada server produksi:

```dotenv
APP_NAME="Managjeh"
APP_ENV=production
APP_KEY=base64:GENERATE_WITH_PHP_ARTISAN_KEY_GENERATE
APP_DEBUG=false
APP_URL=https://managjeh.example.com

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=aws-1-<region>.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.<project_ref>
DB_PASSWORD=your_strong_db_password
DB_SSLMODE=require
DB_PERSISTENT=true

# Direkomendasikan `cookie` di produksi Railway untuk mengurangi round-trip DB sesi per request
SESSION_DRIVER=cookie
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

# AI Planner Providers: Gemini -> Grok -> OpenRouter -> Fallback (D-03, D-04)
GEMINI_API_KEY=your_gemini_api_key
GEMINI_MODEL=gemini-flash-latest
GEMINI_ENDPOINT=https://generativelanguage.googleapis.com/v1beta

XAI_API_KEY=your_xai_api_key
GROK_MODEL=grok-3-mini
GROK_ENDPOINT=https://api.x.ai/v1/chat/completions

OPENROUTER_API_KEY=your_openrouter_api_key
OPENROUTER_MODEL=google/gemini-3.8-flash
OPENROUTER_ENDPOINT=https://openrouter.ai/api/v1/chat/completions

AI_PLANNER_TIMEOUT=20

# WhatsApp Gateway Adapter (D-02, D-14): fonnte | http | null
WHATSAPP_DRIVER=fonnte
FONNTE_TOKEN=your_fonnte_device_token
FONNTE_API_ENDPOINT=https://api.fonnte.com/send
WHATSAPP_TIMEOUT=10
```

---

## 3. Langkah Deployment & Provisioning Pengguna Pertama (`D-01`)

```bash
# 1. Install dependencies PHP & JS serta build aset produksi Vite
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# 2. Jalankan seluruh migrasi database (12 migrasi)
php artisan migrate --force

# 3. Buat akun pengguna melalui CLI Provisioning (registrasi publik dinonaktifkan sesuai D-01)
php artisan user:provision \
  --name="Nama Mahasiswa" \
  --email="mahasiswa@example.com" \
  --password="PasswordKuat#2026!" \
  --timezone="Asia/Jakarta"

# 4. Optimasi cache konfigurasi, route, dan view
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> **Catatan:** Di lingkungan produksi (`APP_ENV=production`), `DB::prohibitDestructiveCommands(true)` aktif di `AppServiceProvider`, sehingga perintah destruktif seperti `migrate:fresh`, `migrate:reset`, dan `db:wipe` diblokir demi keamanan data.

---

## 4. Konfigurasi Supervisor & Cron (Linux Production)

### 4.1 Crontab (` Laravel Scheduler`)

Tambahkan baris berikut pada crontab user server (`crontab -e`):

```cron
* * * * * cd /path/to/managjeh && php artisan schedule:run >> /dev/null 2>&1
```

Jadwal yang terdaftar di [routes/console.php](../routes/console.php):

- `reminders:dispatch-due` — Setiap menit.
- `ai-planner:generate-daily` — Setiap hari pukul `05:30` UTC.
- `app:prune-retention-data` — Setiap hari pukul `02:30` UTC.

### 4.2 Supervisor (`Laravel Queue Worker`)

Contoh konfigurasi `/etc/supervisor/conf.d/managjeh-worker.conf`:

```ini
[program:managjeh-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/managjeh/artisan queue:work database --sleep=3 --tries=3 --backoff=15,60,180 --timeout=30 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/managjeh/storage/logs/worker.log
stopwaitsecs=3600
```

Setiap kali melakukan deployment kode baru, restart queue worker dengan:

```bash
php artisan queue:restart
```

---

## 5. Deployment di Railway + Supabase

Repository ini menyediakan konfigurasi kontainer siap pakai untuk **Railway** + **Supabase PostgreSQL** (`Session Pooler` port `5432`):

- **[Dockerfile](../Dockerfile):** Image `dunglas/frankenphp:php8.5-trixie` lengkap dengan ekstensi PHP PostgreSQL (`pdo_pgsql`, `pgsql`), `intl`, `bcmath`, `pcntl`, konfigurasi OPcache produksi ([docker/php/production.ini](../docker/php/production.ini)), Composer `--no-dev`, dan build Vite + Wayfinder (Node.js 22).
- **[Caddyfile](../Caddyfile):** Konfigurasi FrankenPHP dengan `auto_https off` (di belakang proxy HTTPS Railway) pada `:{$PORT:8080}`, kompresi `zstd gzip`, serta header `Cache-Control: public, max-age=31536000, immutable` untuk `/build/assets/*` dan `max-age=86400` untuk ikon statis & `robots.txt`.
- **[docker/start.sh](../docker/start.sh):** Menjalankan `config:cache`, `route:cache`, `view:cache`, `php artisan migrate --force`, worker antrean (`queue:work database --sleep=15`) & scheduler latar belakang, lalu menjalankan server web FrankenPHP.
- **[bootstrap/app.php](../bootstrap/app.php):** Mengaktifkan `trustProxies(at: '*')` agar skema HTTPS di belakang load balancer Railway terdeteksi dengan benar.
- **Kesiapan Arsitektur MVP 2 (`CONFIRMED` / `Planned` — `D-16`):** Karena AI Orchestrator dan Internal MCP Server (`app/Mcp/`) berjalan **in-process di dalam monolith Laravel** dengan mode **synchronous JSON request-response** (bukan SSE long-lived dan tidak menunggu `queue:work --sleep=15`), deployment MVP 2 tetap berjalan pada satu container FrankenPHP di Railway tanpa microservice terpisah. MVP 2 juga menambahkan scheduler harian untuk rolling sync 48 jam agenda berulang & digest harian/mingguan deterministik, serta middleware HTTP Security Headers. Pastikan region PostgreSQL tetap sejajar dengan region Railway (mis. Singapore) guna menekan TTFB.

---

## 6. Continuous Integration (`.github/workflows/tests.yml`)

Repository ini dilengkapi workflow GitHub Actions di [.github/workflows/tests.yml](../.github/workflows/tests.yml) yang otomatis berjalan pada setiap `push` dan `pull_request` ke branch `main` / `develop`:

1. Menyiapkan container service **PostgreSQL 17** (`managjeh_test`).
2. Menyiapkan **PHP 8.5** dan **Node 22**.
3. Menjalankan `composer install`, `npm ci`, dan `npm run build`.
4. Menjalankan `composer ci:check` yang mencakup:
    - `npm run check` (`vp check`: format & lint seluruh file TS/TSX/CSS/Markdown/JSON).
    - `npm run types:check` (`tsc --noEmit`).
    - `pint --parallel --test` (format kode PHP).
    - `phpstan analyse` (static analysis level 7).
    - `php artisan test` (seluruh test suite Pest di PostgreSQL).
