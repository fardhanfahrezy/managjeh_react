# API & External Service Integration — Managjeh (Personal OS Mahasiswa — MVP 1)

Dokumen ini mendokumentasikan route internal Inertia serta spesifikasi kontrak integrasi eksternal untuk **WhatsApp Gateway (`D-02`, `D-14`)** dan **AI Planner Multi-Provider (`D-03`, `D-04`, `D-12`, `D-13`)**.

---

## 1. Route Internal Aktual & Target (`routes/web.php` & `routes/settings.php`)

| Kelompok Route             | Method & URI                                                                                                                      | Middleware                                              | Status Saat Ini (`FACT`)                                                                                                                            |
| :------------------------- | :-------------------------------------------------------------------------------------------------------------------------------- | :------------------------------------------------------ | :-------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Auth (Fortify)**         | `/login`, `/logout`, `/forgot-password`, `/reset-password/*`, `/email/verify*`, `/two-factor-challenge`, `/passkeys/*`            | `web`, `guest`/`auth`, `throttle:*`                     | `Implemented` (Registrasi publik `/register` **dinonaktifkan** sesuai `D-01`)                                                                       |
| **Settings**               | `GET/PATCH/DELETE /settings/profile`, `GET /settings/security`, `PUT /settings/password`, `GET /settings/appearance`              | `web`, `auth`, `verified`                               | `Implemented` (`ProfileController` sudah mendukung `timezone`, `whatsapp_number`, `whatsapp_enabled`, `reminder_quiet_start`, `reminder_quiet_end`) |
| **Dashboard**              | `GET /dashboard` (`dashboard`)                                                                                                    | `web`, `auth`, `verified`                               | `Partially Implemented` (Agregasi data di Phase 4)                                                                                                  |
| **Projects**               | `GET /projects`, `POST /projects`, `GET /projects/{project}`, `PUT/PATCH /projects/{project}`, `DELETE /projects/{project}`       | `web`, `auth`, `verified`                               | `Planned` (Phase 2 — Model/Policy/FormRequest sudah ada di Phase 1)                                                                                 |
| **Tasks**                  | `GET /tasks`, `POST /tasks`, `PUT/PATCH /tasks/{task}`, `DELETE /tasks/{task}`                                                    | `web`, `auth`, `verified`                               | `Planned` (Phase 2 — Model/Policy/FormRequest sudah ada di Phase 1)                                                                                 |
| **Calendar**               | `GET /calendar`, `POST /calendar-events`, `PUT/PATCH /calendar-events/{calendarEvent}`, `DELETE /calendar-events/{calendarEvent}` | `web`, `auth`, `verified`                               | `Planned` (Phase 3)                                                                                                                                 |
| **WhatsApp OTP & Webhook** | `POST /settings/whatsapp/send-otp`, `POST /settings/whatsapp/verify-otp`, `POST /webhooks/whatsapp/status`                        | `auth`/`verified` (OTP), Signature middleware (Webhook) | `Planned` (Phase 5)                                                                                                                                 |
| **AI Planner**             | `GET /ai-planner`, `POST /ai-planner/generate`                                                                                    | `web`, `auth`, `verified`, `throttle:ai-planner`        | `Planned` (Phase 6)                                                                                                                                 |

---

## 2. Kontrak Integrasi WhatsApp Gateway (`D-02`, `D-05`, `D-06`, `D-14` — Phase 5)

### 2.1 Prinsip & Adapter Pattern (`D-02`)

- WhatsApp pada MVP 1 adalah **kanal pengiriman notifikasi satu arah** (dan pengiriman kode verifikasi OTP nomor pengguna).
- Business logic **dilarang** memanggil endpoint vendor WhatsApp secara langsung; wajib melalui `App\Contracts\WhatsAppGatewayInterface`:

```php
<?php

namespace App\Contracts;

use App\DataTransferObjects\WhatsAppSendResult;

interface WhatsAppGatewayInterface
{
    public function sendMessage(
        string $phoneNumber,
        string $message,
        string $idempotencyKey,
    ): WhatsAppSendResult;

    public function sendVerificationOtp(
        string $phoneNumber,
        string $otpCode,
    ): WhatsAppSendResult;
}
```

### 2.2 Aturan Pengiriman & Webhook Status

- **Cek Persetujuan & Verifikasi (`D-14`):** `SendWhatsAppMessageJob` wajib memeriksa `$user->canReceiveWhatsAppReminders()` sebelum memanggil `WhatsAppGatewayInterface::sendMessage()`.
- **Delivery Webhook (Opsional sesuai vendor):** Jika vendor mengirimkan status pengiriman (`delivered`/`failed`), endpoint `POST /webhooks/whatsapp/status` memvalidasi signature/secret header dengan `hash_equals()`, memeriksa `provider_message_id`, dan memperbarui status di tabel `reminders` secara idempotent. **Jangan menerima perintah perubahan data tugas/agenda melalui webhook WhatsApp pada MVP 1.**

---

## 3. Kontrak Integrasi AI Planner Multi-Provider (`D-03`, `D-04`, `D-12`, `D-13` — Phase 6)

### 3.1 Urutan Provider (`D-03`)

1. **`GeminiPlannerProvider`** (Provider utama — Google Gemini REST API)
2. **`GrokPlannerProvider`** (Fallback 1 — xAI Grok / OpenAI-compatible API)
3. **`OpenRouterPlannerProvider`** (Fallback 2 — OpenRouter OpenAI-compatible API)
4. **`FallbackPlannerProvider`** (Fallback 3 terakhir — algoritma deterministik lokal tanpa HTTP request)

### 3.2 Interface Provider (`App\Contracts\AiPlannerProviderInterface`)

```php
<?php

namespace App\Contracts;

use App\DataTransferObjects\AiPlannerContext;
use App\DataTransferObjects\AiPlannerProviderResponse;

interface AiPlannerProviderInterface
{
    public function name(): string;

    public function model(): ?string;

    /**
     * @throws \App\Exceptions\AiProviderException
     */
    public function generatePlan(AiPlannerContext $context): AiPlannerProviderResponse;
}
```

### 3.3 Kontrak Output JSON AI Planner

Setiap provider AI wajib diinstruksikan menghasilkan JSON dengan struktur berikut, dan wajib divalidasi oleh backend sebelum disimpan ke `ai_planner_runs.result`:

```json
{
    "summary": "Ringkasan singkat kondisi hari ini.",
    "priorities": [
        {
            "task_id": 123,
            "reason": "Deadline paling dekat.",
            "suggested_action": "Selesaikan bagian pertama tugas.",
            "estimated_minutes": 45
        }
    ],
    "recommended_reminders": [
        {
            "task_id": 123,
            "remind_at_local": "2026-11-16 09:00:00",
            "reason": "Awal slot kosong pagi hari."
        }
    ],
    "warnings": [
        {
            "type": "schedule_conflict",
            "message": "Ada dua agenda yang waktunya berdekatan."
        }
    ]
}
```

### 3.4 Konfigurasi `.env` & `config/services.php` (`D-04`)

Seluruh kredensial dan nama model dikonfigurasi melalui `.env` (jangan pernah di-hardcode di kode):

```dotenv
# WhatsApp Gateway (Phase 5 - D-02)
WHATSAPP_DRIVER=null
WHATSAPP_BASE_URL=
WHATSAPP_API_TOKEN=
WHATSAPP_WEBHOOK_SECRET=

# AI Planner Providers (Phase 6 - D-03 & D-04)
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
GEMINI_TIMEOUT=15

GROK_API_KEY=
GROK_BASE_URL=https://api.x.ai/v1
GROK_MODEL=grok-3-mini
GROK_TIMEOUT=15

OPENROUTER_API_KEY=
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=google/gemini-2.0-flash-exp:free
OPENROUTER_TIMEOUT=15
```
