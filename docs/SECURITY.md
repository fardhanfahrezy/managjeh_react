# Security Documentation — Managjeh (Asisten AI Mahasiswa)

Dokumen ini mendokumentasikan:
1. **Mekanisme Keamanan Aktual yang Sudah Diterapkan (`FACT` — Implemented)** di repository.
2. **Kebutuhan Keamanan Target Asisten AI Mahasiswa (`REQUIREMENT` / `PLANNED` — Not Implemented)** berdasarkan Blueprint & PRD.
3. **Evaluasi Risiko & Rekomendasi Penguatan Keamanan (`RECOMMENDATION`)**.

---

## 1. Keamanan yang Sudah Diterapkan di Repository (`FACT` — Implemented)

### 1.1 Backend Security (`FACT`)
- **Authentication & Session Guard (`config/auth.php`, `config/session.php`):**
  - Menggunakan session guard `web` dengan penyimpanan sesi di PostgreSQL (`SESSION_DRIVER=database`), `http_only => true`, dan `same_site => 'lax'`.
- **Multi-Factor & Passwordless Authentication (`config/fortify.php`):**
  - **TOTP 2FA:** `Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])`.
  - **Passkeys / WebAuthn:** `Features::passkeys(['confirmPassword' => true])` terikat ke `APP_URL` (`relying_party_id` & `allowed_origins`).
- **Password Security (`User.php`, `AppServiceProvider.php`):**
  - Hashing `bcrypt` (`hashed` cast, `BCRYPT_ROUNDS=12`).
  - Pada `APP_ENV=production`, `Password::defaults()` mewajibkan minimal 12 karakter, huruf besar/kecil, angka, simbol, dan lolos pemeriksaan kebocoran `uncompromised()`.
  - Perubahan password dan penghapusan akun mewajibkan verifikasi `current_password`.
- **CSRF, Cookie Encryption & Rate Limiting (`bootstrap/app.php`, `FortifyServiceProvider.php`):**
  - Proteksi CSRF aktif pada seluruh route `web`.
  - Cookie dienkripsi kecuali `appearance` dan `sidebar_state`.
  - Rate limiter aktif untuk `login` (5/menit), `two-factor` (5/menit), `passkeys` (10/menit), serta `throttle:6,1` pada update password dan verifikasi email.
- **Database & Cache Hardening:**
  - `DB::prohibitDestructiveCommands(app()->isProduction())` memblokir perintah destruktif di produksi.
  - `config/cache.php` mengatur `'serializable_classes' => false`.
  - File `.env` terdaftar di `.gitignore`.

### 1.2 Frontend Security (`FACT`)
- Auto-escaping JSX React mencegah XSS pada rendering teks dinamis.
- Tidak ada token rahasia atau kredensial yang disimpan di `localStorage` (hanya preferensi tema `appearance`).

---

## 2. Kebutuhan Keamanan Target Asisten AI Mahasiswa (`REQUIREMENT` / `PLANNED` — Not Implemented)

Bagian ini mendokumentasikan kontrol keamanan yang diwajibkan oleh Blueprint & PRD untuk fitur yang akan dibangun pada Minggu 1–6:

### 2.1 Single-User Access Control (`REQUIREMENT`)
- **Prinsip Pengguna Tunggal:** Aplikasi dirancang untuk satu pengguna tunggal (pemilik produk).
- **Pembatasan Akun & Query:**
  - Seluruh query tugas (`tasks`), jadwal (`schedules`), pengingat (`reminders`), kebiasaan (`habits`), dan pesan (`messages`) wajib di-scope ke `user_id` pengguna yang sedang login.
  - **Rekomendasi Implementasi (`RECOMMENDATION`):** Nonaktifkan registrasi publik (`Features::registration()`) setelah akun pemilik dibuat atau batasi pendaftaran melalui konfigurasi environment (`ALLOW_REGISTRATION=false`) agar pihak luar tidak dapat mendaftarkan akun di server produksi.

### 2.2 Keamanan Webhook WhatsApp (`REQUIREMENT` — `F4`, `F9`)
Endpoint webhook WhatsApp dapat diakses dari internet publik sehingga wajib menerapkan pertahanan berlapis:
1. **WhatsApp Webhook Secret (`REQUIREMENT`):**
   - Setiap request masuk ke endpoint webhook wajib diverifikasi menggunakan token rahasia (`WHATSAPP_WEBHOOK_SECRET`) yang dikirim melalui header atau signature gateway.
   - Verifikasi wajib menggunakan `hash_equals()` untuk mencegah *timing attack*. Request tanpa token yang sah langsung ditolak (`401 Unauthorized` / `403 Forbidden`).
2. **Validasi Nomor Pengirim (`REQUIREMENT`):**
   - Nomor telepon pengirim pada payload webhook wajib dinormalisasi dan dicocokkan dengan `users.nomor_wa` milik pengguna tunggal.
   - **Pesan dari nomor selain milik pengguna wajib ditolak (`403 Forbidden`)** sebelum memicu perubahan status tugas, penundaan pengingat, ataupun pemanggilan API AI.
