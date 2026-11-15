# Product Requirements Document (PRD) — Managjeh (Asisten AI Mahasiswa)

Dokumen ini mengintegrasikan **Blueprint Asisten AI Mahasiswa** dan **PRD Asisten AI Mahasiswa** (`REQUIREMENT`) dengan kondisi implementasi aktual repository `C:\Sandbox\managjeh` (`FACT`).

---

## 1. Product Vision & Overview

- **Nama Proyek:** Managjeh — Asisten AI Mahasiswa (`REQUIREMENT`)
- **Product Vision (`REQUIREMENT`):** Membangun asisten pribadi berbasis web (Laravel + React Inertia + PostgreSQL) untuk **satu pengguna** (mahasiswa Teknik Informatika) yang membantu menjalani hari dengan jadwal dan tugas yang terstruktur, mengirimkan pengingat tepat waktu melalui **WhatsApp**, serta memanfaatkan **API AI gratis** (Gemini sebagai penyedia utama, Groq dan OpenRouter `:free` sebagai fallback) dengan biaya operasional **Rp0**.
- **Prinsip Utama Produk (`REQUIREMENT`):**
  1. **Single-user terlebih dahulu:** Dirancang khusus untuk satu pengguna (pemilik produk); sistem multi-akun publik atau multi-tenant tidak diperlukan pada versi pertama.
  2. **WhatsApp-first untuk pengingat:** Pengingat WhatsApp adalah fitur inti karena WhatsApp merupakan aplikasi yang paling sering dibuka pengguna; AI berperan sebagai pelengkap.
  3. **Biaya Rp0:** Seluruh layanan AI dan infrastruktur pendukung mengandalkan kuota free tier.
  4. **Iteratif & dapat dipakai cepat:** MVP wajib dapat digunakan sehari-hari pada akhir **Minggu ke-3**, dan seluruh fitur Tahap 1–3 selesai dalam **6 minggu**.
- **Kondisi Implementasi Aktual (`FACT`):**
  - Pondasi aplikasi monorepo **Laravel `13.42.0` + Inertia.js `3.7.0` + React `19.2.0` + TypeScript + Tailwind CSS v4 + PostgreSQL `18.6`** (`managjeh` & `managjeh_test`) sudah berjalan (`Implemented`).
  - Modul autentikasi lengkap via Laravel Fortify `1.42.0` (Login, Register, Verify Email, Forgot/Reset Password, TOTP 2FA, Passkeys/WebAuthn) serta halaman `/settings/profile`, `/settings/security`, dan `/settings/appearance` sudah tersedia (`Implemented`).
  - Halaman `/dashboard` (`resources/js/pages/dashboard.tsx`) dan `/` (`resources/js/pages/welcome.tsx`) masih berupa tampilan placeholder starter kit (`Partially Implemented`).
  - Seluruh modul domain Asisten AI Mahasiswa (`tasks`, `schedules`, `reminders`, `habits`, `habit_logs`, `messages`, `ai_usage`, integrasi WhatsApp, Scheduler pengingat, Queue jobs, dan `AiService`) berstatus **belum diimplementasikan (`Not Implemented` / `Planned`)**.

---

## 2. Problem Statement (`REQUIREMENT`)

Pengguna adalah seorang mahasiswa Teknik Informatika yang mengalami kesulitan mengatur aktivitas harian karena tugas kuliah, jadwal kegiatan, dan kebiasaan tersebar di berbagai tempat serta mudah terlupa:
1. **Tugas dan tenggat (deadline) tidak tercatat di satu tempat terpusat.**
2. **Tidak ada sistem pengingat proaktif pada waktu yang tepat** di kanal komunikasi yang paling sering dibuka (WhatsApp).
3. **Saat kewalahan (overwhelmed), sulit menentukan prioritas** tugas mana yang harus dikerjakan terlebih dahulu atau memecah tugas besar menjadi langkah kecil.

---

## 3. Product Objectives & Success Metrics (`REQUIREMENT`)

Target awal ini merupakan usulan baseline pada PRD yang dapat dievaluasi ulang setelah 2 minggu pemakaian nyata:

| Tujuan | Metrik Keberhasilan | Target Awal (`REQUIREMENT`) | Status Pengukuran Saat Ini (`FACT`) |
| --- | --- | --- | --- |
| **Pengingat tepat waktu** | Persentase pengingat terkirim dalam ≤ 2 menit dari jadwal (`kirim_pada`) | **95%** | `Not Implemented` (belum ada tabel `reminders` & scheduler) |
| **Dipakai rutin** | Jumlah hari aktif per minggu (membalas pesan WA atau membuka beranda "Hari ini") | **5 dari 7 hari** pada minggu ke-4 | `Not Implemented` |
| **Tugas selesai tepat waktu** | Persentase tugas berstatus `done`/`selesai` sebelum `tenggat` | **70%** pada minggu ke-6 | `Not Implemented` |
| **Pengingat tidak hilang** | Persentase pengingat tetap terkirim saat seluruh provider AI gagal | **100%** (melalui templat statis tanpa AI) | `Not Implemented` |
| **Biaya operasional gratis** | Total biaya bulanan API AI, database, dan gateway | **Rp0 / bulan** | `Implemented` di lingkungan lokal |

---

## 4. Target Users & User Scenarios

### 4.1 Target Users (`REQUIREMENT` vs `FACT`)
- **Target User (`REQUIREMENT`):** Pengguna tunggal (*single user*), yaitu seorang mahasiswa Teknik Informatika yang sekaligus pemilik produk, menggunakan laptop dan ponsel, serta aktif di WhatsApp.
- **Kondisi Aktual (`FACT`):** Model `App\Models\User` sudah tersedia dengan autentikasi session, email verification, 2FA, dan Passkeys. Namun `Features::registration()` di `config/fortify.php` saat ini masih terbuka untuk publik dan tabel `users` belum memiliki kolom preferensi (`nomor_wa`, `zona_waktu`, `jam_tenang_mulai`, `jam_tenang_selesai`).

