# CLAUDE.md

Instruksi kerja **Managjeh (Asisten AI Mahasiswa)** untuk Claude Code.

> **Single Source of Truth:** Sebelum memulai tugas apa pun, baca [AGENTS.md](AGENTS.md) dan [docs/PRD.md](docs/PRD.md) secara penuh. Seluruh aturan kerja wajib AI Agent (19 aturan inti, pelacakan ID requirement `F1`–`F11`, prioritas MVP Minggu 1–3, kontrol ruang lingkup, serta `<laravel-boost-guidelines>`) terpusat di [AGENTS.md](AGENTS.md) agar tidak terjadi duplikasi instruksi.

## Dokumen Referensi di `docs/`

Baca dokumen yang relevan dengan tugas sebelum mengubah kode:

- [README.md](README.md) — Overview Asisten AI Mahasiswa, status fitur `F1`–`F11`, setup, dan perintah dev/test/build
- [docs/PRD.md](docs/PRD.md) — Visi produk, requirement `F1`–`F11`, kriteria penerimaan, roadmap 6 minggu & technical gap analysis
- [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) & [docs/FRONTEND_ARCHITECTURE.md](docs/FRONTEND_ARCHITECTURE.md) — Arsitektur aktual (`FACT`) vs target (`REQUIREMENT`) untuk Laravel 13 + Inertia v3 + React 19 + Scheduler/Queue + WhatsApp + `AiService`
- [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — Skema PostgreSQL aktual (`users`, `passkeys`, `jobs`, dll.) vs rancangan tabel target (`tasks`, `schedules`, `reminders`, `habits`, `habit_logs`, `messages`, `ai_usage`)
- [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) & [docs/USER_FLOW.md](docs/USER_FLOW.md) — Aturan bisnis (`F1`–`F11`), jam tenang, snooze 30 menit, fallback AI, privasi data, dan 14 diagram alur pengguna
- [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md) — Route Inertia aktual serta spesifikasi integrasi WhatsApp Gateway, Webhook, Gemini, Groq, dan OpenRouter (`:free`)
- [docs/SECURITY.md](docs/SECURITY.md) & [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Keamanan single-user, token webhook, validasi `nomor_wa`, sanitasi prompt AI, serta evaluasi hosting gratis
- [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) — Konvensi kode, struktur direktori, timezone UTC/`Asia/Jakarta`, transaksi, idempotency, dan standar testing

## Gambaran Singkat Arsitektur & Status

- **Monorepo SPA (`Implemented`):** Backend **Laravel 13** (`PHP ^8.3`) berkomunikasi dengan frontend **React 19 + TypeScript + Tailwind CSS v4** melalui **Inertia.js v3** dan **Laravel Wayfinder** (`resources/js/actions/`, `resources/js/routes/`).
- **Database & Timezone (`Implemented`):** PostgreSQL (`DB_CONNECTION=pgsql`, database `managjeh` & `managjeh_test`). Semua timestamp disimpan dalam **UTC** dan ditampilkan/dievaluasi dalam **`Asia/Jakarta`**.
- **Fitur Domain `F1`–`F11` (`Planned` / `Not Implemented`):** Modul tugas (`F1`), jadwal (`F2`), pengingat WhatsApp (`F3`), webhook `"selesai"`/`"tunda"` (`F4`), jam tenang (`F5`), beranda `"Hari ini"` (`F6`), perencana harian AI (`F7`), `AiService` fallback Gemini -> Groq -> OpenRouter `:free` -> templat tetap (`F8`), obrolan AI (`F9`), pemecah tugas (`F10`), dan pelacak kebiasaan (`F11`) masih berstatus `Planned`.

## Batasan Utama Project

- **Prioritaskan MVP (`F1`–`F6`)** dan jangan membangun fitur di luar ruang lingkup (_out of scope_: multi-user, mobile native, pembayaran, keuangan, timer fokus, impor jadwal otomatis) tanpa persetujuan.
- **Jangan mengasumsikan layanan eksternal (WhatsApp Gateway, Gemini, Groq, OpenRouter) sudah terintegrasi** di kode saat ini.
- **Privasi AI:** Jangan pernah mengirimkan catatan harian sensitif, mood, atau `nomor_wa` ke provider AI.
- **Kerahasiaan:** Jangan pernah menulis password, secret token, atau API key ke dalam kode maupun dokumentasi.
- **File Generate:** Jangan mengubah folder hasil generate (`vendor/`, `node_modules/`, `resources/js/{actions,routes,wayfinder}/`, `public/build/`).
