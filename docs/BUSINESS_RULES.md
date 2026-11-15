# Business Rules — Managjeh (Asisten AI Mahasiswa)

Dokumen ini memisahkan aturan bisnis yang **sudah diimplementasikan di kode aktual (`FACT`)** dan aturan bisnis produk **Asisten AI Mahasiswa yang direncanakan berdasarkan Blueprint & PRD (`REQUIREMENT` / `PLANNED`)**.

---

## 1. Aturan yang Sudah Diimplementasikan (`FACT` — Implemented)

### 1.1 Identitas, Registrasi & Verifikasi Email

- **Email Unik & Huruf Kecil Otomatis:**
    - Kolom `users.email` memiliki constraint `UNIQUE`.
    - `config/fortify.php` mengaktifkan `'lowercase_usernames' => true` dan `ProfileUpdateRequest::prepareForValidation()` mengubah input `email` ke huruf kecil (`Str::lower`).
- **Validasi Profil (`ProfileValidationRules`):**
    - `name`: wajib diisi, string, maks. 255 karakter.
    - `email`: wajib diisi, string, format email valid, maks. 255 karakter, unik di tabel `users`.
- **Verifikasi Email Wajib (`MustVerifyEmail`):**
    - Model `App\Models\User` mengimplementasikan `MustVerifyEmail`.
    - Pengguna yang belum memverifikasi email diarahkan ke `/email/verify` saat mengakses `/dashboard`, `/settings/security`, `/settings/appearance`, atau `DELETE /settings/profile`.
    - Perubahan alamat email pada `/settings/profile` secara otomatis mereset `email_verified_at = null`.

### 1.2 Aturan Password & Autentikasi Multi-Faktor

- **Aturan Password (`AppServiceProvider`):**
    - Pada `APP_ENV=production`, password wajib minimal 12 karakter, mengandung huruf besar & kecil (`mixedCase`), huruf (`letters`), angka (`numbers`), simbol (`symbols`), dan tidak tercatat pada database kebocoran password (`uncompromised()`).
    - Perubahan password (`PUT /settings/password`) dan penghapusan akun (`DELETE /settings/profile`) wajib menyertakan `current_password`.
- **Two-Factor Authentication (2FA) & Passkeys:**
    - 2FA TOTP mengharuskan konfirmasi kode OTP 6-digit (`confirm => true`) sebelum aktif.
    - Pengelolaan 2FA dan Passkey dilindungi konfirmasi password/passkey (`confirmPassword => true`, timeout `10800` detik / 3 jam).
- **Rate Limiting (`FortifyServiceProvider` & `routes/settings.php`):**
    - `login`: maks. 5 percobaan/menit per `email|ip`.
    - `two-factor`: maks. 5 percobaan/menit per `session('login.id')`.
    - `passkeys`: maks. 10 percobaan/menit per `(credential.id || session_id)|ip`.
    - `user-password.update`, `verification.send`, `verification.verify`: `throttle:6,1`.

### 1.3 Proteksi Database & Waktu Sistem

- `DB::prohibitDestructiveCommands(app()->isProduction())` memblokir perintah destruktif (`migrate:fresh`, `db:wipe`, dll.) di lingkungan `production`.
- `config/app.php` menggunakan timezone `'UTC'` dan `AppServiceProvider` menetapkan `Date::use(CarbonImmutable::class)`.

---

## 2. Aturan Bisnis Asisten AI Mahasiswa (`REQUIREMENT` / `PLANNED` — Not Implemented)

> **Catatan:** Seluruh aturan bisnis pada Bagian 2 ini berasal dari Blueprint & PRD (`REQUIREMENT`) dan saat ini **belum diimplementasikan di repository (`Not Implemented`)**.

### 2.1 Aturan Pengguna Tunggal (_Single-User Mode_)

1. **Satu Pengguna Pemilik Produk (`REQUIREMENT`):**
    - Aplikasi pada versi pertama hanya ditujukan untuk satu pengguna (mahasiswa pemilik produk), bukan layanan multi-akun publik.
    - Setiap pengguna menyimpan nomor WhatsApp (`nomor_wa`), zona waktu (`zona_waktu`, default `Asia/Jakarta`), serta rentang jam tenang (`jam_tenang_mulai` dan `jam_tenang_selesai`).