### 4.2 Skenario Penggunaan Utama (`REQUIREMENT`)
1. **Skenario Pagi Hari (Tahap 2 — F7):** Pagi hari pengguna menerima pesan WhatsApp berisi rencana harian yang disusun AI berdasarkan jadwal kuliah dan daftar tugas hari itu.
2. **Skenario Menjelang Tenggat (MVP — F3, F4, F5):** Menjelang deadline tugas, pengguna menerima pengingat di WhatsApp (di luar jam tenang) lalu cukup membalas satu kata: `"selesai"` (menandai tugas tuntas) atau `"tunda"` (menggeser pengingat 30 menit).
3. **Skenario Saat Kewalahan (Tahap 2 — F9, F10):** Saat bingung atau kewalahan, pengguna mengirim pesan teks bebas ke asisten (melalui web atau WhatsApp) untuk mendapatkan urutan prioritas atau memecah tugas besar menjadi subtugas bertanggal target.
4. **Skenario Evaluasi Mingguan (Tahap 3 — F11):** Setiap Minggu malam, pengguna menerima ringkasan capaian tugas dan streak kebiasaan selama sepekan melalui WhatsApp.

---

## 5. Product Scope & Feature Prioritization (`REQUIREMENT`)

### 5.1 Pembagian Ruang Lingkup
- **Masuk Versi Pertama / MVP (Minggu 1–3):**
  - Kelola tugas, subtugas, prioritas, dan deadline (`F1`)
  - Jadwal mingguan berulang (`F2`)
  - Pengingat terjadwal ke WhatsApp (`F3`)
  - Balasan webhook WhatsApp `"selesai"` dan `"tunda"` (`F4`)
  - Jam tenang / *quiet hours* (`F5`)
  - Beranda `"Hari ini"` (`F6`)
- **Menyusul pada Tahap 2 (Minggu 4–5):**
  - Perencana harian AI & pengingat pagi (`F7`)
  - `AiService` dengan mekanisme fallback 3 penyedia + templat tetap (`F8`)
  - Obrolan asisten AI kontekstual (`F9`)
  - Pemecah tugas (*AI task breakdown*) menjadi subtugas (`F10`)
- **Menyusul pada Tahap 3 (Minggu 6):**
  - Pelacak kebiasaan (*habit tracker*) dan ringkasan mingguan (`F11`)
  - Catatan harian dan mood (prioritas rendah pada Blueprint; **dilarang dikirim ke AI**)
- **Di Luar Lingkup (*Out of Scope*):**
  - Sistem multi-pengguna / multi-tenant
  - Aplikasi mobile native (Android/iOS)
  - Sistem pembayaran / monetisasi
  - Pencatatan keuangan sederhana (ditunda ke fase *"Nanti"*)
  - Timer fokus / Pomodoro (ditunda ke fase *"Nanti"*)
  - Impor otomatis jadwal kampus (input jadwal dilakukan manual)

### 5.2 Matriks Prioritas Fitur (`REQUIREMENT` vs `FACT`)

| Fitur | ID Req | Tahap | Prioritas | Status Aktual (`FACT`) |
| --- | --- | --- | --- | --- |
| Fondasi Auth, Profil, 2FA, Passkeys | Pondasi | Minggu 1 | Tinggi | `Implemented` |
| Tugas, subtugas, dan deadline | `F1` | MVP (Minggu 1) | Tinggi | `Not Implemented` (`Planned`) |
| Jadwal mingguan berulang | `F2` | MVP (Minggu 1) | Tinggi | `Not Implemented` (`Planned`) |
| Pengingat terjadwal ke WhatsApp | `F3` | MVP (Minggu 2–3) | Tinggi | `Not Implemented` (`Planned`) |
| Balasan WhatsApp `"selesai"` dan `"tunda"` | `F4` | MVP (Minggu 2) | Tinggi | `Not Implemented` (`Planned`) |
| Jam tenang (*Quiet Hours*) | `F5` | MVP (Minggu 3) | Tinggi | `Not Implemented` (`Planned`) |
| Beranda `"Hari ini"` | `F6` | MVP (Minggu 3) | Tinggi | `Partially Implemented` (`/dashboard` masih placeholder) |
| Perencana harian AI | `F7` | Tahap 2 (Minggu 4) | Tinggi | `Not Implemented` (`Planned`) |
| Layanan AI dengan Fallback (`AiService`) | `F8` | Tahap 2 (Minggu 4) | Tinggi | `Not Implemented` (`Planned`) |
| Obrolan asisten AI | `F9` | Tahap 2 (Minggu 5) | Sedang | `Not Implemented` (`Planned`) |
| Pemecah tugas (*Task Breakdown*) | `F10` | Tahap 2 (Minggu 5) | Sedang | `Not Implemented` (`Planned`) |
| Pelacak kebiasaan & ringkasan mingguan | `F11` | Tahap 3 (Minggu 6) | Sedang | `Not Implemented` (`Planned`) |
| Catatan harian dan mood | — | Tahap 3 (Opsional) | Rendah | `Not Implemented` (`Planned`) |
| Keuangan sederhana | — | Nanti (*Out of Scope*) | Rendah | `Out of Scope` |
| Timer fokus | — | Nanti (*Out of Scope*) | Rendah | `Out of Scope` |

---

## 6. Functional Requirements & Acceptance Criteria

### 6.1 Kebutuhan Fungsional Utama Produk (`F1`–`F11`) (`REQUIREMENT`)

Seluruh pengembangan fitur domain wajib mengacu pada ID `F1` s.d. `F11` berikut:

| ID | Kebutuhan Fungsional | Tahap | Kriteria Penerimaan (*Acceptance Criteria*) (`REQUIREMENT`) | Status Aktual (`FACT`) |
| --- | --- | --- | --- | --- |
| **F1** | **Kelola tugas:** judul, deskripsi, prioritas, tenggat, status, serta subtugas (`parent_id`) | MVP | 1. Pengguna dapat membuat, melihat, mengubah, dan menghapus tugas/subtugas.<br>2. Tugas tampil di halaman daftar tugas dan beranda `"Hari ini"` (jika tenggat hari ini/terlewat).<br>3. Status tugas dapat diubah menjadi selesai baik dari UI web maupun balasan WhatsApp. | `Not Implemented` |
| **F2** | **Jadwal mingguan berulang:** judul, hari, jam mulai, jam selesai, flag berulang | MVP | 1. Pengguna dapat mengelola jadwal kegiatan/kuliah mingguan.<br>2. Kegiatan tampil pada hari dan rentang jam yang sesuai (zona waktu `Asia/Jakarta`).<br>3. Jadwal hari berjalan tampil otomatis di beranda `"Hari ini"`. | `Not Implemented` |
| **F3** | **Pengingat terjadwal ke WhatsApp** | MVP | 1. Pengingat dibuat otomatis dari tenggat tugas/jadwal atau diatur manual.<br>2. Laravel Scheduler memeriksa pengingat `pending` setiap menit dan mendorongnya ke Queue.<br>3. Pesan terkirim ke `nomor_wa` pengguna pada waktu yang dipilih (≤ 2 menit dari jadwal), status berubah ke `sent`/`terkirim`, dan `dikirim_pada` tercatat dalam UTC. | `Not Implemented` |
| **F4** | **Balasan WhatsApp `"selesai"` dan `"tunda"`** | MVP | 1. Webhook menerima payload pesan masuk dari gateway WhatsApp, memvalidasi token rahasia dan `nomor_wa` pengguna.<br>2. Balasan `"selesai"` mengubah status tugas terkait menjadi selesai.<br>3. Balasan `"tunda"` menggeser `kirim_pada` pengingat sebesar +30 menit.<br>4. Sistem mengirim pesan balasan konfirmasi singkat ke WhatsApp. | `Not Implemented` |
| **F5** | **Jam tenang (*Quiet Hours*)** | MVP | 1. Pengguna memiliki konfigurasi `jam_tenang_mulai` dan `jam_tenang_selesai` pada profil.<br>2. Tidak ada pengiriman pesan WhatsApp selama jam tenang.<br>3. Pengingat yang jatuh pada jam tenang otomatis digeser ke waktu pertama yang diizinkan (`jam_tenang_selesai`). | `Not Implemented` |
| **F6** | **Beranda `"Hari ini"`** | MVP | 1. Halaman `/dashboard` menampilkan jadwal kegiatan hari ini, daftar tugas prioritas/jatuh tempo hari ini, serta status pengingat (termasuk pengingat yang gagal terkirim) dalam satu layar.<br>2. Aksi cepat menandai tugas selesai langsung memperbarui tampilan tanpa reload penuh. | `Partially Implemented` (Route `/dashboard` ada, UI masih `PlaceholderPattern`) |
| **F7** | **Perencana harian AI (*AI Daily Planner*)** | Tahap 2 | 1. Menyusun rencana kerja harian terstruktur (format JSON) dari tugas belum selesai dan jadwal hari tersebut.<br>2. Rencana dapat dilihat di web dan dikirim otomatis ke WhatsApp setiap pagi (setelah jam tenang berakhir). | `Not Implemented` |
| **F8** | **Fallback AI (`AiService`) & kuota `ai_usage`** | Tahap 2 | 1. `AiService` memanggil **Gemini** sebagai provider utama.<br>2. Jika Gemini mengembalikan HTTP `429`, error server, atau timeout, otomatis beralih ke **Groq**.<br>3. Jika Groq gagal, otomatis beralih ke **OpenRouter** (model `:free`).<br>4. Jika ketiga provider gagal atau kuota harian di `ai_usage` habis, sistem wajib menggunakan **templat teks tetap tanpa AI** sehingga pengingat 100% tetap terkirim. | `Not Implemented` |
| **F9** | **Obrolan asisten (*AI Assistant Chat*)** | Tahap 2 | 1. Pesan teks bebas dari WhatsApp (selain kata kunci perintah `"selesai"`/`"tunda"`) maupun dari halaman obrolan web diteruskan ke `AiService` dengan konteks tugas dan jadwal aktif pengguna.<br>2. Riwayat pesan masuk dan keluar tercatat di tabel `messages`. | `Not Implemented` |
| **F10** | **Pemecah tugas (*AI Task Breakdown*)** | Tahap 2 | 1. Pengguna dapat memilih tugas besar dan meminta AI memecahnya.<br>2. `AiService` meminta keluaran JSON berisi daftar subtugas beserta estimasi/target tanggal, lalu menyimpannya ke tabel `tasks` dengan `parent_id` mengarah ke tugas induk. | `Not Implemented` |
| **F11** | **Pelacak kebiasaan (*Habit Tracker*) & ringkasan mingguan** | Tahap 3 | 1. Pengguna dapat mendefinisikan kebiasaan (`habits`) dan mencatat penyelesaian harian (`habit_logs`).<br>2. Perhitungan *streak* harian tercatat akurat.<br>3. Setiap Minggu malam, Scheduler memicu pengiriman ringkasan mingguan (tugas selesai & streak kebiasaan) ke WhatsApp. | `Not Implemented` |

### 6.2 Fitur Pondasi yang Sudah Tersedia di Repository (`FACT`)

Fitur-fitur berikut sudah terimplementasi dari starter kit dan menjadi fondasi autentikasi serta pengaturan akun:
- **Pondasi Auth (`Implemented`):** Registrasi (`CreateNewUser`), Login email/password (`5/menit`), Login Passkey/WebAuthn (`10/menit`), Logout, Verifikasi Email (`MustVerifyEmail`), Forgot & Reset Password, Konfirmasi Password/Passkey, serta TOTP 2FA beserta recovery codes.
- **Pondasi Pengaturan (`Implemented`):** Edit profil (`name`, `email`), hapus akun, kelola password/2FA/passkeys (`SecurityController`), serta pengaturan tema `light`/`dark`/`system` (`HandleAppearance`).

---

## 7. Non-Functional Requirements (`REQUIREMENT` vs `FACT`)

