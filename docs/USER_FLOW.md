# User Flow — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan alur interaksi pengguna dan sistem yang berjalan (`Implemented & Verified`) pada **Managjeh MVP 1** berdasarkan keputusan final `D-01` s.d. `D-15`.

---

## 1. Alur Provisioning Akun & Autentikasi (`D-01`)

Karena registrasi publik dinonaktifkan (`D-01`), akun dibuat melalui CLI lalu pengguna login melalui browser:

```mermaid
flowchart TD
    Admin(["Admin / Pemilik"]) --> Provision["Jalankan CLI:\nphp artisan user:provision"]
    Provision --> CreateVerified["Simpan User terverifikasi di PostgreSQL\n(timezone='Asia/Jakarta', quiet_hours=00:00-05:00)"]
    CreateVerified --> LoginScreen["Pengguna buka /login"]
    LoginScreen --> AuthMethod{"Metode Login"}
    AuthMethod -->|"Email & Password"| CredCheck["POST /login ( + 2FA TOTP bila aktif )"]
    AuthMethod -->|"Passkey / WebAuthn"| PasskeyCheck["POST /passkeys/login"]
    CredCheck --> Dashboard["Redirect ke /dashboard"]
    PasskeyCheck --> Dashboard
```

---

## 2. Alur Pengelolaan Proyek, Tugas & Subtugas (`D-05`, `D-09`, `D-11`)

```mermaid
flowchart TD
    User(["Pengguna di /projects atau /tasks"]) --> Action{"Aksi Pengguna"}
    Action -->|"Buat/Ubah Project"| SaveProject["Validasi Store/UpdateProjectRequest\nSimpan ke tabel projects"]
    Action -->|"Buat/Ubah Task"| ValidateTask["Validasi Store/UpdateTaskRequest:\n1. Kepemilikan project_id & parent_id\n2. Kedalaman subtugas maks. 1 level (D-09)\n3. Konversi due_at dari users.timezone ke UTC"]
    ValidateTask --> SaveTask["Simpan ke tabel tasks\n& sinkronkan completed_at"]
    SaveTask --> SyncReminders["ReminderSynchronizer::syncForTask()\nJadwalkan before_24h & before_1h\n(atau batalkan jika task selesai/dibatalkan)"]
    SyncReminders --> UpdateProgress["Hitung ulang progres project:\ncompleted / non-cancelled tasks"]
```

---

## 3. Alur Kalender & Agenda Berulang (`D-08`)

```mermaid
flowchart TD
    OpenCal(["Pengguna buka /calendar"]) --> SelectView["Pilih tampilan Day / Week / Month\n(berdasarkan users.timezone)"]
    SelectView --> ExpandEvents["RecurrenceExpanderService:\n1. Ambil agenda non-berulang\n2. Ekspansi recurrence_rule (DAILY/WEEKLY/MONTHLY)\n3. Terapkan recurrence_exceptions & single-occurrence overrides\n4. Deteksi konflik jadwal (ScheduleConflictDetector)\n5. Gabungkan indikator deadline tasks (due_at)"]
    ExpandEvents --> RenderCal["Tampilkan di UI Kalender"]

    RenderCal --> EditRecurring{"Ubah / Hapus Agenda Berulang?"}
    EditRecurring -->|"Hanya kejadian ini (edit_scope='single')"| CreateOverride["Simpan override dengan parent_event_id & recurrence_id\natau tambahkan ke recurrence_exceptions"]
    EditRecurring -->|"Seluruh rangkaian (edit_scope='series')"| UpdateMaster["Perbarui / hapus baris agenda induk beserta overrides"]
```

---

## 4. Alur Verifikasi Nomor WhatsApp & Pengiriman Reminder (`D-02`, `D-05`, `D-06`, `D-14`)

