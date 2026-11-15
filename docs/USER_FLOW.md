# User Flow — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan alur interaksi pengguna dan sistem pada **Managjeh MVP 1** berdasarkan spesifikasi teknis dan keputusan final `D-01` s.d. `D-15`.

---

## 1. Alur Provisioning Akun & Autentikasi (`D-01` — `Implemented`)

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

## 2. Alur Pengelolaan Proyek, Tugas & Subtugas (`Phase 1–2`, `D-09`, `D-11`)

```mermaid
flowchart TD
    User(["Pengguna di /projects atau /tasks"]) --> Action{"Aksi Pengguna"}
    Action -->|"Buat/Ubah Project"| SaveProject["Validasi Store/UpdateProjectRequest\nSimpan ke tabel projects"]
    Action -->|"Buat/Ubah Task"| ValidateTask["Validasi Store/UpdateTaskRequest:\n1. Kepemilikan project_id & parent_id\n2. Kedalaman subtugas maks. 1 level (D-09)\n3. Konversi due_at dari users.timezone ke UTC"]
    ValidateTask --> SaveTask["Simpan ke tabel tasks\n& sinkronkan completed_at"]
    SaveTask --> SyncReminders["Perbarui / jadwalkan ulang reminders pending terkait (Phase 5)"]
    SyncReminders --> UpdateProgress["Hitung ulang progres project:\ncompleted / non-cancelled tasks"]
```

---

## 3. Alur Kalender & Agenda Berulang (`Phase 3`, `D-08`)

```mermaid
flowchart TD
    OpenCal(["Pengguna buka /calendar"]) --> SelectView["Pilih tampilan Day / Week / Month\n(berdasarkan users.timezone)"]
    SelectView --> ExpandEvents["RecurrenceExpanderService:\n1. Ambil agenda non-berulang\n2. Ekspansi recurrence_rule secara idempotent\n3. Terapkan recurrence_exceptions & single-occurrence overrides\n4. Gabungkan deadline tasks (due_at)"]
    ExpandEvents --> RenderCal["Tampilkan di UI Kalender"]

    RenderCal --> EditRecurring{"Ubah / Batal Agenda Berulang?"}
    EditRecurring -->|"Hanya kejadian ini (single)"| CreateOverride["Simpan override dengan parent_event_id & recurrence_id\natau tambahkan ke recurrence_exceptions"]
    EditRecurring -->|"Seluruh rangkaian (series)"| UpdateMaster["Perbarui baris agenda induk"]
```

---

## 4. Alur Verifikasi Nomor WhatsApp & Pengiriman Reminder (`Phase 5`, `D-02`, `D-05`, `D-06`, `D-14`)

```mermaid
flowchart TD
    subgraph Verification["1. Verifikasi Nomor WhatsApp (D-14)"]
        InputWA["Pengguna simpan whatsapp_number di Settings"] --> ResetVerif["whatsapp_verified_at = null"]
        ResetVerif --> ReqOtp["Klik Kirim OTP -> Simpan hash OTP di Cache (TTL 5m)\n& kirim via WhatsAppGatewayInterface"]
        ReqOtp --> InputOtp["Pengguna input OTP -> Validasi hash"]
        InputOtp --> SetVerified["Set whatsapp_verified_at = now()\n& aktifkan whatsapp_enabled = true"]
    end

    subgraph Delivery["2. Pengiriman Reminder Terjadwal (D-05, D-06)"]
        Cron(["Laravel Scheduler tiap 1 menit"]) --> FetchDue["Ambil reminders: status='pending' & scheduled_at <= now()"]
        FetchDue --> CheckConsent{"user->canReceiveWhatsAppReminders()?"}
        CheckConsent -->|"Tidak"| CancelRem["Set status='cancelled'"]
        CheckConsent -->|"Ya"| CheckQuiet{"Jatuh pada Quiet Hours\n00:00-05:00 lokal (D-06)?"}
        CheckQuiet -->|"Ya"| ShiftQuiet["Geser scheduled_at ke 05:00 waktu lokal pengguna\n(atau batalkan jika sudah lewat deadline)"]
        CheckQuiet -->|"Tidak"| DispatchJob["Set status='processing' & Dispatch SendWhatsAppMessageJob"]
        DispatchJob --> SendGateway["Kirim via WhatsAppGatewayInterface dengan idempotency_key"]
        SendGateway -->|"Sukses"| MarkSent["Set status='sent', sent_at=now()"]
        SendGateway -->|"Gagal setelah retry"| MarkFailed["Set status='failed', failed_at=now()"]
    end
```

---

## 5. Alur AI Planner (`Phase 6`, `D-03`, `D-04`, `D-07`, `D-12`, `D-13`)

```mermaid
flowchart TD
    Trigger(["Pemicu: Scheduler Otomatis / Perubahan Data / Klik Manual di Web (D-12)"]) --> BuildCtx["Ambil tasks aktif, agenda kalender hari ini,\n& hitung slot kosong di backend (D-07)\n(Sanitasi data sensitif - D-15)"]
    BuildCtx --> TryGemini["1. Panggil GeminiPlannerProvider"]
    TryGemini -->|"Sukses & JSON Valid"| SaveRun["Simpan ke ai_planner_runs (status='success')"]
    TryGemini -->|"Gagal / Timeout / 429"| TryGrok["2. Panggil GrokPlannerProvider"]
    TryGrok -->|"Sukses & JSON Valid"| SaveRun
    TryGrok -->|"Gagal / Timeout / 429"| TryOpenRouter["3. Panggil OpenRouterPlannerProvider"]
    TryOpenRouter -->|"Sukses & JSON Valid"| SaveRun
    TryOpenRouter -->|"Gagal"| RunFallback["4. Jalankan FallbackPlannerProvider\n(Aturan deterministik lokal)"]
    RunFallback --> SaveRun
    SaveRun --> RenderPlan["Tampilkan maksimal 3 prioritas & peringatan di AI Planner & Dashboard\n(AI tidak mengubah data tanpa persetujuan pengguna)"]
```