| Kategori | Kebutuhan Non-Fungsional (`REQUIREMENT`) | Kondisi Aktual Repository (`FACT`) |
| --- | --- | --- |
| **Ketepatan Waktu (*Timeliness*)** | Pengingat terkirim maksimal **2 menit** dari jadwal `kirim_pada` melalui Laravel Scheduler per menit + Queue Worker. | `QUEUE_CONNECTION=database` dan tabel `jobs` sudah ada, tetapi Scheduler (`routes/console.php`) dan Job pengingat belum dibuat. |
| **Keandalan (*Reliability*)** | Jika gateway WhatsApp atau AI gagal sesaat, pengiriman dicoba ulang (*retry*) beberapa kali. Jika tetap gagal, status pengingat ditandai `failed`/`gagal` dan ditampilkan di beranda `"Hari ini"`. Kegagalan AI wajib fallback ke templat statis. | Tabel `failed_jobs` tersedia; mekanisme retry job pengingat dan fallback templat belum diimplementasikan. |
| **Zona Waktu (*Timezone*)** | Seluruh timestamp disimpan di database dalam **UTC** dan dikonversi untuk tampilan maupun evaluasi jadwal lokal pengguna dalam **Asia/Jakarta** (atau `users.zona_waktu`). | `config/app.php` sudah mengatur `'timezone' => 'UTC'` dan `AppServiceProvider` menerapkan `CarbonImmutable`. Kolom `zona_waktu` pada `users` belum ada. |
| **Keamanan (*Security*)** | Autentikasi wajib untuk seluruh halaman aplikasi, proteksi endpoint webhook WhatsApp dengan secret token, validasi `nomor_wa` pengirim (hanya nomor pemilik yang dilayani), HTTPS di produksi, dan rahasia disimpan di `.env`. | Auth session, CSRF, 2FA, Passkeys, dan `.gitignore` untuk `.env` sudah aktif. Endpoint webhook dan pembatasan single-user belum diimplementasikan. |
| **Biaya (*Cost Constraints*)** | Biaya bulanan **Rp0**: menggunakan free tier Gemini, Groq, OpenRouter (`:free`), serta pemantauan pemakaian harian di tabel `ai_usage` agar berhenti sebelum melewati batas kuota gratis. | Belum ada tabel `ai_usage` atau integrasi provider AI. |
| **Privasi Data (*Privacy*)** | Hanya judul tugas, deskripsi tugas terkait, tenggat, dan jadwal relevan yang boleh dikirim ke AI. **Catatan harian, mood, dan nomor WhatsApp dilarang dikirim ke AI** (karena input free tier Google AI Studio dapat digunakan untuk pelatihan/peningkatan produk Google). Pengguna dapat mengekspor dan menghapus datanya sendiri. | Penghapusan akun (`DELETE /settings/profile`) sudah tersedia; fitur ekspor data dan filter sanitasi konteks AI belum tersedia. |
| **Kemudahan Interaksi (*Usability*)** | Balasan perintah di WhatsApp cukup satu kata (`"selesai"` atau `"tunda"`, case-insensitive). | Belum diimplementasikan. |

---

## 8. Technical Gap Analysis (Requirement vs Implementasi Aktual)

Tabel berikut memetakan kesenjangan antara spesifikasi Blueprint/PRD (`REQUIREMENT`) dan kondisi repository saat ini (`FACT`):