3. **Proteksi Pemrosesan Webhook Duplikat / Replay (`RECOMMENDATION`):**
   - Simpan identifier pesan unik dari gateway (`external_message_id`) dan tolak pemrosesan ulang apabila ID pesan tersebut sudah pernah diproses sebelumnya.

### 2.3 Manajemen API Key & Kredensial Eksternal (`REQUIREMENT`)
- Seluruh kunci rahasia (`GEMINI_API_KEY`, `GROQ_API_KEY`, `OPENROUTER_API_KEY`, `WHATSAPP_API_TOKEN`, `WHATSAPP_WEBHOOK_SECRET`, `MAIL_PASSWORD`) **wajib disimpan di `.env`** dan diakses hanya melalui `config/services.php` (bukan `env()` langsung di luar file konfigurasi agar kompatibel dengan `php artisan config:cache`).
- Kunci API **dilarang keras** dikirim ke frontend React (Inertia shared props), ditulis ke dalam kode sumber, atau ditampilkan di dokumentasi.

### 2.4 Rate Limiting Tambahan (`RECOMMENDATION`)
Selain rate limiter autentikasi yang sudah ada (`FACT`), tambahkan rate limiter pada:
- **Endpoint Webhook WhatsApp:** Membatasi jumlah request per menit (mis. `throttle:30,1`) untuk mencegah banjir request jika terjadi misconfiguration pada gateway atau serangan brute-force token.
- **Endpoint AI di Web (`/daily-plan/generate`, `/tasks/{task}/breakdown`, `/chat`):** Membatasi request per menit dari UI disertai pemeriksaan kuota harian di tabel `ai_usage` (`F8`).

### 2.5 Data Minimization & Perlindungan Data Sensitif dari AI (`REQUIREMENT`)
- **Risiko Privasi Free Tier Gemini (`REQUIREMENT`):** Berdasarkan kebijakan free tier Google AI Studio, data prompt yang dikirim dapat digunakan Google untuk meningkatkan produknya.
- **Aturan Sanitasi Konteks AI:**
  - **Hanya kirim data yang mutlak diperlukan:** judul tugas, deskripsi tugas akademik/kerja, prioritas, tenggat, dan jadwal kegiatan.
  - **Dilarang mengirim ke AI:** catatan harian pribadi, catatan mood, nomor WhatsApp (`nomor_wa`), alamat email, password, maupun token sesi.
- **Hak Pengguna atas Data (`REQUIREMENT`):**
  - Pengguna memiliki kendali penuh untuk mengekspor seluruh datanya dan menghapus akun beserta datanya dari database.

### 2.6 Kewajiban HTTPS & Keamanan Cookie Produksi (`REQUIREMENT` & `RECOMMENDATION`)
- Seluruh trafik web dan endpoint webhook di produksi **wajib berjalan di atas HTTPS** (`APP_URL=https://...`).
- HTTPS juga merupakan syarat wajib browser untuk fitur **WebAuthn / Passkeys** di luar `localhost`.
- Pada environment produksi, set `SESSION_SECURE_COOKIE=true` dan pertimbangkan `SESSION_ENCRYPT=true`.

### 2.7 Secure Logging (`RECOMMENDATION`)
- Saat mencatat kegagalan pemanggilan `AiService` atau `WhatsAppService` ke `storage/logs/laravel.log`, pastikan header `Authorization`, query parameter `key=...` (pada URL Gemini API), isi catatan pribadi, dan kredensial SMTP/API telah disensor (*redacted*).

---

## 3. Ringkasan Audit Celah Keamanan Saat Ini (`FACT` vs `REQUIREMENT`)

| Area Keamanan | Kondisi Aktual (`FACT`) | Target (`REQUIREMENT`) | Tindakan yang Diperlukan |
| --- | --- | --- | --- |
| **Registrasi Publik** | `Features::registration()` aktif untuk umum | Sistem *single-user* | Batasi/nonaktifkan registrasi setelah akun pemilik tersedia |
| **Proteksi Webhook WA** | Belum ada route webhook | Token rahasia + validasi `nomor_wa` | Buat middleware verifikasi `WHATSAPP_WEBHOOK_SECRET` & cek `nomor_wa` |
| **Sanitasi Data AI** | Belum ada `AiService` | Whitelist hanya tugas & jadwal | Buat context builder yang membuang field sensitif sebelum memanggil AI |
| **Secure Cookie Produksi** | `SESSION_SECURE_COOKIE` belum diset eksplisit di `.env.example` | Wajib HTTPS di produksi | Set `SESSION_SECURE_COOKIE=true` saat deployment |

---

## 4. TODO

- [ ] Menambahkan middleware verifikasi `WHATSAPP_WEBHOOK_SECRET` dan validasi `nomor_wa` pada Minggu 2.
- [ ] Menambahkan opsi penonaktifan registrasi publik setelah akun pengguna tunggal dibuat.
- [ ] Memastikan tidak ada URL error log Gemini yang mencetak parameter `?key=` secara mentah di log aplikasi.