```mermaid
flowchart TD
    subgraph Verification["1. Verifikasi Nomor WhatsApp (D-14)"]
        InputWA["Pengguna simpan whatsapp_number di /settings/profile"] --> ResetVerif["whatsapp_verified_at = null"]
        ResetVerif --> ReqOtp["Klik Kirim Kode OTP (throttle:whatsapp-otp 5/m)\nSimpan hash SHA-256 OTP di Cache (TTL 5m)\n& kirim via WhatsAppGatewayInterface (Fonnte)"]
        ReqOtp --> InputOtp["Pengguna input 6 digit OTP -> Validasi hash"]
        InputOtp --> SetVerified["Set whatsapp_verified_at = now()\n& aktifkan whatsapp_enabled = true"]
    end

    subgraph Delivery["2. Pengiriman Reminder Terjadwal (D-05, D-06)"]
        Cron(["Scheduler tiap 1 menit:\nreminders:dispatch-due"]) --> ClaimDue["Klaim atomik reminders:\nstatus='pending' & scheduled_at <= now() -> 'processing'"]
        ClaimDue --> DispatchJob["Dispatch SendWhatsAppMessageJob"]
        DispatchJob --> CheckConsent{"user->canReceiveWhatsAppReminders()\n& task/event masih aktif?"}
        CheckConsent -->|"Tidak"| CancelRem["Set status='cancelled'"]
        CheckConsent -->|"Ya"| CheckQuiet["Evaluasi ulang QuietHoursAdjuster\n(00:00-05:00 waktu lokal pengguna)"]
        CheckQuiet -->|"Masih dalam Quiet Hours"| Requeue["Kembalikan ke status='pending'\ndengan scheduled_at akhir jam tenang"]
        CheckQuiet -->|"Di luar Quiet Hours"| SendGateway["Kirim via WhatsAppGatewayInterface\ndengan idempotency_key"]
        SendGateway -->|"Sukses"| MarkSent["Set status='sent', sent_at=now(), provider_message_id"]
        SendGateway -->|"Gagal setelah retry"| MarkFailed["Set status='failed', failed_at=now(), failure_reason"]
    end
```

---

## 5. Alur AI Planner & Persetujuan Pengingat (`D-03`, `D-04`, `D-07`, `D-12`, `D-13`)

```mermaid
flowchart TD
    Trigger(["Pemicu: Scheduler Harian 05:30 / Klik Manual di /ai-planner (throttle:ai-planner 10/m)"]) --> BuildCtx["AiPlannerService::buildContext():\nAmbil tasks aktif, agenda hari ini, & hitung slot kosong\nBuat input_snapshot tersanitasi (D-15)"]
    BuildCtx --> TryGemini["1. Panggil GeminiPlannerProvider (jika circuit closed)"]
    TryGemini -->|"Sukses & JSON Valid"| SaveRun["Simpan ke ai_planner_runs (status='success')"]
    TryGemini -->|"Gagal / Timeout / 429"| TryGrok["2. Panggil GrokPlannerProvider (jika circuit closed)"]
    TryGrok -->|"Sukses & JSON Valid"| SaveRun
    TryGrok -->|"Gagal / Timeout / 429"| TryOpenRouter["3. Panggil OpenRouterPlannerProvider (jika circuit closed)"]
    TryOpenRouter -->|"Sukses & JSON Valid"| SaveRun
    TryOpenRouter -->|"Gagal"| RunFallback["4. Jalankan FallbackPlannerProvider\n(Aturan deterministik lokal)"]
    RunFallback --> SaveRun
    SaveRun --> RenderPlan["Tampilkan 3 prioritas, peringatan, & rekomendasi pengingat di /ai-planner & /dashboard"]
    RenderPlan --> ApproveClick["Pengguna klik 'Jadwalkan WA' pada rekomendasi pengingat\nPOST /ai-planner/reminders/approve"]
    ApproveClick --> CreateAiReminder["AiPlannerService::approveReminder():\nSesuaikan QuietHoursAdjuster & simpan ke tabel reminders\n(reminder_type='ai_recommended')"]
```

---

## 6. Alur Ekspor Data & Retensi Privasi (`D-11`, `D-15`)

```mermaid
flowchart TD
    subgraph Export["1. Ekspor Data Pribadi (JSON)"]
        UserSettings(["Pengguna di /settings/profile"]) --> ClickExport["Klik 'Unduh Data (JSON)'\nGET /settings/profile/export (throttle:data-export 5/m)"]
        ClickExport --> BuildJson["ProfileController@export:\nAmbil data milik auth()->user() yang di-whitelist\n(tanpa password, 2FA secret, OTP, atau token API)"]
        BuildJson --> DownloadFile["Browser mengunduh managjeh-export-YYYY-MM-DD.json"]
    end

    subgraph Retention["2. Pembersihan Retensi Terjadwal"]
        DailyCron(["Scheduler Harian 02:30:\napp:prune-retention-data"]) --> PurgeSoftDeleted["forceDelete() Tasks, CalendarEvents, & Projects\nyang deleted_at < 30 hari lalu (D-11)"]
        PurgeSoftDeleted --> PruneLogs["Hapus Reminders terminal (sent/failed/cancelled)\n& AiPlannerRuns yang berusia > 30 hari (D-15)"]
    end
```

