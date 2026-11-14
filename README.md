# Managjeh — Asisten AI Mahasiswa

**Managjeh** adalah aplikasi web asisten pribadi berbasis **Laravel 13**, **Inertia.js v3**, **React 19**, dan **PostgreSQL** yang dirancang untuk membantu seorang mahasiswa mengatur tugas, jadwal kuliah, dan kebiasaan harian dengan **pengingat otomatis melalui WhatsApp** serta dukungan **API AI gratis** (Gemini sebagai penyedia utama, Groq dan OpenRouter `:free` sebagai cadangan) dengan target biaya operasional **Rp0**.

---

## Tujuan Produk

1. **Pengguna Tunggal (*Single-User*):** Dirancang khusus untuk satu pengguna (pemilik produk) agar fokus, cepat, dan bebas kompleksitas multi-tenant di versi pertama.
2. **Pengingat WhatsApp sebagai Fitur Inti:** Mengirim pengingat tepat waktu ke aplikasi yang paling sering dibuka (WhatsApp) dan mendukung balasan cepat satu kata (`"selesai"` atau `"tunda"`).
3. **AI Berbiaya Rp0 dengan Fallback:** Memanfaatkan free tier **Gemini** -> **Groq** -> **OpenRouter (`:free`)** -> **Templat tetap tanpa AI** (sehingga pengingat 100% tetap terkirim meskipun seluruh layanan AI sedang bermasalah).
4. **Zona Waktu Akurat:** Seluruh waktu disimpan dalam **UTC** di PostgreSQL dan ditampilkan/dievaluasi dalam **`Asia/Jakarta`**.

---

## Status Fitur Saat Ini (`FACT` vs `REQUIREMENT`)

| ID / Modul | Nama Fitur | Tahap | Status Aktual (`FACT`) |
| --- | --- | --- | --- |
| **Auth & Settings** | Login, Register, Verify Email, Reset Password, 2FA TOTP, Passkeys/WebAuthn, Profil & Tema | Minggu 1 | `Implemented` |
| **Database & Queue Infra** | Koneksi PostgreSQL (`managjeh` & `managjeh_test`), tabel `users`, `passkeys`, `sessions`, `cache`, `jobs` | Minggu 1–2 | `Implemented` |
| **`F1`** | Kelola tugas & subtugas (`judul`, `deskripsi`, `prioritas`, `tenggat`, `status`, `parent_id`) | MVP (Minggu 1) | `Not Implemented` (`Planned`) |
| **`F2`** | Jadwal mingguan berulang (`judul`, `hari`, `jam_mulai`, `jam_selesai`, `berulang`) | MVP (Minggu 1) | `Not Implemented` (`Planned`) |
| **`F3`** | Pengingat terjadwal ke WhatsApp (via Laravel Scheduler tiap menit + Queue) | MVP (Minggu 2–3) | `Not Implemented` (`Planned`) |
| **`F4`** | Webhook balasan WhatsApp `"selesai"` (tuntaskan tugas) & `"tunda"` (geser +30 menit) | MVP (Minggu 2) | `Not Implemented` (`Planned`) |
| **`F5`** | Jam tenang (*Quiet Hours* — geser pengingat ke `jam_tenang_selesai`) | MVP (Minggu 3) | `Not Implemented` (`Planned`) |
| **`F6`** | Beranda `"Hari ini"` (jadwal & tugas hari ini dalam satu layar) | MVP (Minggu 3) | `Partially Implemented` (`/dashboard` masih placeholder) |
| **`F7`** | Perencana harian AI (*Daily Planner* dikirim pagi hari) | Tahap 2 (Minggu 4) | `Not Implemented` (`Planned`) |
| **`F8`** | `AiService` dengan fallback (Gemini -> Groq -> OpenRouter `:free` -> Templat tetap) & `ai_usage` | Tahap 2 (Minggu 4) | `Not Implemented` (`Planned`) |
| **`F9`** | Obrolan asisten AI kontekstual (`messages` via Web & WhatsApp) | Tahap 2 (Minggu 5) | `Not Implemented` (`Planned`) |
| **`F10`** | Pemecah tugas besar menjadi subtugas bertanggal target (*AI Task Breakdown*) | Tahap 2 (Minggu 5) | `Not Implemented` (`Planned`) |
| **`F11`** | Pelacak kebiasaan (`habits`, `habit_logs`, streak) & ringkasan mingguan (Minggu malam) | Tahap 3 (Minggu 6) | `Not Implemented` (`Planned`) |

---

## Teknologi yang Digunakan

### Backend
- **PHP** `^8.3` (lingkungan pengembangan: PHP `8.5.12`, ekstensi `pdo_pgsql` & `pgsql`)
- **Laravel Framework** `^13.17` (`13.42.0` terpasang)
- **Inertia.js Laravel** `^3.0` (`3.7.0` terpasang)
- **Laravel Fortify** `^1.37.2` (`1.42.0` terpasang — mendukung TOTP 2FA & Passkeys/WebAuthn)
- **Laravel Wayfinder** `^0.1.14` (`0.1.23` terpasang)
- **PostgreSQL** (`18.6` pada lingkungan lokal)

### Frontend
- **React** `^19.2.0` & **React DOM** `^19.2.0`
- **@inertiajs/react** `^3.0.0` & **@inertiajs/vite** `^3.0.0`
- **TypeScript** `^5.7.2`
- **Tailwind CSS** `^4.0.0` (`@tailwindcss/vite` `^4.1.11`)
- **Radix UI** primitives, **Lucide React** icons, & **Sonner** toast
- **Vite+** (`vite-plus` `1.4.0`) & **Babel React Compiler**

