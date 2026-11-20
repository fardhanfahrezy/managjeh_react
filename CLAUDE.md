# CLAUDE.md

Panduan orientasi cepat **Managjeh — Personal OS Mahasiswa** untuk Claude Code dan AI Coding Agent.

> **Instruksi Utama (`Single Source of Truth`):** Seluruh aturan arsitektur, konvensi kode, keputusan mengikat `D-01` s.d. `D-19`, serta `<laravel-boost-guidelines>` terpusat di @[AGENTS.md](AGENTS.md). Baca [AGENTS.md](AGENTS.md) terlebih dahulu sebelum merencanakan atau mengubah kode. **Source code aktual dan pengujian otomatis (`tests/`) adalah sumber kebenaran tertinggi.**

---

## 1. Project Overview & Status Aktual (`FACT` & `CONFIRMED`)

- **Produk:** Aplikasi web **Personal OS Mahasiswa** (_single-user sekarang, arsitektur siap multi-user_ dengan isolasi `user_id` di setiap tabel domain). **Website Managjeh** adalah pusat kendali & interaksi AI, sedangkan **WhatsApp** adalah kanal pengingat/notifikasi outbound (`D-16`).
- **Status Implementasi (`FACT`):** **Seluruh MVP 1 (Phase 0–7) dan MVP 2 (Website AI Chat Hub, Internal MCP Server, Proposal Approval & Audit Trail, Perluasan WhatsApp Outbound & Multi-Channel, serta HTTP Security Headers CSP Nonce) telah selesai diimplementasikan dan terverifikasi** (`14` migrasi aktif, `109` Pest tests lulus dengan `787` assertions & `2` skipped `RegistrationTest`, `0` PHPStan level 7 errors, `0` TypeScript errors, `0` Vite+ lint/format errors).
- **Arah Terencana (`CONFIRMED` / `Planned` — Belum Diimplementasikan):**
    - **MVP 3 (`D-18`):** Modul **Habits/Rutinitas/Tidur**, **Finance (Pengeluaran/Anggaran/Tagihan)** dengan privasi ketat, dan **Evaluasi/Refleksi Mingguan**.
- **Modul Aktif:**
    1. **Auth & Provisioning (`D-01`):** Fortify Login, 2FA TOTP, WebAuthn Passkeys, registrasi publik nonaktif, pembuatan akun via `php artisan user:provision`.
    2. **Dashboard (`/dashboard`):** 8 komponen agregasi harian (`DashboardAggregatorService`).
    3. **Tasks (`/tasks`):** CRUD tugas, filter, konversi `due_at` UTC <-> `users.timezone`, subtugas maks. 1 tingkat (`D-09`), sinkronisasi pengingat otomatis.
    4. **Projects (`/projects`, `/projects/{project}`):** CRUD proyek & kalkulasi progres otomatis (`completed / non-cancelled`).
    5. **Calendar (`/calendar`):** Tampilan `Day`/`Week`/`Month`, agenda berjam (UTC) & _all-day_ (tanggal lokal), aturan berulang (`DAILY`/`WEEKLY`/`MONTHLY`), override/exception kejadian tunggal (`D-08`), deteksi konflik jadwal.
    6. **WhatsApp Reminder, Digests & Multi-Channel (`D-02`, `D-05`–`D-07`, `D-14`, `D-19`):** `OutboundChannelManager` + `WhatsAppGatewayInterface` (`FonnteWhatsAppGateway`, `MetaCloudWhatsAppGateway`, `HttpWhatsAppGateway`, `NullWhatsAppGateway`), verifikasi OTP di `/settings/profile`, _quiet hours_ `00:00–05:00`, `event_prep` H-15m, rolling sync 48 jam (`reminders:sync-recurring`), dan ringkasan harian/mingguan deterministik (`reminders:schedule-digests`).
    7. **AI Chat & Internal MCP (`/ai-chat`, `/settings/ai`, `D-16`–`D-19`):** Website AI Chat berdampingan dengan `/ai-planner`, Internal MCP Server (`app/Mcp/` — 6 read + 4 write tools tanpa `user_id`), native tool calling (`Gemini → Grok → OpenRouter`), Action Plan Card dengan persetujuan selektif/TTL 24 jam & deteksi konflik jadwal, audit trail, dan retensi chat 30 hari.
    8. **AI Planner (`/ai-planner`, `D-03`, `D-04`, `D-12`, `D-13`):** Rantai fallback `GeminiPlannerProvider` → `GrokPlannerProvider` → `OpenRouterPlannerProvider` → `FallbackPlannerProvider`, _circuit breaker_ cache, persetujuan pengingat 1 klik.
    9. **Hardening, Security Headers, Data Export & Retention (`D-11`, `D-15`):** `AddSecurityHeaders` (CSP nonce + XFO + XCTO + COOP + HSTS), rate limiters (`ai-planner`, `ai-chat`, `whatsapp-otp`, `data-export`) dengan UI cooldown `429`, ekspor JSON (`GET /settings/profile/export`), dan command `php artisan app:prune-retention-data`.

---

## 2. Technology Stack