| No | Requirement | Target (`REQUIREMENT`) | Kondisi Aktual (`FACT`) | Status | Gap | Tindakan yang Diperlukan |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | **Laravel + React + Inertia** | Monorepo SPA Laravel + React via Inertia.js | Laravel `13.42.0`, Inertia `3.7.0`, React `19.2.0`, TypeScript, Tailwind v4, Wayfinder `0.1.23` sudah berjalan | `Implemented` | Tidak ada gap arsitektur dasar | Pertahankan arsitektur monorepo Inertia v3 & Wayfinder |
| 2 | **PostgreSQL** | Database utama PostgreSQL untuk seluruh tabel aplikasi | PostgreSQL `18.6` aktif (`managjeh` & `managjeh_test`), 5 migrasi dasar telah dijalankan, dan CI `.github/workflows/tests.yml` menggunakan service `postgres:18` | `Implemented` | Tabel domain bisnis (`F1`–`F11`) belum dibuat | Buat migrasi tabel domain pada tahap implementasi Minggu 1–6 |
| 3 | **Authentication & Single-User Profile** | Login aman untuk pengguna tunggal + kolom `nomor_wa`, `zona_waktu`, `jam_tenang_mulai`, `jam_tenang_selesai` di `users` | Fortify (Login, 2FA, Passkeys, Verify Email) sudah aktif; registrasi publik masih terbuka; kolom preferensi WA/jam tenang di `users` belum ada | `Partially Implemented` | Kurang 4 kolom profil/preferensi pada `users` & pembatasan single-user | Tambah migrasi kolom ke `users`, update form profil, dan batasi registrasi publik |
| 4 | **Task Management (`F1`)** | CRUD tugas, prioritas, tenggat, status, dan subtugas (`parent_id`) | Tidak ada tabel `tasks`, model `Task`, controller, maupun halaman React | `Not Implemented` | Seluruh fitur `F1` belum tersedia | Buat migrasi `tasks`, model `Task` (self-referencing `parent_id`), controller, FormRequest, dan halaman Inertia |
| 5 | **Schedule Management (`F2`)** | CRUD jadwal mingguan (`judul`, `hari`, `jam_mulai`, `jam_selesai`, `berulang`) | Tidak ada tabel `schedules`, model `Schedule`, controller, maupun halaman React | `Not Implemented` | Seluruh fitur `F2` belum tersedia | Buat migrasi `schedules`, model `Schedule`, controller, dan UI jadwal mingguan |
| 6 | **Reminder Management (`F3`)** | Tabel `reminders` (`tipe`, `target_id`, `kirim_pada`, `status`, `dikirim_pada`), pembuatan otomatis dari deadline & jadwal | Tidak ada tabel `reminders`, model `Reminder`, maupun generator pengingat otomatis | `Not Implemented` | Seluruh antrean domain pengingat belum tersedia | Buat migrasi `reminders`, model `Reminder`, observer/service pembuat pengingat otomatis |
| 7 | **Laravel Scheduler** | Berjalan tiap menit memeriksa `reminders` berstatus `pending` yang jatuh tempo | `routes/console.php` hanya berisi command contoh `inspire` | `Not Implemented` | Belum ada command/job terjadwal di `routes/console.php` | Daftarkan command/job `Schedule::command(...)->everyMinute()` di `routes/console.php` |
| 8 | **Laravel Queue** | Memproses pengiriman pesan WhatsApp secara asinkron dengan retry & penanganan gagal | Konfigurasi `QUEUE_CONNECTION=database` dan tabel `jobs` / `failed_jobs` sudah tersedia; belum ada class Job di `app/Jobs/` | `Partially Implemented` | Infrastruktur tabel queue sudah siap, namun Job pengiriman pengingat belum dibuat | Buat Job (mis. `SendReminderWhatsAppJob`) dengan retry, backoff, dan update status `reminders` |
| 9 | **WhatsApp Gateway (`F3`)** | Mengirim pesan pengingat dan balasan konfirmasi via satu gateway WhatsApp gratis | Belum ada service client HTTP WhatsApp, konfigurasi di `config/services.php`, maupun pemilihan vendor gateway | `Not Implemented` | Provider gateway belum dipilih (`UNKNOWN`) dan client belum dibuat | Pilih gateway WhatsApp, tambahkan config di `config/services.php`, dan buat `WhatsAppService` |
| 10 | **WhatsApp Webhook (`F4`)** | Endpoint webhook menerima balasan `"selesai"`, `"tunda"`, dan teks bebas; validasi token & `nomor_wa` | Belum ada route webhook, controller, maupun middleware verifikasi secret token | `Not Implemented` | Seluruh alur webhook masuk belum tersedia | Buat route POST webhook (kecualikan CSRF atau di `routes/api.php`), middleware secret token, dan parser perintah |
| 11 | **Quiet Hours (`F5`)** | Menahan pengiriman saat jam tenang dan menggeser `kirim_pada` ke `jam_tenang_selesai` | Belum ada kolom jam tenang di `users` maupun logika kalkulasi geser waktu | `Not Implemented` | Logika evaluasi jam tenang dalam zona waktu pengguna belum ada | Implementasikan helper/service kalkulasi jam tenang berbasis `Asia/Jakarta` -> simpan UTC |
| 12 | **Beranda `"Hari ini"` (`F6`)** | Menampilkan jadwal hari ini, tugas jatuh tempo/prioritas, dan status pengingat gagal dalam satu layar | `/dashboard` (`resources/js/pages/dashboard.tsx`) baru menampilkan 3 kotak + 1 area `PlaceholderPattern` | `Partially Implemented` | Data prop dari controller dan komponen UI `"Hari ini"` belum diimplementasikan | Ganti route closure/inertia `/dashboard` dengan `DashboardController` dan bangun UI `"Hari ini"` |
| 13 | **AI Service (`F8`)** | Class `AiService` terpusat dengan urutan fallback Gemini -> Groq -> OpenRouter (`:free`) -> templat tetap | Belum ada direktori `app/Services/` atau class `AiService` | `Not Implemented` | Seluruh abstraksi layanan AI dan fallback belum ada | Buat `AiService` beserta driver/adapter tiap provider dan fallback templat statis |
| 14 | **Gemini Integration (`F8`)** | Provider AI utama menggunakan API key Google AI Studio (free tier) | Belum ada variabel `GEMINI_API_KEY` di `.env.example` / `config/services.php` maupun kode integrasi | `Not Implemented` | Integrasi HTTP ke Gemini API belum ada | Tambahkan konfigurasi Gemini dan implementasi pemanggilan REST API dengan timeout ketat |
| 15 | **Groq Fallback (`F8`)** | Provider cadangan pertama saat Gemini mengembalikan HTTP `429` atau timeout | Belum ada konfigurasi `GROQ_API_KEY` maupun kode integrasi | `Not Implemented` | Integrasi HTTP ke Groq API belum ada | Tambahkan konfigurasi Groq (OpenAI-compatible endpoint) sebagai fallback ke-1 |
| 16 | **OpenRouter Fallback (`F8`)** | Provider cadangan kedua dengan model berlabel `:free` | Belum ada konfigurasi `OPENROUTER_API_KEY` maupun kode integrasi | `Not Implemented` | Integrasi HTTP ke OpenRouter API belum ada | Tambahkan konfigurasi OpenRouter (`:free` model) sebagai fallback ke-2 |
| 17 | **AI Usage Tracking (`F8`)** | Tabel `ai_usage` (`penyedia`, `model`, `jumlah_permintaan`, `tanggal`) untuk membatasi pemakaian sebelum kuota harian habis | Belum ada tabel `ai_usage` maupun mekanisme pencatatan counter harian | `Not Implemented` | Pencatatan dan *circuit breaker* kuota harian belum ada | Buat migrasi `ai_usage` dan integrasikan pengecekan/increment kuota di `AiService` |
| 18 | **Daily Planner (`F7`)** | Menyusun rencana harian (JSON) dari tugas & jadwal lalu mengirimkannya tiap pagi via WhatsApp | Belum ada fitur perencana harian maupun scheduled job pagi | `Not Implemented` | Fitur `F7` belum tersedia | Buat prompt terstruktur JSON di `AiService`, jadwal pagi di Scheduler, dan tampilan rencana di beranda |
| 19 | **AI Chat (`F9`)** | Obrolan teks bebas via WhatsApp atau web dengan konteks tugas/jadwal pengguna, disimpan di `messages` | Belum ada tabel `messages`, endpoint chat, maupun halaman UI obrolan | `Not Implemented` | Fitur `F9` dan penyimpanan riwayat chat belum tersedia | Buat migrasi `messages`, integrasi webhook teks bebas ke `AiService`, dan halaman chat Inertia |
| 20 | **Task Breakdown (`F10`)** | Memecah tugas besar menjadi daftar subtugas (`parent_id`) bertarget tanggal menggunakan keluaran JSON AI | Belum ada endpoint/aksi pemecah tugas maupun penyimpanan subtugas | `Not Implemented` | Fitur `F10` belum tersedia | Tambahkan endpoint action pada `TaskController` yang memanggil `AiService` (JSON schema) dan menyimpan subtugas |
| 21 | **Habit Tracking (`F11`)** | Tabel `habits` & `habit_logs`, pencatatan harian, dan perhitungan streak | Belum ada tabel `habits`, `habit_logs`, model, maupun UI | `Not Implemented` | Seluruh modul kebiasaan belum tersedia | Buat migrasi `habits` & `habit_logs`, kalkulasi streak, dan halaman UI Habit Tracker |
| 22 | **Weekly Summary (`F11`)** | Ringkasan mingguan (tugas selesai & streak kebiasaan) dikirim setiap Minggu malam ke WhatsApp | Belum ada job/command terjadwal Minggu malam | `Not Implemented` | Agregasi data mingguan & pengiriman terjadwal belum ada | Buat scheduled command Minggu malam untuk mengagregasi statistik dan mengirim via `WhatsAppService` |

---

## 9. Roadmap Pengembangan Enam Minggu (`REQUIREMENT`)

Target utama: **MVP sudah dapat digunakan setiap hari mulai akhir Minggu ke-3**, sehingga pemakaian nyata pada minggu ke-3 s.d. ke-6 memberikan umpan balik langsung.

