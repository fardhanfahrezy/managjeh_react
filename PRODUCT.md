# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Mahasiswa (pengguna tunggal pada tahap awal, dengan arsitektur siap multi-user) yang harus menyeimbangkan perkuliahan, tugas individu/kelompok, proyek akademik maupun kepanitiaan/organisasi, serta agenda harian dan mingguan yang padat. Pengguna membutuhkan satu pusat kendali yang dapat diakses cepat di sela aktivitas kampus untuk melihat apa yang harus dikerjakan hari ini, memantau tenggat waktu, dan menerima pengingat tepat waktu di WhatsApp tanpa harus terus-menerus membuka aplikasi.

## Product Purpose

Managjeh adalah aplikasi web **Personal OS Mahasiswa** yang menempatkan **Website Managjeh sebagai pusat kendali seluruh modul dan pusat interaksi AI**, serta **WhatsApp sebagai kanal pengingat dan notifikasi otomatis (outbound)**.

- **MVP 1 (`Implemented & Verified`):** Mengelola **Task (beserta subtugas 1 tingkat)**, **Project**, **Calendar (termasuk agenda berulang)**, **Dashboard terpadu**, **Pengingat WhatsApp (OTP + quiet hours)**, **AI Planner** (3 prioritas utama harian), serta **Ekspor Data & Retensi Privasi**.
- **MVP 2 & MVP 3 (`CONFIRMED` / `Planned`):** Memperluas website menjadi **AI Chat Hub** kontekstual berbasis **AI Orchestrator + Internal MCP Server** (berdampingan dengan `/ai-planner`, lengkap dengan riwayat percakapan, rencana tindakan _propose → approve → execute_, dan audit trail), memperluas **WhatsApp Notification Service** (persiapan kegiatan, ringkasan harian/mingguan, kesiapan multi-channel Telegram), serta menambahkan modul **Habits/Tidur**, **Finance/Anggaran/Tagihan**, dan **Refleksi Mingguan**.

Keberhasilan produk diukur dari:

1. Pengguna dapat mencatat, mengelompokkan, dan memperbarui tugas serta proyek dengan friksi minimal.
2. Pengguna dapat memetakan jadwal kuliah/kegiatan (termasuk agenda _all-day_ dan berulang) berdampingan dengan tenggat tugas dalam zona waktu lokal mereka.
3. Pengguna mendapatkan 3 prioritas harian yang masuk akal dan dapat ditindaklanjuti dari AI Planner tanpa kehilangan kendali atas data mereka.
4. Pengguna menerima pengingat WhatsApp yang relevan dan tepat waktu tanpa gangguan pada jam istirahat (_quiet hours_).

## Positioning

Berbeda dari aplikasi _to-do list_ atau kalender generik yang pasif dan terpisah-pisah, Managjeh menyatukan manajemen **Task + Project + Calendar Berulang** dengan dua mekanisme proaktif yang dirancang khusus untuk ritme mahasiswa:

1. **AI Planner Berjenjang yang Menghormati Kendali Pengguna:** Menganalisis tugas aktif, tenggat, estimasi durasi, dan slot kosong kalender untuk menyarankan **3 prioritas utama harian** (melalui rantai fallback `Gemini` → `Grok` → `OpenRouter` → `Rule-based Fallback` deterministik) tanpa pernah mengubah data tugas, proyek, atau agenda secara sepihak.
2. **Pengingat WhatsApp Cerdas Satu Arah:** Mengirim pengingat multi-tahap (mis. H-1 dan H-1 jam) langsung ke nomor WhatsApp yang terverifikasi OTP dengan perlindungan _quiet hours_ (`00:00–05:00` waktu lokal pengguna), sementara seluruh pengelolaan data tetap terpusat dan rapi di web.

## Operating Context

- Digunakan setiap hari oleh mahasiswa melalui peramban desktop (saat belajar/mengerjakan tugas di laptop) maupun peramban mobile (saat berpindah kelas atau mengecek jadwal di kampus).
- Siklus penggunaan utama mengikuti ritme harian dan mingguan perkuliahan:
    - **Pagi hari:** Membuka Dashboard dan meninjau 3 prioritas utama dari AI Planner serta jadwal kelas/agenda hari ini.
    - **Sepanjang hari:** Mencatat tugas baru, memperbarui status (`todo`, `in_progress`, `completed`, `cancelled`), mengecek progres proyek, atau menyesuaikan jadwal kalender.
    - **Di luar aplikasi:** Menerima notifikasi pengingat tugas dan agenda melalui WhatsApp (di luar jam tenang `00:00–05:00`).

