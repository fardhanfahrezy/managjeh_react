# API & External Service Integration — Managjeh (Asisten AI Mahasiswa)

Dokumen ini mendokumentasikan:
1. **Route & Endpoint Internal Aktual (`FACT` — Implemented)** yang digunakan oleh komunikasi Laravel + Inertia.js v3 + Fortify + Passkeys.
2. **Rancangan Integrasi Layanan Eksternal & Route Target (`REQUIREMENT` / `PLANNED` — Not Implemented)** untuk **WhatsApp Gateway**, **WhatsApp Webhook**, **Google Gemini API**, **Groq API**, dan **OpenRouter API**.

---

## 1. Arsitektur Komunikasi Internal Aktual (`FACT` — Implemented)

- **Mekanisme Utama:** Monorepo SPA menggunakan **Inertia.js v3** (`@inertiajs/react` `^3.0.0`, `inertiajs/inertia-laravel` `3.7.0`). Tidak membutuhkan REST API terpisah untuk interaksi antarmuka web.
- **Autentikasi & Proteksi:** Session cookie (`web` guard) + CSRF token (`XSRF-TOKEN` / `ValidateCsrfToken`).
- **HTTP Client Frontend:**
  - Navigasi & pengiriman form: `<Link>`, `<Form>`, dan `router` dari `@inertiajs/react` dipadukan dengan helper **Laravel Wayfinder** (`resources/js/actions/`, `resources/js/routes/`).
  - Request JSON internal (QR code, secret key, recovery codes 2FA): hook `useHttp()` dari `@inertiajs/react`.
  - Handshake WebAuthn Passkeys: `usePasskeyRegister` dan `usePasskeyVerify` dari `@laravel/passkeys/react`.

### Daftar Route Aktual (`php artisan route:list` — `FACT`)

| Kelompok | Method & URI Utama | Middleware | Status |
| --- | --- | --- | --- |
| **App & Dashboard** | `GET /` (`home`), `GET /dashboard` (`dashboard`), `GET /up` | `web` (`auth`, `verified` untuk `/dashboard`) | `Implemented` (`/dashboard` masih placeholder) |
| **Settings Profile** | `GET /settings/profile`, `PATCH /settings/profile`, `DELETE /settings/profile` | `web`, `auth` (`verified` untuk `DELETE`) | `Implemented` |
| **Settings Security & Appearance** | `GET /settings/security`, `PUT /settings/password`, `GET /settings/appearance` | `web`, `auth`, `verified` (`RequirePassword` pada `security`) | `Implemented` |
| **Fortify Auth** | `/login`, `/logout`, `/register`, `/forgot-password`, `/reset-password/*`, `/email/verify*`, `/two-factor-challenge`, `/user/confirm-password` | `web`, `guest` / `auth`, `throttle:*` | `Implemented` |
| **2FA & Passkeys** | `/user/two-factor-*`, `/passkeys/login*`, `/passkeys/confirm*`, `/user/passkeys*`, `/.well-known/passkey-endpoints` | `web`, `auth`/`guest`, `password.confirm`, `throttle:passkeys` | `Implemented` |

---

## 2. Integrasi Eksternal Target (`REQUIREMENT` / `PLANNED` — Not Implemented)

> **PENTING:** Seluruh integrasi eksternal di bawah ini **belum diimplementasikan di repository (`Not Implemented`)**. Jangan mengasumsikan class service, route webhook, atau variabel `.env` terkait sudah tersedia di kode saat ini.

### 2.1 Ringkasan Status Integrasi Eksternal

