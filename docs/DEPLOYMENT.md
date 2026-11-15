# Deployment Guide — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan kebutuhan infrastruktur, konfigurasi environment, serta langkah deployment untuk **Managjeh MVP 1**.

---

## 1. Komponen Runtime yang Dibutuhkan

| Komponen                                                | Peran                                                                                                                             | Kebutuhan Produksi                                                           |
| :------------------------------------------------------ | :-------------------------------------------------------------------------------------------------------------------------------- | :--------------------------------------------------------------------------- |
| **1. Web Server (PHP `^8.3` + Nginx/FrankenPHP/Caddy)** | Melayani aplikasi Laravel 13 + Inertia SSR/SPA                                                                                    | Wajib **HTTPS** (syarat mutlak untuk WebAuthn Passkeys dan keamanan sesi)    |
| **2. PostgreSQL (`>= 16`)**                             | Database utama (`DB_CONNECTION=pgsql`)                                                                                            | Timezone database & aplikasi menggunakan **UTC**                             |
| **3. Laravel Queue Worker**                             | Menjalankan `SendWhatsAppMessageJob` & job AI Planner secara asynchronous                                                         | `php artisan queue:work database --tries=3 --backoff=15,60,180 --timeout=30` |
| **4. Laravel Scheduler**                                | Memeriksa reminder jatuh tempo tiap menit, menjalankan AI Planner otomatis (`D-12`), & membersihkan retensi data (`D-11`, `D-15`) | Cron `* * * * * php artisan schedule:run`                                    |

---

## 2. Variabel Environment Produksi (`.env`)

Jangan pernah menyimpan kredensial asli di repository. Gunakan variabel berikut pada server produksi:

```dotenv
APP_NAME="Managjeh"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://managjeh.example.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=managjeh
DB_USERNAME=postgres
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

# WhatsApp Gateway Adapter (D-02, D-14)
WHATSAPP_DRIVER=http
WHATSAPP_BASE_URL=
WHATSAPP_API_TOKEN=
WHATSAPP_WEBHOOK_SECRET=

# AI Planner Providers: Gemini -> Grok -> OpenRouter (D-03, D-04)
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
GEMINI_TIMEOUT=15

GROK_API_KEY=
GROK_BASE_URL=https://api.x.ai/v1
GROK_MODEL=grok-3-mini
GROK_TIMEOUT=15

OPENROUTER_API_KEY=
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=google/gemini-2.0-flash-exp:free
OPENROUTER_TIMEOUT=15
```

---

## 3. Langkah Deployment & Provisioning Pengguna Pertama (`D-01`)

```bash
# 1. Install dependencies & build frontend
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# 2. Jalankan migrasi database
php artisan migrate --force

# 3. Buat akun pengguna melalui CLI Provisioning (karena registrasi publik dinonaktifkan sesuai D-01)
php artisan user:provision --name="Nama Mahasiswa" --email="mahasiswa@example.com" --password="PasswordKuat#2026!" --timezone="Asia/Jakarta"

# 4. Optimasi cache Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