---

## 7. Alur Target Lanjutan — Website AI Chat, MCP & Perluasan Outbound (`CONFIRMED` / `Planned` — `D-16` s.d. `D-19`)

> **Status Implementasi:** `Planned` (desain teknis MVP 2 terkunci; belum diimplementasikan di kode saat ini). Halaman `/ai-planner` pada Bagian 5 tetap berjalan berdampingan (`D-17`).

### 7.1 Alur A: Interaksi Pengguna dengan Website AI Chat (`/ai-chat`) & Persetujuan Aksi

```mermaid
flowchart TD
    OpenChat(["Pengguna membuka /ai-chat di Website Managjeh"]) --> SendMsg["Kirim pesan (Sync JSON POST, maks 2.000 karakter,\nthrottle:ai-chat 20/m + useRateLimitCooldown)"]
    SendMsg --> Orch["AI Orchestrator memuat sliding window 16 pesan terakhir\n+ users.ai_preferences (tanpa PII - D-15)"]
    Orch --> CallMcp["Loop native tool-calling (Gemini -> Grok -> OpenRouter,\nmaks 4 putaran) memanggil Internal MCP in-process (app/Mcp/)"]
    CallMcp --> BackendRead["Backend menyuntikkan User dari sesi auth (bukan dari AI)\n& mengeksekusi 6 Read Tools terhadap PostgreSQL"]
    BackendRead --> NeedWrite{"Apakah LLM memanggil\nWrite Tool (create/update)?"}
    NeedWrite -->|"Tidak (hanya tanya/analisis)"| ReplyOnly["Simpan ai_chat_messages & tampilkan jawaban\n(retensi 30 hari / hapus manual - D-19)"]
    NeedWrite -->|"Ya (dicegat di loop)"| Propose["Simpan ke ai_action_proposals (TTL 24 jam)\n+ pratinjau bentrok via ScheduleConflictDetector\nTampilkan Action Plan Card (checkbox per item)"]
    Propose --> UserDecision{"Keputusan Pengguna\n(sebelum 24 jam)"}
    UserDecision -->|"Tolak Semua / Kedaluwarsa"| CancelPlan["Set status rejected / expired\n(tidak ada mutasi data)"]
    UserDecision -->|"Setujui Terpilih"| ExecBackend["Backend re-validasi kepemilikan & Policy secara idempoten\nEksekusi Domain Services & perbarui audit trail"]
    ExecBackend --> Confirm["Tampilkan status hasil inline di kartu chat\n& catat di panel 'Riwayat Aksi AI'"]
```

### 7.2 Alur B: Perluasan Pengingat Outbound (WhatsApp, Rolling Sync & Kesiapan Multi-Channel)

```mermaid
flowchart TD
    Source(["Sumber Pengingat:\n1. Event/Task langsung (before_24h, before_1h, event_prep H-15m, event_start)\n2. Scheduler Rolling Sync 48 jam agenda berulang (RecurrenceExpanderService)\n3. Scheduler Digest Deterministik (Harian 07:00 & Mingguan Minggu 19:00 lokal)"]) --> Eval["Evaluasi users.notification_preferences (opt-in & jam kustom)\n& susun pesan kaya (status, saran singkat, link ke /ai-chat)"]
    Eval --> ConsentCheck{"User terverifikasi OTP (D-14)\n& di luar Quiet Hours (D-06)?"}
    ConsentCheck -->|"Tidak"| HoldOrSkip["Tunda pasca Quiet Hours atau batalkan"]
    ConsentCheck -->|"Ya"| Dispatch["Rute via OutboundChannelManager (reminders.channel):\nWhatsApp (Fonnte / Meta Cloud API) | Telegram (Planned - D-19)"]
    Dispatch --> LogStatus["Simpan status pengiriman di reminders\n& tangani retry aman dengan idempotency_key"]
    LogStatus --> DeepLink["Pengguna menerima notifikasi WA\n& diarahkan membuka Website Managjeh untuk aksi/detail"]
```