| Minggu | Fokus Target (`REQUIREMENT`) | Tahap |
| --- | --- | --- |
| **Minggu 1** | Proyek Laravel + React (Inertia), autentikasi, tabel `users`, `tasks`, `schedules`, serta halaman daftar tugas dan jadwal | MVP |
| **Minggu 2** | Laravel Scheduler, Queue, tabel `reminders`, satu pengingat WhatsApp terjadwal, serta webhook `"selesai"` dan `"tunda"` | MVP |
| **Minggu 3** | Beranda `"Hari ini"`, jam tenang (*quiet hours*), dan pengingat otomatis dari tenggat tugas (mulai dipakai harian) | MVP |
| **Minggu 4** | `AiService` dengan fallback 3 provider + templat tetap, tabel `ai_usage`, perencana harian AI, dan pengingat pagi berisi rencana | Tahap 2 |
| **Minggu 5** | Obrolan asisten AI (`messages`) dan pemecah tugas otomatis menjadi subtugas | Tahap 2 |
| **Minggu 6** | Pelacak kebiasaan (`habits`, `habit_logs`), ringkasan mingguan, perapian UI/UX, dan pengujian menyeluruh | Tahap 3 |

### Detail Rencana Per Minggu

#### Minggu 1: Pondasi Data & Manajemen Tugas/Jadwal (`F1`, `F2`)
- **Objective:** Menyelesaikan struktur database inti (`users` preferensi, `tasks`, `schedules`) di atas fondasi autentikasi yang sudah ada, serta menyediakan halaman CRUD tugas dan jadwal mingguan.
- **Tasks:**
  1. *(Selesai / `Implemented`)* Inisialisasi Laravel 13 + React 19 + Inertia v3 + PostgreSQL + Fortify Auth.
  2. Buat migrasi penambahan kolom `nomor_wa`, `zona_waktu`, `jam_tenang_mulai`, `jam_tenang_selesai` pada tabel `users`, serta perbarui halaman `/settings/profile`.
  3. Buat migrasi, model, factory, controller, FormRequest, dan halaman React untuk `tasks` (termasuk relasi subtugas `parent_id`).
  4. Buat migrasi, model, factory, controller, FormRequest, dan halaman React untuk `schedules` (jadwal mingguan berulang).
- **Dependencies:** PostgreSQL aktif (`Implemented`), autentikasi Fortify (`Implemented`).
- **Expected Deliverables:** Migrasi `users` (update), `tasks`, `schedules`; halaman `/tasks` dan `/schedules` yang berfungsi penuh.
- **Acceptance Criteria:** Pengguna dapat menambah/mengubah/menyelesaikan tugas & subtugas (`F1`) serta menyusun jadwal kuliah/kegiatan mingguan dalam zona waktu `Asia/Jakarta` (`F2`).
- **Testing Requirements:** Feature test Pest untuk CRUD `tasks` (termasuk perubahan status dan `parent_id`), CRUD `schedules`, pembaruan profil nomor WA/jam tenang, serta `npm run types:check`.

#### Minggu 2: Mesin Pengingat, Queue, Gateway & Webhook WhatsApp (`F3`, `F4`)
- **Objective:** Membangun pipa pengiriman pengingat terjadwal ke WhatsApp dan memproses balasan dua arah (`"selesai"` / `"tunda"`).
- **Tasks:**
  1. Buat migrasi dan model `reminders` (`tipe`, `target_id`, `kirim_pada`, `status`, `dikirim_pada`).
  2. Implementasikan `WhatsAppService` untuk mengirim pesan keluar melalui gateway yang dipilih.
  3. Buat Queue Job pengiriman pengingat beserta penanganan retry dan status gagal.
  4. Konfigurasikan Laravel Scheduler di `routes/console.php` setiap menit untuk mengambil pengingat `pending` yang sudah jatuh tempo.
  5. Buat endpoint webhook WhatsApp dengan verifikasi secret token, validasi `nomor_wa` pengguna tunggal, dan handler kata kunci `"selesai"` (ubah status tugas) serta `"tunda"` (geser `kirim_pada` +30 menit).
- **Dependencies:** Tabel `users` (`nomor_wa`) dan `tasks` dari Minggu 1; kredensial gateway WhatsApp di `.env`.
- **Expected Deliverables:** Tabel `reminders`, Scheduler aktif, Queue Job pengiriman WA, endpoint webhook masuk, dan kemampuan uji kirim 1 pesan nyata ke nomor pemilik.
- **Acceptance Criteria:** Pengingat terjadwal terkirim ke WhatsApp dalam ≤ 2 menit (`F3`), dan balasan `"selesai"` atau `"tunda"` dari WhatsApp memperbarui database serta membalas konfirmasi singkat (`F4`).
- **Testing Requirements:** Feature test dengan `Http::fake()` untuk pengiriman gateway WA, test `Queue::fake()` pada command Scheduler, serta feature test webhook untuk skenario token tidak valid, nomor asing ditolak, perintah `"selesai"`, dan perintah `"tunda"`.

#### Minggu 3: Beranda "Hari Ini", Jam Tenang & Otomatisasi MVP (`F3`, `F5`, `F6`)
- **Objective:** Menyatukan alur penggunaan harian di beranda `/dashboard` (`"Hari ini"`), menerapkan aturan jam tenang, dan mengotomatiskan pembuatan pengingat dari tenggat tugas sehingga MVP siap digunakan setiap hari.
- **Tasks:**
  1. Implementasikan logika *Quiet Hours* (`F5`) pada penjadwalan/pengiriman pengingat agar waktu pengiriman yang jatuh di antara `jam_tenang_mulai` dan `jam_tenang_selesai` digeser ke `jam_tenang_selesai`.
  2. Buat mekanisme otomatis yang menghasilkan atau memperbarui entri `reminders` ketika tugas dengan `tenggat` atau jadwal dibuat/diubah.
  3. Ubah `/dashboard` (`resources/js/pages/dashboard.tsx`) menjadi Beranda `"Hari ini"` (`F6`) yang menampilkan jadwal kegiatan hari ini, daftar tugas jatuh tempo/prioritas, dan indikator pengingat gagal.
- **Dependencies:** Modul `tasks`, `schedules`, `reminders`, dan `WhatsAppService` dari Minggu 1–2.
- **Expected Deliverables:** Beranda `"Hari ini"` fungsional, proteksi jam tenang aktif, generator pengingat otomatis dari deadline.
- **Acceptance Criteria:** Tidak ada pesan WhatsApp yang dikirim pada jam tenang (`F5`), beranda menampilkan agenda hari ini secara akurat dalam `Asia/Jakarta` (`F6`), dan aplikasi mulai digunakan setiap hari oleh pengguna.
- **Testing Requirements:** Unit/Feature test konversi UTC <-> `Asia/Jakarta` terutama di sekitar tengah malam dan pergantian jam tenang, serta Feature test Inertia props pada `/dashboard`.