2. **Pembatasan Registrasi (`RECOMMENDATION`):**
    - Setelah akun pemilik dibuat, pendaftaran publik (`Features::registration()`) harus dinonaktifkan atau dibatasi agar pihak luar tidak dapat membuat akun tambahan.

### 2.2 Task Management (`F1`, `F10`)

1. **Pembuatan Tugas (`REQUIREMENT`):**
    - Setiap tugas wajib memiliki `judul`, `prioritas`, dan `status`, serta opsional memiliki `deskripsi`, `tenggat` (deadline), dan `parent_id`.
2. **Prioritas Tugas (`REQUIREMENT`):**
    - Tugas diklasifikasikan berdasarkan tingkat prioritas (mis. `tinggi` / `sedang` / `rendah` atau `high` / `medium` / `low`) untuk menentukan urutan tampilan di Beranda `"Hari ini"` dan penyusunan rencana harian AI (`F7`).
3. **Deadline / Tenggat (`REQUIREMENT`):**
    - Input tenggat dari pengguna dimasukkan dalam waktu lokal (`Asia/Jakarta`) dan dikonversi ke **UTC** saat disimpan ke kolom `tasks.tenggat`.
    - Ketika tugas memiliki `tenggat`, sistem secara otomatis menjadwalkan entri pengingat di tabel `reminders` (Minggu 3).
4. **Status Tugas (`REQUIREMENT`):**
    - Tugas memiliki status aktif (`pending` / `belum selesai`) dan status tuntas (`done` / `selesai`).
5. **Subtasks / Subtugas (`REQUIREMENT` — `F1`, `F10`):**
    - Subtugas disimpan di tabel `tasks` yang sama dengan mengisi `parent_id` mengarah ke `id` tugas induk.
    - Subtugas dapat dibuat secara manual oleh pengguna (`F1`) maupun dihasilkan otomatis melalui fitur Pemecah Tugas AI (`F10`) lengkap dengan tanggal target masing-masing.
6. **Penyelesaian Tugas (`REQUIREMENT`):**
    - Tugas dapat ditandai selesai melalui dua jalur:
        1. Aksi di antarmuka web (Beranda `"Hari ini"` atau halaman Daftar Tugas).
        2. Membalas `"selesai"` pada pengingat WhatsApp (`F4`).
    - Saat tugas ditandai selesai, pengingat terkait yang masih berstatus `pending` tidak boleh lagi dikirimkan.

### 2.3 Schedule Management (`F2`)

1. **Jadwal Mingguan & Kegiatan Berulang (`REQUIREMENT`):**
    - Setiap jadwal memiliki `judul`, `hari`, `jam_mulai`, `jam_selesai`, dan flag `berulang`.
    - Jika `berulang = true`, kegiatan tersebut otomatis muncul setiap minggu pada hari yang sama tanpa perlu diinput ulang.
2. **Validasi Jam Mulai dan Selesai (`RECOMMENDATION`):**
    - `jam_selesai` wajib lebih besar (setelah) `jam_mulai` pada hari yang sama.
3. **Tampilan Jadwal Harian (`REQUIREMENT` — `F2`, `F6`):**
    - Beranda `"Hari ini"` hanya menampilkan jadwal yang cocok dengan hari kalender saat ini dalam zona waktu `Asia/Jakarta`, diurutkan secara kronologis berdasarkan `jam_mulai`.

### 2.4 Reminder Management (`F3`, `F5`)

1. **Sumber Pengingat (`REQUIREMENT`):**
    - **Berdasarkan Deadline Tugas:** Dibuat otomatis menjelang `tasks.tenggat` atau sesuai waktu yang dipilih pengguna.
    - **Berdasarkan Jadwal Kegiatan:** Dibuat untuk mengingatkan kuliah/kegiatan di `schedules`.
    - **Terjadwal Sistem:** Pengingat rencana harian pagi (`F7`) dan ringkasan mingguan Minggu malam (`F11`).
