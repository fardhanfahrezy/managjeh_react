# API & External Service Integration — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan seluruh route aplikasi yang aktif (`Implemented & Verified`) di [routes/web.php](../routes/web.php) dan [routes/settings.php](../routes/settings.php), serta kontrak integrasi untuk **WhatsApp Gateway (`D-02`, `D-14`)** dan **AI Planner Multi-Provider (`D-03`, `D-04`, `D-12`, `D-13`)**.

---

## 1. Daftar Route Aplikasi Aktual (`php artisan route:list --except-vendor`)

Seluruh route di bawah ini telah diimplementasikan dan terverifikasi:

| Method       | URI                                 | Route Name                    | Controller / Action                                 | Middleware                                        | Tujuan                                                                   |
| :----------- | :---------------------------------- | :---------------------------- | :-------------------------------------------------- | :------------------------------------------------ | :----------------------------------------------------------------------- |
| `GET\|HEAD`  | `/`                                 | `home`                        | `Inertia\Controller` (`welcome`)                    | `web`                                             | Halaman sambutan publik                                                  |
| `GET\|HEAD`  | `/dashboard`                        | `dashboard`                   | `DashboardController@__invoke`                      | `web`, `auth`, `verified`                         | Halaman Dashboard terpadu (8 bagian ringkasan)                           |
| `GET\|HEAD`  | `/projects`                         | `projects.index`              | `ProjectController@index`                           | `web`, `auth`, `verified`                         | Daftar proyek milik pengguna beserta progres                             |
| `POST`       | `/projects`                         | `projects.store`              | `ProjectController@store`                           | `web`, `auth`, `verified`                         | Membuat proyek baru (`StoreProjectRequest`)                              |
| `GET\|HEAD`  | `/projects/{project}`               | `projects.show`               | `ProjectController@show`                            | `web`, `auth`, `verified` (`ProjectPolicy`)       | Detail proyek dan daftar tugas terkait                                   |
| `PUT\|PATCH` | `/projects/{project}`               | `projects.update`             | `ProjectController@update`                          | `web`, `auth`, `verified` (`ProjectPolicy`)       | Memperbarui proyek (`UpdateProjectRequest`)                              |
| `DELETE`     | `/projects/{project}`               | `projects.destroy`            | `ProjectController@destroy`                         | `web`, `auth`, `verified` (`ProjectPolicy`)       | Soft delete proyek (`D-11`)                                              |
| `GET\|HEAD`  | `/tasks`                            | `tasks.index`                 | `TaskController@index`                              | `web`, `auth`, `verified`                         | Daftar tugas & subtugas dengan filter status/prioritas/proyek            |
| `POST`       | `/tasks`                            | `tasks.store`                 | `TaskController@store`                              | `web`, `auth`, `verified`                         | Membuat tugas/subtugas & sinkronisasi pengingat (`StoreTaskRequest`)     |
| `PUT\|PATCH` | `/tasks/{task}`                     | `tasks.update`                | `TaskController@update`                             | `web`, `auth`, `verified` (`TaskPolicy`)          | Memperbarui tugas/status & sinkronisasi pengingat (`UpdateTaskRequest`)  |
| `DELETE`     | `/tasks/{task}`                     | `tasks.destroy`               | `TaskController@destroy`                            | `web`, `auth`, `verified` (`TaskPolicy`)          | Soft delete tugas & batalkan pengingat `pending` (`D-11`)                |
| `GET\|HEAD`  | `/calendar`                         | `calendar.index`              | `CalendarEventController@index`                     | `web`, `auth`, `verified`                         | Tampilan kalender (`day`/`week`/`month`) + ekspansi agenda berulang      |
| `POST`       | `/calendar-events`                  | `calendar-events.store`       | `CalendarEventController@store`                     | `web`, `auth`, `verified`                         | Membuat agenda baru (`StoreCalendarEventRequest`)                        |
| `PUT\|PATCH` | `/calendar-events/{calendar_event}` | `calendar-events.update`      | `CalendarEventController@update`                    | `web`, `auth`, `verified` (`CalendarEventPolicy`) | Memperbarui 1 kejadian (`single`) atau seluruh rangkaian (`series`)      |
| `DELETE`     | `/calendar-events/{calendar_event}` | `calendar-events.destroy`     | `CalendarEventController@destroy`                   | `web`, `auth`, `verified` (`CalendarEventPolicy`) | Menghapus 1 kejadian (`single`) atau seluruh rangkaian (`series`)        |
| `GET\|HEAD`  | `/ai-planner`                       | `ai-planner.index`            | `AiPlannerController@index`                         | `web`, `auth`, `verified`                         | Menampilkan rencana prioritas harian AI & riwayat eksekusi               |
| `POST`       | `/ai-planner/generate`              | `ai-planner.generate`         | `AiPlannerController@generate`                      | `web`, `auth`, `verified`, `throttle:ai-planner`  | Memicu pembuatan rencana AI harian (maks. 10x/menit)                     |
| `POST`       | `/ai-planner/reminders/approve`     | `ai-planner.approve-reminder` | `AiPlannerController@approveReminder`               | `web`, `auth`, `verified`                         | Menyetujui rekomendasi pengingat AI menjadi entri `reminders` (`D-12`)   |
| `ANY`        | `/settings`                         | —                             | Redirect ke `/settings/profile`                     | `web`, `auth`                                     | Redirect halaman pengaturan utama                                        |
| `GET\|HEAD`  | `/settings/profile`                 | `profile.edit`                | `Settings\ProfileController@edit`                   | `web`, `auth`                                     | Halaman pengaturan profil, timezone, WhatsApp, quiet hours, & privasi    |
| `PATCH`      | `/settings/profile`                 | `profile.update`              | `Settings\ProfileController@update`                 | `web`, `auth`                                     | Memperbarui profil, timezone, nomor WA, & jam tenang                     |
| `GET\|HEAD`  | `/settings/profile/export`          | `profile.export`              | `Settings\ProfileController@export`                 | `web`, `auth`, `verified`, `throttle:data-export` | Mengunduh arsip JSON data pribadi pengguna (maks. 5x/menit, `D-15`)      |
| `DELETE`     | `/settings/profile`                 | `profile.destroy`             | `Settings\ProfileController@destroy`                | `web`, `auth`, `verified`                         | Menghapus akun pengguna dengan konfirmasi password                       |
| `POST`       | `/settings/whatsapp/send-otp`       | `whatsapp.send-otp`           | `Settings\WhatsAppVerificationController@sendOtp`   | `web`, `auth`, `throttle:whatsapp-otp`            | Mengirim OTP 6 digit ke nomor WhatsApp pengguna (maks. 5x/menit)         |
| `POST`       | `/settings/whatsapp/verify-otp`     | `whatsapp.verify-otp`         | `Settings\WhatsAppVerificationController@verifyOtp` | `web`, `auth`, `throttle:10,1`                    | Memverifikasi kode OTP WhatsApp (`D-14`)                                 |
| `GET\|HEAD`  | `/settings/security`                | `security.edit`               | `Settings\SecurityController@edit`                  | `web`, `auth`, `verified`, `RequirePassword`      | Pengaturan password, 2FA TOTP, dan WebAuthn Passkeys                     |
| `PUT`        | `/settings/password`                | `user-password.update`        | `Settings\SecurityController@update`                | `web`, `auth`, `verified`, `throttle:6,1`         | Mengubah password pengguna                                               |
| `GET\|HEAD`  | `/settings/appearance`              | `appearance.edit`             | `Inertia\Controller` (`settings/appearance`)        | `web`, `auth`, `verified`                         | Pengaturan tema tampilan (`light`, `dark`, `system`)                     |
| `GET\|HEAD`  | `/.well-known/passkey-endpoints`    | `well-known.passkeys`         | Closure (`routes/settings.php`)                     | `web`                                             | Endpoint discovery standar untuk pengelola Passkey                       |
| `GET\|HEAD`  | `/offline-shell`                    | `offline-shell`               | `Inertia\Controller` (`offline-shell`)              | `web`                                             | App shell publik non-sensitif (tanpa `auth.user`) untuk fallback SW      |
| `POST`       | `/sync/push`                        | `sync.push`                   | `SyncController@push`                               | `web`, `auth`, `verified`, `throttle:sync`        | Batch push maks. 50 operasi Outbox (idempoten + OCC + field-level merge) |
| `GET\|HEAD`  | `/sync/pull`                        | `sync.pull`                   | `SyncController@pull`                               | `web`, `auth`, `verified`, `throttle:sync`        | Tarik perubahan inkremental (`since`, `limit`) + tombstones + snapshot   |
| `GET\|HEAD`  | `/sync/status`                      | `sync.status`                 | `SyncController@status`                             | `web`, `auth`, `verified`, `throttle:sync`        | Verifikasi sesi & pembaruan offline lease 7 hari (`D-21`)                |

