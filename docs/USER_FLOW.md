# User Flow — Managjeh (Asisten AI Mahasiswa)

Dokumen ini mendokumentasikan alur interaksi pengguna dan sistem pada **Managjeh**, dengan membedakan antara **Alur Aktual yang Sudah Diimplementasikan (`FACT`)** dan **Alur Target Asisten AI Mahasiswa (`REQUIREMENT` / `PLANNED`)**.

---

## 1. Daftar Aktor

- **Mahasiswa / Pengguna Tunggal (`REQUIREMENT` & `FACT`):** Pemilik produk yang berinteraksi melalui web browser (React + Inertia) dan aplikasi **WhatsApp** di ponsel/laptop.
- **Laravel Scheduler & Queue Worker (`REQUIREMENT`):** Aktor sistem latar belakang yang memicu pengingat, rencana harian pagi, dan ringkasan mingguan.
- **WhatsApp Gateway (`REQUIREMENT`):** Layanan perantara pengiriman pesan keluar dan penerusan balasan pengguna ke webhook Laravel.
- **AI Providers (`REQUIREMENT`):** Gemini (utama), Groq (fallback 1), dan OpenRouter `:free` (fallback 2).

---

## 2. Alur 1: Login, 2FA, Passkey, Verifikasi Email & Pengaturan Akun (`FACT` — Implemented)

Alur autentikasi dan pengaturan akun berikut **sudah diimplementasikan penuh** menggunakan Laravel Fortify `1.42.0` dan `@laravel/passkeys`:

```mermaid
flowchart TD
    Start(["Pengguna buka /login"]) --> Method{"Pilih metode login"}
    Method -->|"Email & Password"| PostLogin["POST /login (throttle:login 5/mnt)"]
    Method -->|"Sign in with a passkey"| GetOpts["GET /passkeys/login/options"]
    GetOpts --> WebAuthn["Browser navigator.credentials.get()"]
    WebAuthn --> PostPasskey["POST /passkeys/login (throttle:passkeys 10/mnt)"]
    PostPasskey --> CheckVerified{"Email terverifikasi?"}

    PostLogin --> CredCheck{"Kredensial valid?"}
    CredCheck -->|"Tidak"| LoginErr["Error validasi kembali ke /login"]
    CredCheck -->|"Ya, 2FA nonaktif"| CheckVerified
    CredCheck -->|"Ya, 2FA aktif"| TwoFA["Redirect ke /two-factor-challenge"]

    TwoFA --> PostTwoFA["POST /two-factor-challenge (OTP / Recovery Code)"]
    PostTwoFA -->|"Tidak valid"| TwoFAErr["Error validasi di /two-factor-challenge"]
    PostTwoFA -->|"Valid"| CheckVerified

    CheckVerified -->|"Tidak"| VerifyPage["Redirect ke /email/verify"]
    CheckVerified -->|"Ya"| Dashboard["Redirect ke /dashboard (Beranda 'Hari ini')"]
```

- **Registrasi & Verifikasi Email (`FACT`):** `POST /register` -> `CreateNewUser` -> kirim email verifikasi -> klik signed URL `GET /email/verify/{id}/{hash}` -> redirect `/dashboard`.
- **Reset Password (`FACT`):** `POST /forgot-password` -> link email -> `POST /reset-password` (`ResetUserPassword`).
- **Pengaturan Akun (`FACT`):**
    - `/settings/profile`: ubah nama/email dan hapus akun (`ProfileController`). _(Pada Minggu 1 akan diperluas dengan input `nomor_wa`, `zona_waktu`, `jam_tenang_mulai`, `jam_tenang_selesai`)._
    - `/settings/security`: ubah password, kelola TOTP 2FA, dan kelola Passkeys (`SecurityController`, dilindungi `RequirePassword`).
    - `/settings/appearance`: ubah tema `light` / `dark` / `system`.

---

## 3. Alur 2, 3 & 5: Membuat Tugas, Mengatur Deadline & Membuat Pengingat (`REQUIREMENT` — `F1`, `F3`)

> **Status:** `Not Implemented` (`Planned` — Minggu 1 & Minggu 3).

```mermaid
flowchart TD
    OpenTasks(["Pengguna buka halaman Tugas / Beranda 'Hari ini'"]) --> FillTask["Isi form tugas:\njudul, deskripsi, prioritas, parent_id (opsional)"]
    FillTask --> HasDeadline{"Apakah mengatur\ntenggat (deadline)?"}
    HasDeadline -->|"Tidak"| SaveTaskOnly["Konversi input & simpan ke tabel tasks\n(status = pending)"]
    HasDeadline -->|"Ya (Waktu lokal Asia/Jakarta)"| ConvertUTC["Konversi tenggat dari Asia/Jakarta ke UTC\n& simpan ke tasks.tenggat"]
    ConvertUTC --> AutoReminder["Buat entri pengingat otomatis (atau manual)\ndi tabel reminders (tipe='task', status='pending', kirim_pada UTC)"]
    SaveTaskOnly --> RenderUI["Redirect / perbarui daftar tugas & Beranda 'Hari ini'"]
    AutoReminder --> RenderUI
```

