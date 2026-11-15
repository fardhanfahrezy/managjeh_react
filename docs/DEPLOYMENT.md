# Deployment Guide — Managjeh (Asisten AI Mahasiswa)

Dokumen ini mencakup **Persyaratan & Konfigurasi Deployment Aktual (`FACT`)** serta **Kebutuhan Infrastruktur Target Asisten AI Mahasiswa (`REQUIREMENT` / `PLANNED`)**, termasuk evaluasi kritis terhadap keterbatasan layanan hosting gratis (target biaya **Rp0**).

---

## 1. Komponen Infrastruktur yang Diperlukan (`FACT` & `REQUIREMENT`)

Agar seluruh fitur Asisten AI Mahasiswa (`F1`–`F11`) berfungsi sesuai target ketepatan waktu (pengingat terkirim ≤ 2 menit), lingkungan produksi membutuhkan **5 komponen aktif**:

| Komponen                     | Peran Utama                                                                                               | Status di Repo (`FACT`)                                           | Kebutuhan Produksi (`REQUIREMENT`)                                                     |
| ---------------------------- | --------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| **1. Laravel Web Server**    | Melayani UI React Inertia & menerima HTTP POST Webhook WhatsApp                                           | `Implemented` (`public/index.php`)                                | Harus memiliki URL publik **HTTPS** yang selalu aktif (tidak tidur saat webhook masuk) |
| **2. PostgreSQL Database**   | Menyimpan akun, tugas, jadwal, pengingat, kebiasaan, chat, `ai_usage`, dan tabel `jobs`                   | `Implemented` (`pgsql`)                                           | Semua timestamp disimpan dalam **UTC** (`config('app.timezone') === 'UTC'`)            |
| **3. Laravel Scheduler**     | Memeriksa tabel `reminders` (`status = 'pending'`) setiap 1 menit, rencana pagi, & ringkasan Minggu malam | `Not Implemented` (`routes/console.php` masih kosong dari jadwal) | Cron job sistem `* * * * * php artisan schedule:run` wajib berjalan setiap menit       |
| **4. Laravel Queue Worker**  | Memproses pengiriman pesan ke gateway WhatsApp & pemanggilan `AiService` secara asinkron                  | `Partially Implemented` (tabel `jobs` & `failed_jobs` sudah ada)  | Proses daemon `php artisan queue:work` (atau pemicu ekuivalen setiap menit)            |
| **5. Outbound HTTPS Access** | Menghubungi API Gemini, Groq, OpenRouter, WhatsApp Gateway, dan SMTP Email                                | `Implemented` (SMTP Gmail teruji di lokal)                        | Akses keluar port `443` (HTTPS) dan `587`/`465` (SMTP) tidak diblokir firewall         |

---

## 2. Evaluasi Opsi Hosting Gratis & Keterbatasannya (`REQUIREMENT` Rp0 vs Realita Teknis)

> **Peringatan Penting:** Jangan mengasumsikan bahwa platform hosting gratis selalu mendukung **Laravel Scheduler tiap menit** dan **Queue Worker yang berjalan terus-menerus (daemon)** secara cuma-cuma.

Berikut adalah evaluasi opsi infrastruktur dengan target biaya **Rp0**:

| Opsi Hosting Gratis                                                                                                | Kelebihan                                                                                                                          | Keterbatasan Kritis (Wajib Diantisipasi)                                                                                                                                                                                                                                       | Kelayakan untuk Managjeh                                                                                                    |
| ------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| **A. Free Container PaaS (Render Free, Koyeb Free, Fly.io Trial, dll.)**                                           | Mudah di-deploy dari Git, gratis HTTPS otomatis.                                                                                   | 1. **Scale-to-zero / Sleep:** Container mati setelah 5–15 menit tanpa trafik, sehingga cron Scheduler berhenti dan webhook WhatsApp pertama mengalami _cold start_ (bisa timeout).<br>2. Biasanya tidak mengizinkan 2 proses terpisah (web + background worker) secara gratis. | **Kurang Ideal** untuk pengingat ≤ 2 menit kecuali dikombinasikan dengan pemicu cron eksternal dan satu container gabungan. |
| **B. Serverless PHP / Shared Hosting Gratis**                                                                      | Biaya Rp0 untuk trafik web ringan.                                                                                                 | Tidak mengizinkan daemon `php artisan queue:work` berjalan terus-menerus di latar belakang; batas waktu eksekusi HTTP pendek.                                                                                                                                                  | **Terbatas** — hanya bisa jalan jika cron 1 menit menjalankan `schedule:run` diikuti `queue:work --stop-when-empty`.        |
| **C. VPS Gratis Selamanya (mis. Oracle Cloud Always Free ARM/AMD) atau Home Server / Mini PC + Cloudflare Tunnel** | Mendukung `systemd` penuh untuk `queue:work`, `cron` sistem tiap menit tanpa tidur, dan PostgreSQL lokal tanpa batas _cold start_. | Kuota pendaftaran Oracle Free Tier terkadang sulit didapat; jika memakai laptop/server rumah, perangkat harus menyala saat jam aktif.                                                                                                                                          | **Paling Direkomendasikan (`RECOMMENDATION`)** untuk menjamin SLA pengingat ≤ 2 menit dengan biaya Rp0.                     |
| **D. Managed PostgreSQL Gratis (Neon / Supabase Free Tier)**                                                       | PostgreSQL terkelola gratis tanpa mengurus server DB sendiri.                                                                      | Memiliki batas ukuran storage (mis. 500 MB) dan _compute auto-suspend_ (butuh 1–3 detik _wake-up_ saat koneksi pertama).                                                                                                                                                       | **Layak** untuk beban satu pengguna (_single-user_).                                                                        |