> **Route Autentikasi Fortify & Passkeys (Vendor):** `/login`, `/logout`, `/forgot-password`, `/reset-password/{token}`, `/email/verify/*`, `/user/confirm-password`, `/two-factor-challenge`, `/user/two-factor-*`, dan `/passkeys/*`. Route `/register` **dinonaktifkan** sesuai `D-01`.

---

## 2. Kontrak Integrasi WhatsApp Gateway (`D-02`, `D-05`, `D-06`, `D-14`)

### 2.1 Interface & DTO (`app/Contracts/WhatsAppGatewayInterface.php`)

```php
<?php

namespace App\Contracts;

use App\Services\WhatsApp\WhatsAppSendResult;

interface WhatsAppGatewayInterface
{
    public function sendMessage(string $phoneNumber, string $message, string $idempotencyKey): WhatsAppSendResult;

    public function sendVerificationOtp(string $phoneNumber, string $otpCode): WhatsAppSendResult;
}
```

### 2.2 Driver yang Tersedia (`app/Services/WhatsApp/`)

Diikat di [AppServiceProvider.php](../app/Providers/AppServiceProvider.php) berdasarkan `config('services.whatsapp.driver')`:

1. **`fonnte` ([FonnteWhatsAppGateway.php](../app/Services/WhatsApp/FonnteWhatsAppGateway.php)):**
    - Mengirim HTTP form POST (`asForm()`) ke `https://api.fonnte.com/send` dengan header `Authorization: <FONNTE_TOKEN>` (tanpa prefix `Bearer`), parameter `target` (nomor tanpa tanda `+`), `message`, dan `countryCode: '0'`.
    - Aktif secara otomatis apabila `WHATSAPP_DRIVER=fonnte` atau apabila `FONNTE_TOKEN` terisi di `.env`.