1. **Membuat Tugas (`F1`):** Pengguna mengisi `judul`, `prioritas` (`rendah`/`sedang`/`tinggi`), dan opsional `deskripsi` atau `parent_id` (jika subtugas).
2. **Mengatur Deadline (`F1`):** Pengguna memilih tanggal dan jam tenggat dalam waktu lokal `Asia/Jakarta`; backend mengonversinya ke **UTC** sebelum menyimpan ke `tasks.tenggat`.
3. **Membuat Pengingat (`F3`):** Sistem membuat baris di tabel `reminders` secara otomatis berdasarkan `tenggat` tugas (atau pengguna menambahkan waktu pengingat kustom) dengan `status = 'pending'`.

---

## 4. Alur 4: Membuat Jadwal Mingguan Berulang (`REQUIREMENT` — `F2`)

> **Status:** `Not Implemented` (`Planned` — Minggu 1).

```mermaid
flowchart LR
    OpenSched(["Pengguna buka halaman Jadwal"]) --> InputSched["Input: judul, hari (Senin-Minggu),\njam_mulai, jam_selesai, berulang=true"]
    InputSched --> ValidateSched{"Validasi jam_selesai > jam_mulai"}
    ValidateSched -->|"Valid"| SaveSched["Simpan ke tabel schedules"]
    SaveSched --> ShowToday["Tampil di kalender mingguan &\notomatis muncul di Beranda 'Hari ini' pada hari yang sesuai"]
```

---

## 5. Alur 6 & 9: Pengiriman Pengingat WhatsApp & Evaluasi Jam Tenang (_Quiet Hours_) (`REQUIREMENT` — `F3`, `F5`, `F8`)

> **Status:** `Not Implemented` (`Planned` — Minggu 2 & Minggu 3).

```mermaid
flowchart TD
    Cron(["Laravel Scheduler berjalan tiap 1 menit"]) --> QueryDue["Cari reminders: status='pending'\nDAN kirim_pada <= now() (UTC)"]
    QueryDue --> CheckQuiet{"Apakah waktu lokal pengguna\n(Asia/Jakarta) sedang dalam\nJam Tenang (F5)?"}
    CheckQuiet -->|"Ya (Dalam Jam Tenang)"| ShiftTime["Geser kirim_pada ke jam_tenang_selesai\n(tetap status='pending')"]
    CheckQuiet -->|"Tidak (Di Luar Jam Tenang)"| LockRow["Ubah status='processing'\n& Dispatch Job ke Laravel Queue"]

    LockRow --> ComposeMsg{"Butuh konteks AI\natau pengingat rutin?"}
    ComposeMsg -->|"Rutin / Sederhana"| UseTemplate["Gunakan templat teks tetap"]
    ComposeMsg -->|"Butuh Konteks"| CallAI["Panggil AiService\n(Gemini -> Groq -> OpenRouter -> Templat Tetap)"]

    UseTemplate --> SendWA["Kirim pesan ke nomor_wa via WhatsApp Gateway"]
    CallAI --> SendWA

    SendWA --> WaResult{"Berhasil terkirim?"}
    WaResult -->|"Ya"| MarkSent["Update reminders:\nstatus='sent' (terkirim), dikirim_pada=now()"]
    WaResult -->|"Gagal"| RetryCheck{"Masih ada kuota retry?"}
    RetryCheck -->|"Ya"| RetryJob["Queue Retry dengan Backoff"]
    RetryCheck -->|"Habis"| MarkFailed["Update reminders: status='failed'\n& tampilkan peringatan di Beranda 'Hari ini'"]
```

---

## 6. Alur 7 & 8: Membalas `"selesai"` dan `"tunda"` via Webhook WhatsApp (`REQUIREMENT` — `F4`)

> **Status:** `Not Implemented` (`Planned` — Minggu 2).

```mermaid
flowchart TD
    UserReply(["Pengguna membalas pesan di WhatsApp"]) --> GatewayHook["WhatsApp Gateway memanggil\nPOST Webhook Laravel"]
    GatewayHook --> VerifyToken{"Token rahasia webhook valid?"}
    VerifyToken -->|"Tidak"| Reject401["Tolak Request (401/403)"]
    VerifyToken -->|"Ya"| VerifyPhone{"Nomor pengirim ==\nusers.nomor_wa?"}
    VerifyPhone -->|"Tidak"| Reject403["Tolak & abaikan nomor asing (403)"]
    VerifyPhone -->|"Ya"| ParseText{"Periksa isi pesan\n(case-insensitive)"}

    ParseText -->|"Kata 'selesai'"| MarkTaskDone["Ubah status tugas terkait menjadi 'done'/'selesai'\n& batalkan pengingat pending terkait"]
    MarkTaskDone --> ReplyConfirmDone["Kirim balasan konfirmasi singkat ke WhatsApp"]

    ParseText -->|"Kata 'tunda'"| SnoozeReminder["Geser kirim_pada pengingat +30 menit\n(evaluasi jam tenang bila perlu) & set status='pending'"]
    SnoozeReminder --> ReplyConfirmSnooze["Kirim balasan konfirmasi tunda 30 menit ke WhatsApp"]

    ParseText -->|"Teks bebas lainnya"| ForwardChat["Teruskan ke Alur 11 (AI Chat - F9)"]
```