## Capabilities and Constraints

### Cakupan Fungsional MVP 1

1. **Task Management:** CRUD tugas, status (`todo`, `in_progress`, `completed`, `cancelled`), prioritas (`low`, `medium`, `high`, `urgent`), tenggat waktu (`due_at`), estimasi durasi (`estimated_minutes`), relasi opsional ke proyek (`project_id`), dan subtugas maksimal 1 tingkat (`parent_id` sesuai `D-09`).
2. **Project Management:** CRUD proyek, status (`active`, `completed`, `archived`), rentang tanggal (`start_date`, `due_date`), dan perhitungan progres otomatis dari rasio tugas selesai terhadap total tugas non-cancelled.
3. **Calendar & Agenda Berulang (`D-08`, `D-10`):** Kalender internal sebagai sumber utama (harian, mingguan, bulanan), mendukung agenda berjam (`starts_at`–`ends_at`), agenda seharian (`all_day`), _recurrence rule_ (`rrule`), pengecualian (`recurrence_exceptions`), serta _single occurrence override_ (`parent_event_id` & `recurrence_id`).
4. **Dashboard Aggregation:** Ringkasan terpadu yang menampilkan sapaan kontekstual, 3 prioritas AI Planner hari ini, tugas jatuh tempo hari ini & terlambat, agenda hari ini, progres proyek aktif, dan status pengingat WhatsApp.
5. **WhatsApp Reminder & OTP Verification (`D-02`, `D-05`–`D-07`, `D-14`):** Integrasi melalui kontrak `WhatsAppGatewayInterface`, wajib verifikasi OTP sebelum pengiriman aktif, mendukung multi-reminder per tugas, pembatalan otomatis saat tugas selesai/dibatalkan, dan penundaan otomatis selama _quiet hours_ (`00:00–05:00` waktu lokal).
6. **AI Planner (`D-03`, `D-04`, `D-12`, `D-13`):** Eksekusi otomatis (Scheduler harian & perubahan data signifikan) dan manual via kontrak `AiPlannerProviderInterface` (`Gemini` → `Grok` → `OpenRouter` → `Rule-based Fallback`), dilengkapi _timeout_, _retry_ terbatas, _circuit breaker_, dan pencatatan metadata di `ai_planner_runs`.
7. **Pengaturan & Autentikasi (`D-01`):** Autentikasi Laravel Fortify (2FA TOTP & Passkeys/WebAuthn), registrasi publik **dinonaktifkan** (akun dibuat melalui `php artisan user:provision`), pengaturan zona waktu IANA (`users.timezone`, default `Asia/Jakarta`), jam tenang, dan verifikasi nomor WhatsApp.

### Batasan Teknis & Domain yang Mengikat

- **Tahap Berikutnya (`CONFIRMED` / `Planned`, Belum Diimplementasikan di MVP 1):** Website **AI Chat + AI Orchestrator + Internal MCP Server** dan perluasan notifikasi outbound/multi-channel Telegram (MVP 2); modul **Habits/Rutinitas/Tidur**, **Finance/Anggaran/Tagihan**, dan **Evaluasi/Refleksi Mingguan** (MVP 3).
- **Tetap Di Luar Cakupan / Dilarang:** Fokus timer terpisah, catatan kuliah, karier/portofolio, manajemen kontak, dokumen, sinkronisasi Google Calendar eksternal (`D-10`), menjadikan WhatsApp sebagai kanal utama chat AI / CRUD (`D-02`, `D-16`), serta menyimpan password bank, PIN, OTP, atau kredensial e-wallet (**dilarang keras**).
- **Kepemilikan Data (`user_id`):** Seluruh entitas bisnis wajib terikat ke `user_id` pengguna yang sedang login; backend tidak pernah mempercayai `user_id` dari _request_ klien.
- **Zona Waktu:** Seluruh _timestamp_ absolut disimpan dalam **UTC** di PostgreSQL; konversi tampilan, evaluasi "hari ini", dan evaluasi _quiet hours_ menggunakan `users.timezone`. Tanggal kalender (`start_date`, `due_date`, `end_date`, `planning_date`) disimpan sebagai `date` lokal (`Y-m-d`).
- **Soft Delete & Retensi (`D-11`, `D-15`):** `projects`, `tasks`, dan `calendar_events` menggunakan _soft delete_ (`deleted_at`). Tidak boleh menyimpan OTP mentah, kredensial, atau isi percakapan sensitif di log/database.
- **Konvensi Bahasa:** Seluruh skema database, model, _controller_, dan _route_ menggunakan **Bahasa Inggris `snake_case`**, sedangkan teks antarmuka pengguna (UI) menggunakan **Bahasa Indonesia**.