2. **`http` ([HttpWhatsAppGateway.php](../app/Services/WhatsApp/HttpWhatsAppGateway.php)):**
    - Mengirim HTTP JSON POST dengan Bearer token (`WHATSAPP_API_TOKEN`) dan header `Idempotency-Key` ke `WHATSAPP_API_ENDPOINT`.
3. **`null` ([NullWhatsAppGateway.php](../app/Services/WhatsApp/NullWhatsAppGateway.php)):**
    - Mengembalikan `WhatsAppSendResult::ok('null-...')` tanpa request keluar. Digunakan pada pengujian otomatis (`phpunit.xml`).

---

## 3. Kontrak Integrasi AI Planner Multi-Provider (`D-03`, `D-04`, `D-12`, `D-13`)

### 3.1 Interface & DTO (`app/Contracts/AiPlannerProviderInterface.php`)

```php
<?php

namespace App\Contracts;

use App\Exceptions\AiProviderException;
use App\Services\AiPlanner\AiPlannerContext;
use App\Services\AiPlanner\AiPlannerProviderResponse;

interface AiPlannerProviderInterface
{
    public function name(): string;

    public function model(): ?string;

    /**
     * @throws AiProviderException
     */
    public function generatePlan(AiPlannerContext $context): AiPlannerProviderResponse;
}
```

### 3.2 Urutan Provider (`app/Services/AiPlanner/`)

1. **`GeminiPlannerProvider`** (`provider = 'gemini'`): Memanggil Google Gemini REST API (`generateContent` dengan `responseMimeType: 'application/json'`).
2. **`GrokPlannerProvider`** (`provider = 'grok'`, turunan `OpenAiCompatiblePlannerProvider`): Memanggil endpoint OpenAI-compatible xAI (`https://api.x.ai/v1/chat/completions`).
3. **`OpenRouterPlannerProvider`** (`provider = 'openrouter'`, turunan `OpenAiCompatiblePlannerProvider`): Memanggil endpoint OpenRouter (`https://openrouter.ai/api/v1/chat/completions`).
4. **`FallbackPlannerProvider`** (`provider = 'fallback'`, `model = 'rule-based-v1'`): Algoritma deterministik lokal berbasis skor prioritas tugas, kedekatan deadline, dan slot kosong kalender.