2. **Siklus Status Pengingat (`REQUIREMENT` & `RECOMMENDATION`):**
    - `pending`: Pengingat terjadwal dan menunggu `kirim_pada <= now()` (UTC).
    - `processing`: Pengingat telah diambil oleh Laravel Scheduler dan sedang berada di antrean Queue.
    - `sent` / `terkirim`: Pesan berhasil dikirim ke gateway WhatsApp dan `dikirim_pada` tercatat.
    - `failed` / `gagal`: Pengiriman gagal setelah seluruh percobaan ulang (_retry_) habis; wajib ditampilkan sebagai indikator peringatan di Beranda `"Hari ini"`.
3. **Target Ketepatan Waktu (`REQUIREMENT`):**
    - Scheduler berjalan setiap 1 menit sehingga pengingat terkirim maksimal dalam **2 menit** dari jadwal `kirim_pada` (selama berada di luar jam tenang).
4. **Aturan Jam Tenang / _Quiet Hours_ (`REQUIREMENT` — `F5`):**
    - Tidak boleh ada pesan pengingat WhatsApp yang dikirimkan apabila waktu lokal pengguna (`Asia/Jakarta`) berada di antara `jam_tenang_mulai` dan `jam_tenang_selesai`.
    - Pengingat yang jatuh di dalam jam tenang otomatis **digeser ke waktu pertama yang diizinkan** (yaitu tepat pada `jam_tenang_selesai` berikutnya).
5. **Aturan Tunda / _Snooze_ 30 Menit (`REQUIREMENT` — `F4`):**
    - Jika pengguna membalas `"tunda"` melalui WhatsApp, waktu `kirim_pada` pengingat digeser sebesar **+30 menit** dari waktu saat itu dan statusnya dikembalikan menjadi `pending` (tetap tunduk pada aturan jam tenang apabila +30 menit tersebut masuk ke jam tenang).
6. **Aturan Retry & Pencegahan Duplikasi (`REQUIREMENT` & `RECOMMENDATION`):**
    - Jika gateway WhatsApp gagal sesaat (timeout / HTTP 5xx), Queue Job mencoba ulang pengiriman beberapa kali (mis. 3 kali dengan backoff).
    - Penguncian transisi status `pending -> processing` dilakukan secara atomik di dalam transaksi database agar Scheduler menit berikutnya tidak mengirim pesan yang sama dua kali.

### 2.5 Interaksi & Webhook WhatsApp (`F3`, `F4`, `F9`)

1. **Validasi Keamanan & Nomor Pengirim (`REQUIREMENT`):**
    - Setiap request ke endpoint webhook WhatsApp wajib menyertakan token rahasia yang valid.
    - Nomor pengirim pada payload webhook wajib dicocokkan dengan `users.nomor_wa` milik pengguna tunggal.
    - Pesan dari nomor lain **wajib ditolak** dan tidak boleh memicu perubahan data ataupun pemanggilan AI.
2. **Perintah Satu Kata (`REQUIREMENT` — `F4`):**
    - Pencocokan perintah dilakukan secara _case-insensitive_ (mengabaikan huruf besar/kecil dan spasi di awal/akhir):
        - **`"selesai"`:** Mencari pengingat tugas terakhir yang baru dikirim kepada pengguna, mengubah status tugas (`tasks.status`) menjadi selesai, dan membalas pesan konfirmasi singkat ke WhatsApp.
        - **`"tunda"`:** Mencari pengingat terakhir yang baru dikirim, menggeser jadwal pengingat +30 menit, dan membalas pesan konfirmasi singkat ke WhatsApp.
3. **Pesan Teks Bebas (`REQUIREMENT` — `F9`):**
    - Jika isi balasan WhatsApp bukan kata kunci `"selesai"` atau `"tunda"`, pesan tersebut diperlakukan sebagai **obrolan asisten**, disimpan ke tabel `messages`, diteruskan ke `AiService` beserta konteks tugas & jadwal pengguna, lalu jawaban AI dikirim balik ke WhatsApp pengguna.

### 2.6 Layanan AI, Urutan Fallback & Kuota (`F7`, `F8`, `F9`, `F10`)