#### Minggu 4: Layanan AI Multi-Provider & Perencana Harian (`F7`, `F8`)
- **Objective:** Mengintegrasikan `AiService` dengan urutan fallback 3 penyedia gratis + templat statis, melacak kuota di `ai_usage`, dan mengirimkan rencana harian setiap pagi.
- **Tasks:**
  1. Buat migrasi dan model `ai_usage` (`penyedia`, `model`, `jumlah_permintaan`, `tanggal`).
  2. Bangun `AiService` dengan urutan: **Gemini** -> **Groq** (saat 429/timeout/error) -> **OpenRouter `:free`** -> **Templat tetap tanpa AI**.
  3. Terapkan filter privasi data (hanya kirim tugas & jadwal; larang kirim nomor WA atau catatan sensitif) dan pembatas kuota harian.
  4. Buat fitur Perencana Harian AI (`F7`) dengan output JSON terstruktur serta jadwal pengiriman rencana pagi via WhatsApp.
- **Dependencies:** MVP Minggu 1–3 selesai; API key Gemini, Groq, dan OpenRouter di `.env`.
- **Expected Deliverables:** Tabel `ai_usage`, class `AiService`, generator rencana harian JSON, dan pengingat rencana pagi.
- **Acceptance Criteria:** Saat provider utama gagal, `AiService` otomatis berpindah ke fallback berikutnya, dan saat semua provider gagal, pesan pengingat tetap terkirim memakai templat tetap (`F8`); rencana harian pagi terbentuk dari tugas & jadwal (`F7`).
- **Testing Requirements:** Feature/Unit test `AiService` menggunakan `Http::fake()` untuk mensimulasikan Gemini 429 -> Groq sukses, semua AI gagal -> templat statis sukses, serta batas kuota `ai_usage`.

#### Minggu 5: Obrolan Asisten AI & Pemecah Tugas (`F9`, `F10`)
- **Objective:** Memungkinkan percakapan dua arah dengan konteks tugas/jadwal pengguna serta fitur pemecah tugas besar menjadi subtugas otomatis.
- **Tasks:**
  1. Buat migrasi dan model `messages` (`arah`, `isi`, `dibuat_pada`).
  2. Hubungkan balasan teks bebas pada webhook WhatsApp (di luar `"selesai"`/`"tunda"`) dan halaman obrolan web ke `AiService` dengan menyertakan konteks tugas & jadwal aktif (`F9`).
  3. Buat fitur Pemecah Tugas (`F10`) yang meminta keluaran JSON dari `AiService` dan menyimpan subtugas ke tabel `tasks` (`parent_id`).
- **Dependencies:** `AiService` (Minggu 4), `tasks` (Minggu 1), dan webhook WhatsApp (Minggu 2).
- **Expected Deliverables:** Tabel `messages`, halaman UI Obrolan Asisten, integrasi chat WhatsApp, dan tombol/aksi "Pecah Tugas dengan AI".
- **Acceptance Criteria:** Balasan teks bebas dijawab oleh AI sesuai konteks tugas pengguna (`F9`), dan tugas besar dapat dipecah menjadi subtugas bertanggal target di database (`F10`).
- **Testing Requirements:** Feature test penyimpanan `messages`, routing pesan webhook non-perintah ke `AiService`, dan validasi parsing JSON subtugas.

#### Minggu 6: Pelacak Kebiasaan, Ringkasan Mingguan & Perapian (`F11`)
- **Objective:** Menambahkan pelacak kebiasaan harian, pengiriman ringkasan mingguan otomatis tiap Minggu malam, serta pengujian dan perapian akhir.
- **Tasks:**
  1. Buat migrasi dan model `habits` serta `habit_logs` beserta perhitungan *streak* (`F11`).
  2. Bangun UI Habit Tracker di frontend React.
  3. Buat scheduled job Minggu malam untuk mengirim ringkasan mingguan (tugas selesai & streak kebiasaan) ke WhatsApp (`F11`).
  4. Tambahkan fitur ekspor/hapus data pengguna dan lakukan pengujian end-to-end serta perapian UI.
- **Dependencies:** Seluruh modul Minggu 1–5.
- **Expected Deliverables:** Tabel `habits` & `habit_logs`, halaman Habit Tracker, job ringkasan mingguan, test suite lengkap hijau.
- **Acceptance Criteria:** Streak kebiasaan tercatat dengan benar dan ringkasan mingguan terkirim setiap Minggu malam (`F11`).
- **Testing Requirements:** Feature test kalkulasi streak lintas hari (`Asia/Jakarta`), test job ringkasan mingguan, `composer ci:check` lulus penuh.

---

## 10. Business & Technical Constraints (`REQUIREMENT` & `FACT`)

1. **Anggaran Rp0 (`REQUIREMENT`):** Tidak boleh menggunakan layanan berbayar wajib untuk AI API, database, maupun pengiriman pesan.
2. **Pengguna Tunggal (`REQUIREMENT`):** Aplikasi hanya melayani satu pengguna pemilik produk; pesan webhook dari nomor WhatsApp selain `users.nomor_wa` wajib ditolak.
3. **Batas Privasi Free Tier AI (`REQUIREMENT`):** Permintaan pada free tier Google AI Studio (Gemini) dapat ditinjau atau digunakan Google untuk meningkatkan produknya. Oleh karena itu, catatan pribadi/mood dan nomor telepon dilarang dimasukkan ke dalam prompt AI.
4. **Ketergantungan Proses Latar Belakang (`FACT` & `REQUIREMENT`):** Pengingat tepat waktu (≤ 2 menit) menuntut proses cron (`schedule:run` tiap menit) dan queue worker (`queue:work`) yang aktif terus-menerus.

---

## 11. Evaluasi Risiko, Keputusan Teknis & Strategi Mitigasi

Bagian ini mendokumentasikan risiko dari Blueprint/PRD (`REQUIREMENT`) sekaligus hasil evaluasi teknis arsitektur terhadap kondisi nyata (`RECOMMENDATION`):