### Testing, Linting & Quality Tools
- **Pest** `^5.3` (`5.3.5`) & **pest-plugin-laravel** (`5.0.1`)
- **Laravel Pint** `^1.27` (`1.32.3`)
- **Larastan / PHPStan** `^3.9` (`3.15.0`, level 7 pada `phpstan.neon`)
- **Vite+ Check (`vp check`)** & **TypeScript (`tsc --noEmit`)**

---

## Persyaratan Sistem

- PHP `>= 8.3` dengan ekstensi `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `intl`, `bcmath`, `curl`, `xml`, `zip`
- Composer v2
- Node.js (CI menggunakan v22) & npm
- PostgreSQL (mis. PostgreSQL 18)

---

## Installation Guide

1. Pastikan PostgreSQL berjalan dan buat dua database (aplikasi & testing):
   - `managjeh`
   - `managjeh_test`
2. Jalankan setup awal:
   ```bash
   composer setup
   ```
   Perintah `composer setup` di `composer.json` menjalankan `composer install`, menyalin `.env.example` ke `.env` jika belum ada, `php artisan key:generate`, `php artisan migrate --force`, `npm install`, dan `npm run build`.

## Environment Setup

1. Salin `.env.example` menjadi `.env` jika belum tersedia:
   ```bash
   cp .env.example .env
   ```
2. Konfigurasikan koneksi PostgreSQL di `.env` (jangan pernah melakukan commit password/API key ke Git):
   ```dotenv
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=managjeh
   DB_USERNAME=postgres
   DB_PASSWORD=
   ```
3. Pastikan `APP_KEY` terisi (`php artisan key:generate`) dan `APP_URL` sesuai alamat akses lokal (`http://localhost:8000` atau `http://127.0.0.1:8000`).
4. Pengujian otomatis menggunakan `phpunit.xml` dengan `DB_CONNECTION=pgsql` dan `DB_DATABASE=managjeh_test`.

## Backend & Frontend Setup Manual

```bash
# Backend
composer install
php artisan key:generate
php artisan migrate

# Frontend
npm install
npm run build
```

## Development Commands

| Tujuan | Perintah | Sumber |
| --- | --- | --- |
| Menjalankan dev server Laravel + Vite (`php artisan dev`) | `composer dev` | `composer.json` |
| Menjalankan Vite+ dev server saja (`vp dev`) | `npm run dev` | `package.json` |
| Menjalankan queue worker | `php artisan queue:listen` | Laravel Artisan |
| Menjalankan scheduler secara lokal (saat diimplementasikan) | `php artisan schedule:work` | Laravel Artisan |
| Menjalankan Laravel Pail (log viewer) | `php artisan pail` | `composer.json` |

## Testing & Quality Commands

| Tujuan | Perintah | Sumber |
| --- | --- | --- |
| Menjalankan seluruh test Pest | `php artisan test` | `composer.json` / `phpunit.xml` |
| Cek penuh backend (config clear, Pint, PHPStan, Pest) | `composer test` | `composer.json` |
| Cek CI lengkap (frontend check, tsc, backend test) | `composer ci:check` | `composer.json` |
| Format kode PHP dengan Pint | `composer lint` | `composer.json` |
| Static analysis PHPStan (level 7) | `composer types:check` | `composer.json` |
| Type-check TypeScript (`tsc --noEmit`) | `npm run types:check` | `package.json` |
| Lint & format check frontend (`vp check`) | `npm run check` | `package.json` |

## Build Commands

| Tujuan | Perintah | Sumber |
| --- | --- | --- |
| Build aset frontend produksi (`vp build`) | `npm run build` | `package.json` |
| Build aset + Inertia SSR bundle (`vp build && vp build --ssr`) | `npm run build:ssr` | `package.json` |

---

## Dokumentasi Utama Proyek

- [AGENTS.md](AGENTS.md) — Panduan & instruksi wajib seluruh AI Agent
- [CLAUDE.md](CLAUDE.md) — Instruksi kerja ringkas untuk Claude Code
- [docs/PRD.md](docs/PRD.md) — Product Requirements Document (`F1`–`F11`), Roadmap 6 Minggu & Technical Gap Analysis
- [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) — Arsitektur Aktual (`FACT`) vs Arsitektur Target (`REQUIREMENT`)
- [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — ERD & Skema PostgreSQL Aktual vs Target (`tasks`, `schedules`, `reminders`, `habits`, `messages`, `ai_usage`)
- [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — Aturan bisnis tugas, jadwal, pengingat, jam tenang, webhook WhatsApp, fallback AI, dan privasi
- [docs/USER_FLOW.md](docs/USER_FLOW.md) — 14 alur interaksi pengguna & sistem dengan diagram Mermaid
- [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md) — Route Inertia aktual serta spesifikasi integrasi WhatsApp Gateway, Webhook, Gemini, Groq, dan OpenRouter
- [docs/FRONTEND_ARCHITECTURE.md](docs/FRONTEND_ARCHITECTURE.md) — Arsitektur React 19 + Inertia v3 & rancangan halaman untuk `F1`–`F11`
- [docs/SECURITY.md](docs/SECURITY.md) — Keamanan autentikasi, webhook secret, validasi nomor WA, dan perlindungan privasi data dari AI
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Panduan deployment, kebutuhan Queue/Scheduler, dan evaluasi hosting gratis
- [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) — Standar pengembangan, konvensi kode, timezone UTC/`Asia/Jakarta`, idempotency, dan testing