### Strategi Arsitektur Worker pada Hosting Terbatas (`RECOMMENDATION`)

Jika nantinya menggunakan hosting yang **tidak** mendukung daemon supervisor `queue:work` 24/7:

- Jalankan proses pengingat dan antrean langsung dari cron setiap menit:
    ```bash
    * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
    ```
- Di dalam `routes/console.php`, setelah command pemeriksaan pengingat mendispatch job, jalankan `queue:work --stop-when-empty --max-time=50` agar tidak ada proses menggantung melewati batas 1 menit.

---

## 3. Persyaratan Server & Runtime (`FACT`)

- **PHP:** `^8.3` (diuji pada `8.5.12`) dengan ekstensi `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `intl`, `bcmath`, `curl`, `xml`, `zip`.
- **Node.js & npm:** Digunakan pada tahap build aset frontend (`vp build`).
- **Database:** PostgreSQL (diuji pada `18.6`).
- **HTTPS Wajib (`REQUIREMENT`):**
    1. Gateway WhatsApp membutuhkan endpoint webhook publik berprotokol **HTTPS** (`https://domain-anda.com/...`).
    2. Browser mewajibkan **HTTPS** (selain `localhost`) agar fitur **Passkeys / WebAuthn** berfungsi (`APP_URL` wajib menggunakan `https://`).

---

## 4. Konfigurasi Environment Produksi (`.env`)

### 4.1 Variabel yang Sudah Digunakan Saat Ini (`FACT`)

```dotenv
APP_NAME="Managjeh"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://domain-anda.com

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

MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"
```

### 4.2 Variabel Tambahan untuk Fitur Target (`PLANNED` — Minggu 2 & 4)

```dotenv
# WhatsApp Gateway & Webhook
WHATSAPP_API_URL=
WHATSAPP_API_TOKEN=
WHATSAPP_WEBHOOK_SECRET=

# AI Service (Gemini -> Groq -> OpenRouter :free)
GEMINI_API_KEY=
GROQ_API_KEY=
OPENROUTER_API_KEY=
```

---

## 5. Langkah Build, Queue Worker & Scheduler

### 5.1 Build & Optimasi Aplikasi (`FACT`)

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 5.2 Menjalankan Laravel Scheduler (`REQUIREMENT` — Minggu 2)

Tambahkan entri cron sistem pada server:

```cron
* * * * * cd /path/to/managjeh && php artisan schedule:run >> /dev/null 2>&1
```

### 5.3 Menjalankan Queue Worker (`REQUIREMENT` — Minggu 2)

Gunakan Supervisor / `systemd` untuk menjaga worker tetap hidup:

```bash
php artisan queue:work database --tries=3 --backoff=15,60,180 --timeout=30
```

---

## 6. Backup Database & Monitoring Pengiriman Pesan (`REQUIREMENT` & `RECOMMENDATION`)

### 6.1 Backup PostgreSQL Berkala

Karena seluruh tugas kuliah, jadwal, dan riwayat percakapan pengguna tunggal tersimpan di PostgreSQL:

- Jadwalkan backup harian menggunakan `pg_dump`:
    ```bash
    pg_dump -Fc -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" > backup_managjeh_$(date +%F).dump
    ```
- Simpan salinan dump di penyimpanan terpisah dan uji proses _restore_ secara berkala.

### 6.2 Monitoring Queue, Kuota AI & Pengiriman Gagal

1. **Indikator di Beranda `"Hari ini"` (`REQUIREMENT`):** Pengingat di tabel `reminders` yang berstatus `failed` setelah seluruh percobaan ulang habis wajib ditampilkan secara mencolok di Beranda `"Hari ini"` agar pengguna mengetahui ada pesan WhatsApp yang gagal terkirim.
2. **Pemantauan `failed_jobs`:** Periksa antrean gagal dengan `php artisan queue:failed` dan kirim ulang dengan `php artisan queue:retry all` setelah gangguan gateway teratasi.
3. **Pemantauan Kuota AI (`ai_usage`):** Pantau tabel `ai_usage` untuk melihat distribusi pemakaian harian antara Gemini, Groq, dan OpenRouter.
4. **Health Check (`FACT`):** Endpoint `GET /up` sudah tersedia untuk memantau ketersediaan HTTP server.

---

## 7. TODO (`UNKNOWN`)

- [ ] Memutuskan platform hosting gratis final yang akan digunakan (`UNKNOWN` di PRD).