---

## 7. Alur 10: AI Daily Planner / Perencana Harian (`REQUIREMENT` — `F7`, `F8`)

> **Status:** `Not Implemented` (`Planned` — Minggu 4).

```mermaid
flowchart TD
    MorningTrigger(["Scheduler Pagi Hari (setelah Jam Tenang berakhir)\natau pengguna klik 'Susun Rencana Hari Ini' di Web"]) --> GatherContext["Ambil jadwal hari ini (schedules)\n& tugas belum selesai (tasks)\n(Tanpa catatan sensitif / nomor WA)"]
    GatherContext --> CallAiPlan["Panggil AiService (minta output JSON terstruktur):\nGemini -> Groq -> OpenRouter (:free) -> Fallback Statis"]
    CallAiPlan --> SavePlan["Simpan rencana harian & tampilkan di Beranda 'Hari ini'"]
    SavePlan --> QueueMorningWA["Kirim ringkasan rencana hari ini ke WhatsApp pengguna"]
```

---

## 8. Alur 11: AI Chat / Obrolan Asisten (`REQUIREMENT` — `F9`, `F8`)

> **Status:** `Not Implemented` (`Planned` — Minggu 5).

```mermaid
flowchart TD
    ChatInput(["Pengguna mengirim pesan teks bebas\n(lewat WhatsApp atau halaman Obrolan Web)"]) --> SaveIncoming["Simpan pesan masuk ke tabel messages\n(arah='incoming', dibuat_pada=now())"]
    SaveIncoming --> BuildCtx["Siapkan konteks aman:\ntugas aktif & jadwal relevan pengguna"]
    BuildCtx --> CallAiChat["Panggil AiService:\nGemini -> Groq -> OpenRouter (:free) -> Balasan Fallback"]
    CallAiChat --> SaveOutgoing["Simpan balasan asisten ke tabel messages\n(arah='outgoing', dibuat_pada=now())"]
    SaveOutgoing --> DeliverReply["Tampilkan di UI Web / kirim balasan ke WhatsApp"]
```

---

## 9. Alur 12: AI Task Breakdown / Pemecah Tugas (`REQUIREMENT` — `F10`, `F8`)

> **Status:** `Not Implemented` (`Planned` — Minggu 5).

```mermaid
flowchart TD
    SelectTask(["Pengguna memilih tugas besar di halaman Tugas\n& klik 'Pecah Tugas (AI)'"]) --> SendBreakdownPrompt["Kirim judul, deskripsi & tenggat tugas induk ke AiService\n(minta format JSON daftar subtugas + target tanggal)"]
    SendBreakdownPrompt --> ValidateJson{"JSON subtugas valid?"}
    ValidateJson -->|"Ya"| InsertSubtasks["Simpan setiap subtugas ke tabel tasks\ndengan parent_id = ID tugas induk"]
    ValidateJson -->|"Gagal / AI Mati"| ShowErrorToast["Tampilkan pesan gagal & izinkan tambah subtugas manual"]
    InsertSubtasks --> RefreshTaskUI["Tampilkan daftar subtugas di bawah tugas induk"]
```

---

## 10. Alur 13 & 14: Habit Tracking & Weekly Summary (`REQUIREMENT` — `F11`)

> **Status:** `Not Implemented` (`Planned` — Minggu 6).

```mermaid
flowchart TD
    subgraph DailyHabit["Alur 13: Pelacak Kebiasaan Harian"]
        OpenHabit(["Pengguna buka halaman Kebiasaan / Beranda"]) --> ToggleHabit["Centang kebiasaan selesai untuk tanggal hari ini (Asia/Jakarta)"]
        ToggleHabit --> UpsertLog["Simpan ke habit_logs (habit_id, tanggal, selesai=true)"]
        UpsertLog --> CalcStreak["Hitung ulang streak hari berturut-turut"]
    end

    subgraph SundaySummary["Alur 14: Ringkasan Mingguan (Minggu Malam)"]
        SundayCron(["Scheduler Minggu Malam"]) --> AggregateData["Hitung total tugas selesai 7 hari terakhir\n& streak aktif di habit_logs"]
        AggregateData --> ComposeSummary["Susun pesan ringkasan mingguan\n(via AiService atau templat tetap)"]
        ComposeSummary --> SendSummaryWA["Kirim ringkasan mingguan ke WhatsApp pengguna"]
    end
```

---

## 11. TODO

- [ ] Menentukan format pesan konfirmasi standar untuk balasan `"selesai"` dan `"tunda"`.
- [ ] Menentukan jam eksekusi default untuk pengiriman Rencana Harian Pagi (`F7`) dan Ringkasan Mingguan Minggu Malam (`F11`).