| No | Area Risiko / Keputusan Teknis | Dampak | Mitigasi di Blueprint/PRD (`REQUIREMENT`) | Evaluasi Teknis & Rekomendasi (`RECOMMENDATION`) |
| --- | --- | --- | --- | --- |
| 1 | **Kelayakan WhatsApp Gateway Gratis** | Gateway tidak resmi (unofficial WA Web wrapper) rawan terputus sesi atau nomor terblokir; gateway resmi (Meta Cloud API) memiliki aturan template 24-hour window. | Uji gateway terlebih dahulu di Minggu 2; siapkan cadangan notifikasi lewat email (SMTP Gmail sudah teruji di lokal). | Gunakan abstraksi `WhatsAppGatewayInterface` agar mudah mengganti provider (mis. Fonnte free tier / WAHA self-hosted / Meta Cloud API test number) dan gunakan fallback email jika pengiriman WA gagal total setelah retry. |
| 2 | **Keterbatasan Layanan AI Free Tier** | Rate limit per menit (RPM) dan per hari (RPD) dapat berubah sewaktu-waktu atau mengalami lonjakan latensi. | Fallback berurutan: Gemini -> Groq -> OpenRouter (`:free`) -> Templat tetap tanpa AI; catat kuota di `ai_usage`. | Tetapkan HTTP timeout pendek per provider (mis. 8–10 detik) agar antrean Queue tidak menggantung, dan simpan batas kuota harian di config/env agar mudah disesuaikan tanpa deploy ulang. |
| 3 | **Kelayakan Hosting Gratis untuk Scheduler & Queue Worker** | Banyak PaaS gratis menghentikan container saat idle (*scale-to-zero*) atau tidak menyediakan proses background worker + cron 1 menit secara gratis. | Belum ditentukan di PRD (`UNKNOWN`). | **Penting:** Jika memakai shared/free PaaS tanpa daemon worker, jalankan `queue:work --stop-when-empty` dari dalam cron tiap menit atau gunakan VPS gratis/murah (mis. Oracle Cloud Always Free / home server / tunnel lokal) yang mendukung systemd/cron penuh. |
| 4 | **Ketersediaan PostgreSQL Hosting Gratis** | Beberapa layanan cloud gratis membatasi ukuran storage, koneksi simultan, atau menghapus database setelah periode tidak aktif. | Menggunakan PostgreSQL. | Layanan seperti Neon atau Supabase menyediakan free tier PostgreSQL permanen, namun perhatikan *cold start* koneksi dan jadwalkan backup berkala (`pg_dump`). |
| 5 | **Penyimpanan Waktu UTC & Konversi `Asia/Jakarta`** | Kesalahan konversi di sekitar pukul `00:00 WIB` (`17:00 UTC` hari sebelumnya) dapat membuat tugas/jadwal/streak jatuh di tanggal yang salah. | Simpan semua waktu dalam UTC dan tampilkan dalam `Asia/Jakarta`; uji di sekitar tengah malam. | Saat melakukan query harian ("Hari ini" atau `ai_usage.tanggal`), selalu hitung batas awal dan akhir hari menggunakan `CarbonImmutable::now('Asia/Jakarta')->startOfDay()->utc()` dan `endOfDay()->utc()`. |
| 6 | **Retry, Idempotency & Risiko Pengiriman Pesan Ganda** | Jika Scheduler berjalan tiap menit sementara Queue belum selesai memproses pengingat, pengingat yang sama berisiko diambil dua kali dan dikirim ganda. | Tandai status pengingat saat diproses dan terkirim. | Tambahkan status intermediate `processing` secara atomik (`SELECT ... FOR UPDATE SKIP LOCKED` atau update bersyarat `WHERE status = 'pending'`) sebelum mendorong ke Queue, serta simpan `external_message_id` / `idempotency_key` untuk mencegah duplikasi. |
| 7 | **Keamanan Webhook WhatsApp** | Endpoint webhook publik dapat ditembak pihak luar untuk memalsukan perintah `"selesai"` atau menghabiskan kuota AI. | Lindungi webhook dengan token rahasia dan tolak pesan dari nomor selain milik pengguna. | Gunakan perbandingan `hash_equals()` untuk secret token header/query, terapkan rate limiter khusus pada route webhook, dan catat ID pesan masuk untuk menolak *webhook replay* (pesan duplikat dari gateway). |
| 8 | **Privasi Data pada Prompt AI** | Kebocoran data sensitif ke penyedia AI pihak ketiga. | Hanya kirim judul tugas, tenggat, dan jadwal; jangan kirim catatan harian, mood, atau nomor WA. | Buat DTO/mapper khusus (`AiContextBuilder`) yang hanya memilih field whitelist (`tasks.judul`, `tasks.tenggat`, `tasks.prioritas`, `schedules.judul`, `schedules.hari`, `schedules.jam_mulai`, `schedules.jam_selesai`) sebelum dikirim ke `AiService`. |

---

## 12. Open Questions & TODO (`UNKNOWN`)

Pertanyaan terbuka dari PRD dan hasil audit yang membutuhkan keputusan pemilik produk sebelum atau saat implementasi dimulai:

1. **Pemilihan Gateway WhatsApp (`UNKNOWN`):**
   - [ ] Gateway WhatsApp spesifik mana yang akan digunakan (mis. Fonnte, Wablas, WAHA self-hosted, atau Meta WhatsApp Cloud API), dan bagaimana format payload webhook serta kuota gratisnya?
2. **Jam Tenang Bawaan (`UNKNOWN`):**
   - [ ] Pukul berapa nilai default `jam_tenang_mulai` dan `jam_tenang_selesai` untuk pengguna baru (mis. `22:00` s.d. `06:00` WIB)?
3. **Input Jadwal Kuliah (`REQUIREMENT` / Konfirmasi Format):**
   - [ ] PRD telah menetapkan bahwa impor otomatis jadwal kampus berada di luar lingkup (*out of scope*), sehingga jadwal diketik manual di UI web. Apakah diperlukan fitur impor file sederhana (CSV/JSON) di masa depan atau murni form input manual?
4. **Target Hosting Gratis (`UNKNOWN`):**
   - [ ] Di platform mana aplikasi, database PostgreSQL, Queue Worker, dan Scheduler akan di-hosting secara gratis (agar URL webhook publik HTTPS dapat diakses oleh gateway WhatsApp)?
5. **Kebijakan Registrasi Single-User (`ASSUMPTION` / Konfirmasi Teknis):**
   - [ ] Karena sistem ditujukan untuk *single user*, apakah `Features::registration()` di `config/fortify.php` sebaiknya dinonaktifkan setelah akun utama dibuat (atau dibatasi hanya 1 baris di tabel `users`)?
6. **Penyelarasan Teknis Repository Saat Ini (`TODO`):**
   - [ ] Menyelaraskan tipe `User` di `resources/js/types/auth.ts` (`avatar?`, `two_factor_enabled?`) dengan model `App\Models\User` aktual.