### 3.3 Skema Output JSON Tervalidasi (`ai_planner_runs.result`)

```json
{
    "summary": "Ringkasan singkat rencana hari ini.",
    "priorities": [
        {
            "task_id": 12,
            "task_title": "Laporan Praktikum Metode Numerik",
            "reason": "Tenggat hari ini dengan prioritas tinggi.",
            "suggested_action": "Kerjakan pada slot kosong 09:00 - 10:30.",
            "estimated_minutes": 60
        }
    ],
    "recommended_reminders": [
        {
            "task_id": 12,
            "task_title": "Laporan Praktikum Metode Numerik",
            "remind_at_local": "2026-11-16 08:45:00",
            "reason": "15 menit sebelum sesi fokus pagi."
        }
    ],
    "warnings": [
        {
            "type": "schedule_conflict",
            "message": "Terdapat 2 agenda yang saling beririsan hari ini."
        }
    ]
}
```

---

## 4. Variabel Konfigurasi di `.env` & `config/services.php` (`D-04`)

Seluruh nama variabel di bawah ini sesuai dengan [config/services.php](../config/services.php) dan [.env.example](../.env.example):

```dotenv
# AI Planner Providers
GEMINI_API_KEY=
GEMINI_MODEL=gemini-flash-latest
GEMINI_ENDPOINT=https://generativelanguage.googleapis.com/v1beta

XAI_API_KEY=
# Alternatif alias yang juga didukung config/services.php: GROK_API_KEY
GROK_MODEL=grok-3-mini
GROK_ENDPOINT=https://api.x.ai/v1/chat/completions

OPENROUTER_API_KEY=
OPENROUTER_MODEL=google/gemini-3.8-flash
OPENROUTER_ENDPOINT=https://openrouter.ai/api/v1/chat/completions

AI_PLANNER_TIMEOUT=20

# WhatsApp Gateway
WHATSAPP_DRIVER=fonnte
FONNTE_TOKEN=
FONNTE_API_ENDPOINT=https://api.fonnte.com/send
WHATSAPP_API_ENDPOINT=
WHATSAPP_API_TOKEN=
WHATSAPP_TIMEOUT=10
```

---

## 5. Rencana Integrasi Lanjutan — Internal MCP Server & Multi-Channel Outbound (`CONFIRMED` / `Planned` — Belum Diimplementasikan)

> **Catatan Kepatuhan Dokumentasi:** Seluruh route pada Bagian 1 adalah route aktual (`FACT`). Bagian ini **tidak mendefinisikan URL endpoint HTTP fiktif** — kontrak route final akan didokumentasikan setelah implementasi MVP 2 selesai.

### 5.1 Daftar Kemampuan Tools Internal MCP Server (`D-16`, `D-18` — Desain Teknis Terkunci)

Sesuai `D-16`, **Internal MCP Server** diimplementasikan ringan tanpa dependensi baru di `app/Mcp/` (`McpToolInterface`, `McpToolRegistry`, `McpToolExecutor`) dengan kontrak JSON Schema kompatibel MCP dan dipanggil **in-process** langsung oleh AI Orchestrator memakai objek `User` dari autentikasi sesi Laravel (tanpa endpoint HTTP `/mcp` eksternal di MVP 2):