- **Backend:** PHP `^8.3` (`8.5.12`), Laravel `13.42.0`, Inertia.js Laravel `3.7.0`, Laravel Fortify `1.42.0`, Laravel Wayfinder `0.1.23`, PostgreSQL (`managjeh` & `managjeh_test`).
- **Frontend:** React `19.2.0`, `@inertiajs/react` `^3.0.0`, TypeScript `^5.7.2`, Tailwind CSS `v4`, Radix UI primitives, Lucide React, Sonner, Vite+ (`vp` `1.4.0`).
- **Visual System:** **"The Quiet Desk"** ([DESIGN.md](DESIGN.md) — font lokal `Inter` dengan `tabular-nums`, kanvas hangat `#fafaf9`, kartu `#ffffff` berborder `#e7e5e4`, aksen utama `#059669`).

---

## 3. Important Directories

- `app/Models/` & `app/Policies/` — Model Eloquent (`User`, `Project`, `Task`, `CalendarEvent`, `Reminder`, `AiPlannerRun`) & Policy kepemilikan `user_id`.
- `app/Http/Controllers/` & `app/Http/Requests/` — Controller Inertia & FormRequest validasi input.
- `app/Services/` — Logika domain (`AiPlanner/*`, `Calendar/*`, `Reminder/*`, `WhatsApp/*`, `DashboardAggregatorService.php`).
- `app/Console/Commands/` & `routes/console.php` — Command CLI & jadwal cron Laravel Scheduler.
- `resources/js/pages/` & `resources/js/components/` — Halaman React Inertia & komponen UI.
- `resources/js/hooks/` & `resources/js/types/` — Custom React hooks (`use-rate-limit-cooldown.ts`) & kontrak TypeScript (`models.ts`).
- `docs/` — Dokumentasi teknis terperinci (lihat daftar di bawah).

---

## 4. Development & Verification Commands

| Tujuan                                           | Perintah                                              |
| :----------------------------------------------- | :---------------------------------------------------- |
| Menjalankan dev server lengkap                   | `composer dev`                                        |
| Menjalankan seluruh pemeriksaan CI (Wajib Lulus) | `composer ci:check`                                   |
| Menjalankan seluruh test Pest                    | `php artisan test`                                    |
| Static analysis PHPStan (level 7, wajib 0 error) | `php vendor/bin/phpstan analyse --memory-limit=512M`  |
| Format kode PHP dengan Pint                      | `php vendor/bin/pint --dirty --format agent`          |
| Format & lint check frontend/docs (`vp`)         | `npm run check` (perbaiki dengan `npm run check:fix`) |
| Type-check TypeScript                            | `npm run types:check` (`npx tsc --noEmit`)            |
| Regenerasi route/action Wayfinder                | `php artisan wayfinder:generate --with-form`          |
| Build aset produksi Vite                         | `npm run build`                                       |

---

## 5. Mandatory Rules Summary (Detail Lengkap di `AGENTS.md`)

1. **Periksa implementasi yang ada sebelum mengedit:** Jangan menduplikasi service, komponen, atau enum yang sudah tersedia.
2. **Keamanan & Kepemilikan Data (`user_id`):** Selalu ambil user dari `$request->user()`, batasi query pada milik user tersebut, dan wajib panggil `$this->authorize(...)`. Jangan pernah mengekspos kredensial, token API, atau kode OTP mentah.
3. **Zona Waktu:** Simpan semua `timestamp` absolut dalam **UTC** di database; gunakan `$user->timezone` untuk konversi input/tampilan lokal serta evaluasi tanggal kalender (`Y-m-d`) dan _quiet hours_ (`00:00–05:00`).
4. **Pemeliharaan Dokumentasi & Verifikasi:** Setiap perubahan arsitektur, route, konfigurasi, atau command wajib diikuti pembaruan dokumentasi terkait di `docs/`, `README.md`, dan `AGENTS.md`, serta wajib lulus `composer ci:check`.

---

## 6. Indeks Dokumentasi di `docs/`

- [AGENTS.md](AGENTS.md) — Panduan utama AI Coding Agent & aturan arsitektur lengkap
- [DESIGN.md](DESIGN.md) & [PRODUCT.md](PRODUCT.md) — Design System **"The Quiet Desk"** & konteks produk
- [docs/PRD.md](docs/PRD.md) — Spesifikasi Produk, Arah MVP 2–3, Keputusan `D-01`–`D-19`, & Riwayat Phase 0–7
- [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — Skema PostgreSQL (12 migrasi aktif) & kebijakan retensi
- [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — Aturan bisnis terverifikasi seluruh modul
- [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) — Arsitektur sistem & komponen
- [docs/FRONTEND_ARCHITECTURE.md](docs/FRONTEND_ARCHITECTURE.md) — Kontrak TypeScript & spesifikasi halaman React
- [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md) — Referensi route, `WhatsAppGatewayInterface`, & `AiPlannerProviderInterface`
- [docs/USER_FLOW.md](docs/USER_FLOW.md) — Diagram alur pengguna & background jobs
- [docs/SECURITY.md](docs/SECURITY.md) — Keamanan, privasi, rate limiting, & sanitasi AI
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Panduan deployment produksi & troubleshooting
- [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) — Referensi implementasi Phase 0–7 & playbook ekstensi fitur