| Layanan Eksternal | Peran dalam Sistem | Urutan Prioritas | Status Aktual (`FACT`) | Detail Kontrak API |
| --- | --- | --- | --- | --- |
| **WhatsApp Gateway (Outbound)** | Mengirim pengingat (`F3`), rencana pagi (`F7`), balasan obrolan (`F9`), dan ringkasan mingguan (`F11`) | Utama (Inti MVP) | `Not Implemented` | **TODO (`UNKNOWN`):** Vendor gateway belum dipilih |
| **WhatsApp Webhook (Inbound)** | Menerima balasan `"selesai"`, `"tunda"` (`F4`), dan pesan obrolan teks bebas (`F9`) | Utama (Inti MVP) | `Not Implemented` | **TODO (`UNKNOWN`):** Format payload masuk bergantung pada vendor gateway yang dipilih |
| **Google Gemini API** | Penyedia AI Utama untuk perencana harian (`F7`), obrolan (`F9`), dan pemecah tugas (`F10`) | AI Prioritas #1 | `Not Implemented` | Menggunakan REST API Google AI Studio (free tier) |
| **Groq API** | Penyedia AI Cadangan #1 saat Gemini error `429` / timeout | AI Fallback #1 | `Not Implemented` | Menggunakan endpoint OpenAI-compatible Groq Cloud (free tier) |
| **OpenRouter API (`:free`)** | Penyedia AI Cadangan #2 (terakhir sebelum templat statis) | AI Fallback #2 | `Not Implemented` | Menggunakan endpoint OpenAI-compatible OpenRouter dengan model `:free` |
| **Templat Tetap Lokal** | Cadangan akhir tanpa jaringan eksternal AI agar pengingat 100% tetap terkirim | Fallback Terakhir (#4) | `Not Implemented` | Dieksekusi langsung di PHP tanpa panggilan HTTP AI |

---

### 2.2 Integrasi WhatsApp Gateway — Outbound (`REQUIREMENT` — `F3`)

- **Tujuan Integrasi:** Mengirim pesan teks WhatsApp ke nomor pengguna tunggal (`users.nomor_wa`) untuk pengingat tugas/jadwal, konfirmasi aksi, rencana harian, dan ringkasan mingguan.
- **Status Vendor Gateway:** **`UNKNOWN` / `TODO`** — Blueprint menyebutkan *"Satu gateway WhatsApp, kuota gratisnya dicek saat implementasi"*. Endpoint URL spesifik dan struktur header belum ditetapkan karena menunggu pemilihan penyedia (mis. Fonnte, Wablas, WAHA self-hosted, atau Meta Cloud API).
- **Mekanisme Autentikasi Target:** Token API (`WHATSAPP_API_TOKEN`) yang dikirim melalui HTTP Header (mis. `Authorization`) ke base URL `WHATSAPP_API_URL`.
- **Kebutuhan Request & Response Abstrak (`WhatsAppService`):**
  - **Input Internal:** `recipientPhone` (`users.nomor_wa`), `messageText` (string UTF-8), dan opsional `idempotencyKey`.
  - **Output Internal:** Status keberhasilan (`bool`), `externalMessageId` (`?string`), dan `errorMessage` (`?string`).
- **Error Handling & Retry Policy (`REQUIREMENT`):**
  - Dipanggil dari dalam Laravel Queue Job.
  - Jika gateway mengembalikan HTTP `5xx`, network error, atau timeout, Job melakukan *retry* otomatis beberapa kali dengan *exponential backoff* (mis. 3 percobaan: 15 detik, 60 detik, 180 detik).
  - Jika seluruh retry gagal, kolom `reminders.status` diubah menjadi `failed` (atau `gagal`), error dicatat, dan ditampilkan sebagai peringatan di Beranda `"Hari ini"`.
- **Rate Limit & Perlindungan Spam:**
  - Pengiriman ditahan selama jam tenang (`jam_tenang_mulai` – `jam_tenang_selesai`) dan digeser ke `jam_tenang_selesai` (`F5`).

---

### 2.3 Integrasi WhatsApp Webhook — Inbound (`REQUIREMENT` — `F4`, `F9`)

- **Tujuan Integrasi:** Menerima pesan balasan dari pengguna (`"selesai"`, `"tunda"`, atau teks bebas) yang diteruskan oleh gateway WhatsApp ke aplikasi Laravel.
- **Rancangan Endpoint Internal (`PLANNED`):**
  - Method & URI usulan: `POST /api/webhooks/whatsapp` (atau route khusus yang dikecualikan dari CSRF session web).
- **Mekanisme Autentikasi & Validasi Keamanan (`REQUIREMENT`):**
  1. **Verifikasi Token Rahasia:** Memeriksa kecocokan secret token (`WHATSAPP_WEBHOOK_SECRET`) pada header/query/signature menggunakan perbandingan *timing-attack safe* (`hash_equals`). Jika tidak cocok, kembalikan HTTP `401`/`403`.
  2. **Validasi Nomor Pengirim Tunggal:** Menormalisasi nomor pengirim dari payload webhook dan mencocokkannya dengan `users.nomor_wa` milik pengguna tunggal. Jika nomor pengirim bukan milik pengguna, **tolak pesan (`403`)** dan jangan jalankan aksi apa pun.
  3. **Idempotency Webhook (`RECOMMENDATION`):** Memeriksa `external_message_id` pada tabel `messages` agar pengiriman ulang webhook yang sama dari gateway tidak dieksekusi ganda.
- **Pemrosesan Payload Masuk (`REQUIREMENT`):**
  - **TODO (`UNKNOWN`):** Nama field JSON spesifik (mis. `sender`, `message`, `id`) akan dipetakan setelah vendor gateway dipilih.
  - Jika teks pesan (setelah `trim` & `strtolower`) adalah `"selesai"` -> tandai tugas terkait selesai & kirim balasan konfirmasi singkat.
  - Jika teks pesan adalah `"tunda"` -> geser `reminders.kirim_pada` sebesar +30 menit (`status = 'pending'`) & kirim balasan konfirmasi singkat.
  - Jika teks bebas lainnya -> simpan di `messages` dan teruskan ke `AiService` sebagai obrolan asisten (`F9`).

---

### 2.4 Integrasi Layanan AI & Mekanisme Fallback (`REQUIREMENT` — `F7`, `F8`, `F9`, `F10`)

Seluruh pemanggilan AI wajib melalui satu class layanan terpusat (`App\Services\AiService`) menggunakan Laravel HTTP Client (`Illuminate\Support\Facades\Http`).

#### A. Google Gemini API (Penyedia Utama — Prioritas #1)
- **Tujuan:** Menyusun rencana harian (`F7`), menyusun pesan pengingat kontekstual (`F8`), menjawab obrolan (`F9`), dan memecah tugas besar menjadi subtugas (`F10`).
- **Autentikasi:** API Key dari Google AI Studio (`GEMINI_API_KEY`).
- **Karakteristik Request & Response:**
  - Mengirim prompt sistem + konteks terbatas (hanya `tasks` dan `schedules`; **dilarang mengirim catatan harian, mood, atau `nomor_wa`**).
  - Untuk `F7` (Perencana Harian) dan `F10` (Pemecah Tugas), meminta output berformat **JSON** terstruktur.
- **Error Handling & Trigger Fallback:**
  - Jika kuota harian Gemini di tabel `ai_usage` sudah mencapai batas aman, atau API mengembalikan HTTP `429 Too Many Requests`, HTTP `5xx`, maupun koneksi/response timeout, `AiService` menangkap exception tersebut, mencatat log peringatan (tanpa membocorkan API key), dan **langsung beralih ke Groq**.

#### B. Groq API (Penyedia Cadangan Pertama — Prioritas #2)
- **Tujuan:** Menangani permintaan AI ketika Gemini terkena limit (`429`) atau mengalami gangguan/timeout.
- **Autentikasi:** Bearer Token (`GROQ_API_KEY`).
- **Karakteristik Request & Response:**
  - Menggunakan endpoint chat completions yang kompatibel dengan format OpenAI, termasuk mode respons JSON ketika dipanggil oleh perencana harian atau pemecah tugas.
- **Error Handling & Trigger Fallback:**
  - Jika kuota harian Groq di `ai_usage` habis, atau Groq mengembalikan `429` / timeout / error `5xx`, `AiService` **langsung beralih ke OpenRouter**.

#### C. OpenRouter API (Penyedia Cadangan Terakhir — Prioritas #3)
- **Tujuan:** Cadangan AI terakhir menggunakan model gratis berlabel `:free` saat Gemini dan Groq keduanya gagal.
- **Autentikasi:** Bearer Token (`OPENROUTER_API_KEY`).
- **Konfigurasi Model (`REQUIREMENT`):** Wajib menggunakan identifier model yang berakhiran `:free` (`OPENROUTER_MODEL`, `TODO`: tentukan model `:free` spesifik saat implementasi Minggu 4).
- **Error Handling & Trigger Fallback ke Templat Statis:**
  - Jika OpenRouter juga gagal, timeout, atau kuota di `ai_usage` habis, `AiService` beralih ke **Templat Tetap Tanpa AI**.

#### D. Templat Tetap Tanpa AI (Fallback #4 — Jaminan Keandalan 100%)
- **Tujuan (`REQUIREMENT`):** Memastikan pengingat WhatsApp **tidak pernah hilang** meskipun seluruh penyedia AI eksternal mati atau kehabisan kuota gratis.
- **Mekanisme:** Menyusun pesan langsung dari atribut database menggunakan format string deterministik (mis. *"Pengingat Tugas: {judul} — Tenggat: {tenggat_wib}. Balas 'selesai' jika sudah tuntas atau 'tunda' untuk geser 30 menit."*).

---

## 3. Rancangan Route Internal Baru yang Diperlukan (`PLANNED`)

Saat tahap implementasi Minggu 1–6 berjalan, route berikut perlu ditambahkan dan didaftarkan ke Laravel Wayfinder:

| Tahap | Method & Route Target (`PLANNED`) | Tujuan & Referensi Requirement | Status |
| --- | --- | --- | --- |
| Minggu 1 | `GET/POST/PATCH/DELETE /tasks*` | CRUD Tugas & Subtugas (`F1`) | `Planned` |
| Minggu 1 | `GET/POST/PATCH/DELETE /schedules*` | CRUD Jadwal Mingguan (`F2`) | `Planned` |
| Minggu 2 | `POST /api/webhooks/whatsapp` (atau `/webhooks/whatsapp`) | Endpoint penerima webhook WhatsApp (`F4`, `F9`) | `Planned` |
| Minggu 3 | `GET /dashboard` (diperbarui) | Beranda `"Hari ini"` (`F6`) | `Partially Implemented` |
| Minggu 4 | `POST /daily-plan/generate` | Memicu/menyegarkan Perencana Harian AI (`F7`) | `Planned` |
| Minggu 5 | `GET /chat`, `POST /chat/messages` | Halaman & pengiriman Obrolan Asisten AI (`F9`) | `Planned` |
| Minggu 5 | `POST /tasks/{task}/breakdown` | Memecah tugas besar menjadi subtugas via AI (`F10`) | `Planned` |
| Minggu 6 | `GET/POST/PATCH/DELETE /habits*`, `POST /habits/{habit}/toggle` | Pelacak Kebiasaan & Log Harian (`F11`) | `Planned` |

---

## 4. Variabel Environment yang Diperlukan untuk Integrasi (`PLANNED`)

Variabel berikut belum ada di `.env.example` saat ini (`FACT`) dan direncanakan ditambahkan pada Minggu 2 dan Minggu 4 (`REQUIREMENT`):

```dotenv
# WhatsApp Gateway & Webhook (Minggu 2 - TODO sesuaikan dengan vendor yang dipilih)
WHATSAPP_API_URL=
WHATSAPP_API_TOKEN=
WHATSAPP_WEBHOOK_SECRET=

# AI Providers Free Tier (Minggu 4)
GEMINI_API_KEY=
GEMINI_MODEL=
GEMINI_DAILY_LIMIT=

GROQ_API_KEY=
GROQ_MODEL=
GROQ_DAILY_LIMIT=

OPENROUTER_API_KEY=
OPENROUTER_MODEL=
OPENROUTER_DAILY_LIMIT=
```

---

## 5. TODO (`UNKNOWN`)

- [ ] Menentukan vendor **WhatsApp Gateway** dan memverifikasi dokumentasi endpoint pengiriman serta struktur JSON webhook-nya.
- [ ] Menentukan nama model default untuk **Gemini**, **Groq**, dan **OpenRouter (`:free`)** beserta batas aman kuota harian (`*_DAILY_LIMIT`) masing-masing.
- [ ] Menyelaraskan tipe `User` di `resources/js/types/auth.ts` dengan atribut `App\Models\User` aktual dan atribut baru (`nomor_wa`, `zona_waktu`, `jam_tenang_mulai`, `jam_tenang_selesai`).