## Brand Commitments

- **Nama Produk:** Managjeh (Personal OS Mahasiswa).
- **Voice & Tone:** Tenang, fokus, dan lugas. Menggunakan Bahasa Indonesia yang bersih, jelas, dan langsung pada inti persoalan, dipadukan secara natural dengan istilah produktivitas yang sudah akrab bagi mahasiswa (seperti _Task_, _Project_, _Deadline_, _Calendar_, _Dashboard_, _AI Planner_).
- **Karakter Komunikasi:** Membantu pengguna berpikir jernih saat menghadapi banyak tenggat waktu—tidak menggurui, tidak bertele-tele, dan tidak menggunakan bahasa promosi yang berlebihan. Pesan error, status sistem, rekomendasi AI Planner, dan notifikasi WhatsApp harus ringkas serta mudah dipindai dalam sekali baca.

## Evidence on Hand

- Spesifikasi produk dan arsitektur lengkap terdokumentasi di `AGENTS.md`, `CLAUDE.md`, `README.md`, dan direktori `docs/` (`docs/PRD.md`, `docs/DATABASE_SCHEMA.md`, `docs/BUSINESS_RULES.md`, `docs/FRONTEND_ARCHITECTURE.md`, `docs/API_INTEGRATION.md`, `docs/SYSTEM_ARCHITECTURE.md`, `docs/USER_FLOW.md`, `docs/SECURITY.md`, `docs/DEPLOYMENT.md`, `docs/DEVELOPMENT_GUIDELINES.md`).
- Implementasi **MVP 1 (Phase 0 s.d. Phase 7 + Optimasi Performa)** telah selesai dan terverifikasi (`12` migrasi PostgreSQL, `84` Pest tests lulus dengan `569` assertions & `2` skipped pada `RegistrationTest`, PHPStan level 7 `0` errors, TypeScript `0` errors, dan Vite+ `vp check` lulus). Arah **MVP 2** (Website AI Chat + Internal MCP + perluasan notifikasi outbound) dan **MVP 3** (Habits, Finance, Refleksi Mingguan) berstatus `Planned` (`CONFIRMED`).
- Aset merek yang tersedia di repositori saat ini adalah ikon standar aplikasi di `public/favicon.svg`, `public/favicon.ico`, dan `public/apple-touch-icon.png`. Tidak ada klaim testimoni, metrik pelanggan eksternal, atau studi kasus pihak ketiga—pekerjaan ke depan dilarang mengarang klaim atau bukti sosial palsu.

## Product Principles

1. **Kendali Penuh di Tangan Pengguna:** AI Planner dan otomatisasi sistem bertugas menyarankan prioritas serta menjadwalkan pengingat, namun tidak pernah mengubah atau menghapus tugas, proyek, maupun agenda tanpa persetujuan eksplisit pengguna.
2. **Kejelasan di Atas Kerumitan:** Mahasiswa membuka Managjeh untuk mengurangi beban kognitif. Setiap tampilan harus langsung menjawab "apa yang paling penting dikerjakan sekarang" tanpa menumpuk fitur di luar cakupan MVP.
3. **Proaktif namun Menghormati Waktu Istirahat:** Pengingat WhatsApp hanya dikirim ke nomor yang sudah terverifikasi, otomatis dibatalkan jika tugas sudah selesai, dan selalu menghormati _quiet hours_ (`00:00–05:00` waktu lokal).
4. **Andal Saat Layanan Eksternal Terganggu:** Kegagalan satu vendor AI atau gateway WhatsApp tidak boleh menghentikan alur kerja utama pengguna; rantai fallback deterministik dan antrean (_queue_) menjaga aplikasi tetap responsif.
5. **Fondasi Siap Tumbuh (Single-User Now, Multi-User Ready):** Meskipun saat ini digunakan secara pribadi dengan provisioning akun tertutup (`D-01`), setiap batas otorisasi, isolasi `user_id`, dan zona waktu per pengguna dijaga ketat sejak hari pertama.

## Accessibility & Inclusion

- Antarmuka web harus nyaman digunakan pada layar laptop maupun ponsel mahasiswa, mendukung navigasi keyboard penuh pada form dan dialog, menggunakan struktur HTML semantik, serta menjaga kontras baca yang jelas untuk penggunaan jangka panjang.