| Tool Name (`CONFIRMED`) | Tipe Operasi | Target Fase (`D-18`) | Sumber Domain Backend                                                             | Persetujuan User Wajib? | Status    |
| :---------------------- | :----------- | :------------------- | :-------------------------------------------------------------------------------- | :---------------------- | :-------- |
| `get_my_tasks`          | Read         | MVP 2                | `Task` (`user_id` scoped)                                                         | Tidak                   | `Planned` |
| `get_upcoming_tasks`    | Read         | MVP 2                | `Task` (`due_at` & aktif)                                                         | Tidak                   | `Planned` |
| `get_today_schedule`    | Read         | MVP 2                | `CalendarEvent` + `RecurrenceExpanderService`                                     | Tidak                   | `Planned` |
| `get_schedule_range`    | Read         | MVP 2                | `CalendarEvent` + `RecurrenceExpanderService` + kalkulasi slot waktu kosong       | Tidak                   | `Planned` |
| `get_my_projects`       | Read         | MVP 2                | `Project` (`user_id` scoped + perhitungan progres)                                | Tidak                   | `Planned` |
| `get_daily_overview`    | Read         | MVP 2                | `DashboardAggregatorService`                                                      | Tidak                   | `Planned` |
| `create_task`           | Write        | MVP 2                | Domain logic `Task` + `ReminderSynchronizer`                                      | **Ya (Wajib)**          | `Planned` |
| `update_task`           | Write        | MVP 2                | `TaskPolicy` + Domain logic `Task` + `ReminderSynchronizer`                       | **Ya (Wajib)**          | `Planned` |
| `create_schedule`       | Write        | MVP 2                | Domain logic `CalendarEvent` + `ScheduleConflictDetector`                         | **Ya (Wajib)**          | `Planned` |
| `update_schedule`       | Write        | MVP 2                | `CalendarEventPolicy` + Domain logic `CalendarEvent` + `ScheduleConflictDetector` | **Ya (Wajib)**          | `Planned` |
| `get_my_habits`         | Read         | MVP 3                | Modul Habits & Rutinitas/Tidur (belum ada)                                        | Tidak                   | `Planned` |
| `get_my_finances`       | Read         | MVP 3                | Modul Finance/Anggaran/Tagihan (belum ada)                                        | Tidak                   | `Planned` |

- **Batasan Keamanan Kontrak MCP (`D-16`):**
    - Skema input seluruh tools di atas **dilarang** menyertakan parameter `user_id` yang bisa diisi oleh AI; identitas pengguna wajib diinjeksi oleh lapisan autentikasi backend.
    - Write tools MVP 2 dibatasi pada 4 operasi **non-destruktif** (`create_task`, `update_task`, `create_schedule`, `update_schedule`); tidak ada tool `delete_*` maupun mutasi `Project` di MVP 2.
    - Pemanggilan write tool selalu dicegat oleh Orchestrator menjadi Proposal pending ber-TTL **24 jam** (dengan pratinjau konflik via `ScheduleConflictDetector`) dan hanya dieksekusi setelah pengguna menyetujui item terpilih.
- **Kontrak Integrasi LLM & Pengiriman AI Chat (`D-03`, `D-04`, `TODO-02` Selesai):**
    - AI Orchestrator memanfaatkan rantai multi-provider `Gemini` → `Grok` → `OpenRouter` di `config/services.php` menggunakan _native function/tool calling_ masing-masing provider (`Gemini` `functionDeclarations`, `Grok` & `OpenRouter` OpenAI-compatible `tools`).
    - Maksimal **4 putaran tool-call** per pesan, _sliding window_ **16 pesan terakhir**, batas input `2.000` karakter, rate limit `ai-chat` (`20 req/menit` per user), dan pengiriman **synchronous JSON request-response** (tanpa SSE dan tanpa antrean queue). Jika seluruh provider gagal, sistem mengembalikan pesan error ramah tanpa chat fallback tiruan.

### 5.2 Arah Ekstensi Kanal Notifikasi Outbound (`D-19`, `TODO-03` Selesai)

- **Abstraksi Multi-Channel (`F-WA-02`):** Memperkenalkan `OutboundChannelInterface` + `OutboundChannelManager` yang merutekan pengiriman berdasarkan kolom `reminders.channel`. Kanal `'whatsapp'` tetap mendelegasikan ke `WhatsAppGatewayInterface` (Fonnte, HTTP, Null) serta menambah driver adapter `MetaCloudWhatsAppGateway` (`meta_cloud`). Integrasi & UI aktif **Telegram** ditunda sampai dibutuhkan tanpa perlu mengubah `ReminderSynchronizer`.
- **Ekspansi Pengingat & Digest Deterministik (`F-WA-01`):** Memanfaatkan tabel `reminders` untuk menambahkan **Ringkasan Harian** (default `07:00` lokal), **Ringkasan Mingguan** (default `Minggu 19:00` lokal) — keduanya disusun secara **deterministik (rule-based)** tanpa memanggil LLM — serta **Persiapan Kegiatan (`event_prep`, H-15 menit)** dan **rolling sync 48 jam** harian via `RecurrenceExpanderService` untuk agenda berulang. Pengaturan opt-in & jam kirim disimpan per pengguna di `users.notification_preferences`.