1. **Urutan Fallback Berjenjang (`REQUIREMENT` — `F8`):**
    - `AiService` wajib memanggil penyedia secara berurutan:
        1. **Gemini** (Penyedia Utama).
        2. **Groq** (Cadangan Pertama — dipanggil jika Gemini mengembalikan HTTP `429`, error, atau timeout).
        3. **OpenRouter dengan model berlabel `:free`** (Cadangan Kedua — dipanggil jika Groq juga gagal).
        4. **Templat Tetap Tanpa AI** (Cadangan Terakhir — jika ketiga penyedia AI gagal atau kuota habis).
2. **Prinsip Pengingat Tidak Boleh Hilang (`REQUIREMENT`):**
    - Kegagalan seluruh layanan AI **dilarang menggagalkan pengiriman pengingat WhatsApp**. Sistem wajib jatuh ke templat teks statis (target keandalan pengiriman saat AI mati: **100%**).
3. **Pemantauan & Pembatasan Kuota Gratis (`REQUIREMENT` — `F8`):**
    - Setiap permintaan ke provider AI dicatat pada tabel `ai_usage` (`penyedia`, `model`, `jumlah_permintaan`, `tanggal`).
    - Sebelum memanggil suatu provider, `AiService` memeriksa apakah `jumlah_permintaan` hari tersebut sudah mencapai batas aman free tier; jika sudah mencapai batas, langsung beralih ke provider cadangan berikutnya tanpa memanggil API yang kuotanya habis.
4. **Keluaran JSON Terstruktur (`REQUIREMENT` — `F7`, `F10`):**
    - Untuk fitur **Perencana Harian (`F7`)** dan **Pemecah Tugas (`F10`)**, prompt `AiService` wajib meminta keluaran dalam format **JSON** terstruktur agar dapat divalidasi dan disimpan langsung ke database (`tasks` / rencana harian).

### 2.7 Habit Tracking & Ringkasan Mingguan (`F11`)

1. **Pencatatan Kebiasaan & Streak (`REQUIREMENT`):**
    - Pengguna mencatat penyelesaian kebiasaan per tanggal lokal (`Asia/Jakarta`) di `habit_logs`.
    - _Streak_ dihitung dari jumlah hari berturut-turut (`selesai = true`) hingga hari ini/kemarin.
2. **Ringkasan Mingguan (`REQUIREMENT`):**
    - Setiap **Minggu malam**, sistem merangkum jumlah tugas yang diselesaikan dalam 7 hari terakhir serta capaian _streak_ kebiasaan, lalu mengirimkannya ke WhatsApp pengguna.

### 2.8 Privasi & Tata Kelola Data (`REQUIREMENT`)

1. **Data yang Diizinkan Dikirim ke AI (_Whitelist_):**
    - Hanya data yang diperlukan untuk penjadwalan dan asistensi tugas: `judul` tugas, `deskripsi` tugas yang relevan, `prioritas`, `tenggat`, serta `schedules` (`judul`, `hari`, `jam_mulai`, `jam_selesai`).
2. **Data yang Dilarang Dikirim ke AI (_Blacklist_):**
    - **Catatan harian pribadi, catatan mood, dan nomor WhatsApp (`nomor_wa`) dilarang keras dikirim ke provider AI mana pun**, karena permintaan pada free tier Google AI Studio (Gemini) dapat digunakan Google untuk meningkatkan produknya ([referensi Blueprint](https://benchlm.ai/free-tier/google-ai-studio)).
3. **Hak Ekspor & Hapus Data Pengguna (`REQUIREMENT`):**
    - Pengguna harus dapat mengekspor seluruh datanya sendiri dan menghapus akun beserta seluruh data terkaitnya secara mandiri. (Fitur hapus akun sudah tersedia di `ProfileController@destroy`; fitur ekspor data berstatus `Planned`).

---

## 3. TODO & Pertanyaan Terbuka (`UNKNOWN`)

- [ ] Menentukan jam tenang bawaan (`jam_tenang_mulai` & `jam_tenang_selesai`) saat pengguna pertama kali mengatur profil.
- [ ] Menentukan batas angka maksimal `jumlah_permintaan` harian per provider di `ai_usage` (bergantung pada model spesifik Gemini, Groq, dan OpenRouter `:free` yang dipilih).
- [ ] Menentukan aturan pemilihan tugas target apabila terdapat lebih dari satu pengingat tugas yang baru dikirim ketika pengguna membalas kata `"selesai"` atau `"tunda"` tanpa menyebut ID tugas.
