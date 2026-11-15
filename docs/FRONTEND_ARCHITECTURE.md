# Frontend Architecture — Managjeh (Asisten AI Mahasiswa)

Dokumen ini mendokumentasikan arsitektur frontend **React 19 + Inertia.js v3 + TypeScript + Tailwind CSS v4**, mencakup **Komponen & Halaman Aktual yang Sudah Ada (`FACT`)** serta **Rancangan Halaman Target Asisten AI Mahasiswa (`REQUIREMENT` / `PLANNED`)** beserta pemetaannya terhadap kebutuhan fungsional `F1`–`F11`.

---

## 1. Technology Stack & Versions (`FACT` — dari `package.json`)

- **React:** `^19.2.0` (`react`, `react-dom`) dengan `babel-plugin-react-compiler` (`^1.0.0`) & `strictMode: true`
- **Inertia.js:** `@inertiajs/react` `^3.0.0`, `@inertiajs/vite` `^3.0.0`
- **Passkeys Client:** `@laravel/passkeys` `^0.2.0` (`@laravel/passkeys/react`)
- **TypeScript:** `^5.7.2` (`strict: true`, `noImplicitAny: true`, path alias `@/* -> ./resources/js/*`)
- **Styling:** Tailwind CSS `^4.0.0` (`@tailwindcss/vite` `^4.1.11`, `tw-animate-css` `^1.4.0`), `class-variance-authority`, `clsx`, `tailwind-merge`
- **UI Primitives & Icons:** Radix UI (`dialog`, `dropdown-menu`, `avatar`, `checkbox`, `collapsible`, `label`, `navigation-menu`, `select`, `separator`, `toggle`, `toggle-group`, `tooltip`), `input-otp` (`^1.4.2`), `lucide-react` (`^0.475.0`), `sonner` (`^2.0.0`)
- **Bundler & Route Typing:** `vite-plus` (`1.4.0`), `@vitejs/plugin-react` (`^6.1.1`), `@laravel/vite-plugin-wayfinder` (`^0.1.3`)

---

## 2. Struktur Direktori & Halaman Aktual (`FACT` — Implemented)

- `resources/views/app.blade.php` — root Blade template Inertia.
- `resources/css/app.css` — konfigurasi Tailwind CSS v4 & variabel warna OKLCH (`:root` dan `.dark`).
- `resources/js/app.tsx` — entrypoint `createInertiaApp` dengan pemetaan layout otomatis:
    - `welcome` -> tanpa layout (`null`)
    - `auth/*` -> `AuthLayout` (`auth-simple-layout`)
    - `settings/*` -> `[AppLayout, SettingsLayout]`
    - halaman lainnya -> `AppLayout` (`app-sidebar-layout`)
- **Halaman Aktual di `resources/js/pages/` (`FACT`):**
    - `welcome.tsx` (`Implemented` — halaman sambutan starter kit)
    - `dashboard.tsx` (`Partially Implemented` — masih berisi `PlaceholderPattern`)
    - `auth/`: `login.tsx`, `register.tsx`, `forgot-password.tsx`, `reset-password.tsx`, `two-factor-challenge.tsx`, `verify-email.tsx`, `confirm-password.tsx` (`Implemented`)
    - `settings/`: `profile.tsx`, `security.tsx`, `appearance.tsx` (`Implemented`)

---

## 3. Pemetaan Halaman Target terhadap Requirement `F1`–`F11` (`REQUIREMENT` / `PLANNED`)

Tabel berikut menghubungkan setiap halaman frontend yang direncanakan dengan ID requirement di [docs/PRD.md](PRD.md):

| Nama Halaman                          | Path / Komponen Target (`resources/js/pages/`)                             | Requirement Terkait          | Tahap                               | Status Aktual (`FACT`)                                            |
| ------------------------------------- | -------------------------------------------------------------------------- | ---------------------------- | ----------------------------------- | ----------------------------------------------------------------- |
| **1. Dashboard "Hari Ini"**           | `dashboard.tsx` (`/dashboard`)                                             | `F1`, `F2`, `F3`, `F6`, `F7` | MVP (Minggu 3) & Tahap 2 (Minggu 4) | `Partially Implemented` (masih placeholder)                       |
| **2. Task Management**                | `tasks/index.tsx` (`/tasks`)                                               | `F1`, `F3`, `F10`            | MVP (Minggu 1) & Tahap 2 (Minggu 5) | `Not Implemented` (`Planned`)                                     |
| **3. Schedule Management**            | `schedules/index.tsx` (`/schedules`)                                       | `F2`, `F6`                   | MVP (Minggu 1)                      | `Not Implemented` (`Planned`)                                     |
| **4. Reminder Management**            | Bagian dari `/dashboard` & `/tasks` atau `reminders/index.tsx`             | `F3`, `F4`, `F5`             | MVP (Minggu 2–3)                    | `Not Implemented` (`Planned`)                                     |
| **5. AI Chat (Obrolan Asisten)**      | `chat/index.tsx` (`/chat`)                                                 | `F8`, `F9`                   | Tahap 2 (Minggu 5)                  | `Not Implemented` (`Planned`)                                     |
| **6. Habit Tracker**                  | `habits/index.tsx` (`/habits`)                                             | `F11`                        | Tahap 3 (Minggu 6)                  | `Not Implemented` (`Planned`)                                     |
| **7. Settings (Profil & Jam Tenang)** | `settings/profile.tsx`, `settings/security.tsx`, `settings/appearance.tsx` | `F3`, `F4`, `F5`             | Minggu 1 & Minggu 3                 | `Partially Implemented` (belum ada input `nomor_wa` & jam tenang) |

---

## 4. Rancangan Detail Setiap Halaman (`REQUIREMENT` / `PLANNED`)

### 4.1 Dashboard "Hari Ini" (`resources/js/pages/dashboard.tsx` — `F1`, `F2`, `F3`, `F6`, `F7`)

- **Status:** `Partially Implemented` (file dan route `/dashboard` sudah ada, isi masih `PlaceholderPattern`).
- **Tujuan:** Menjadi layar utama yang dibuka pengguna setiap hari untuk melihat seluruh agenda hari ini dalam satu tampilan terpadu (`F6`).
- **Komponen & Bagian Utama yang Direncanakan:**
    1. **Ringkasan Jadwal Hari Ini (`F2`, `F6`):** Daftar kuliah dan kegiatan berulang yang jatuh pada hari ini (zona waktu `Asia/Jakarta`), diurutkan berdasarkan `jam_mulai` – `jam_selesai`.
    2. **Daftar Tugas Hari Ini & Prioritas (`F1`, `F6`):** Tugas yang jatuh tempo hari ini, tugas yang terlewat (_overdue_), serta subtugas aktif, lengkap dengan checkbox cepat untuk mengubah status menjadi `selesai` (menggunakan `<Form>` / optimistic update Inertia v3).
    3. **Status Pengingat & Peringatan Gagal Kirim (`F3`, `F6`):** Menampilkan pengingat yang akan dikirim hari ini serta banner peringatan jika ada pengingat berstatus `failed`/`gagal` agar pengguna tidak melewatkan tenggat.
    4. **Kartu Rencana Harian AI (`F7` — Tahap 2):** Menampilkan urutan prioritas kerja harian hasil susunan `AiService` beserta tombol untuk menyusun ulang rencana.

### 4.2 Task Management (`resources/js/pages/tasks/index.tsx` — `F1`, `F3`, `F10`)

- **Status:** `Not Implemented` (`Planned` — Minggu 1 & Minggu 5).
- **Tujuan:** Mengelola seluruh tugas kuliah dan pribadi beserta tenggat, prioritas, dan hierarki subtugas.
- **Fitur UI yang Direncanakan:**
    - Filter & pengelompokan tugas berdasarkan `status` (`pending`, `selesai`) dan `prioritas` (`tinggi`, `sedang`, `rendah`).
    - Form tambah/edit tugas (`<Form>` Inertia + Wayfinder) dengan input `judul`, `deskripsi`, `prioritas`, dan `tenggat` (input waktu lokal `Asia/Jakarta`).
    - Hierarki tampilan tugas induk dan daftar subtugas (`parent_id`).
    - **Tombol "Pecah Tugas dengan AI" (`F10` — Minggu 5):** Memanggil action pemecah tugas untuk menghasilkan daftar subtugas bertarget tanggal secara otomatis di bawah tugas induk.

### 4.3 Schedule Management (`resources/js/pages/schedules/index.tsx` — `F2`)

- **Status:** `Not Implemented` (`Planned` — Minggu 1).
- **Tujuan:** Mengelola jadwal kuliah dan kegiatan mingguan secara manual.
- **Fitur UI yang Direncanakan:**
    - Tampilan jadwal dikelompokkan per hari (Senin s.d. Minggu).
    - Form tambah/ubah/hapus jadwal (`judul`, `hari`, `jam_mulai`, `jam_selesai`, toggle `berulang`).
    - Penanda visual untuk hari dan jam yang sedang berlangsung di `Asia/Jakarta`.

### 4.4 Reminder Management (`F3`, `F4`, `F5`)

- **Status:** `Not Implemented` (`Planned` — Minggu 2–3).
- **Tujuan:** Memantau antrean pengingat WhatsApp (`pending`, `processing`, `sent`, `failed`) dan mengelola waktu pengingat.
- **Fitur UI yang Direncanakan:**
    - Indikator waktu pengingat berikutnya pada setiap item tugas/jadwal.
    - Daftar pengingat yang tertunda (termasuk informasi apabila pengingat digeser otomatis karena jatuh pada jam tenang `F5` atau ditunda 30 menit lewat balasan WhatsApp `"tunda"` `F4`).
    - Tombol kirim ulang manual untuk pengingat yang berstatus `failed`.

### 4.5 AI Chat / Obrolan Asisten (`resources/js/pages/chat/index.tsx` — `F8`, `F9`)

- **Status:** `Not Implemented` (`Planned` — Minggu 5).
- **Tujuan:** Menyediakan antarmuka obrolan asisten di web yang tersinkronisasi dengan riwayat tabel `messages` (termasuk percakapan dari WhatsApp).
- **Fitur UI yang Direncanakan:**
    - Daftar gelembung pesan (`incoming` dari pengguna dan `outgoing` dari asisten) yang diurutkan berdasarkan `dibuat_pada` (ditampilkan dalam `Asia/Jakarta`).
    - Input pesan teks bebas untuk berdiskusi mengenai prioritas tugas saat pengguna merasa kewalahan (`F9`).
    - Indikator penyedia AI atau mode fallback jika layanan AI sedang menggunakan cadangan (`F8`).

### 4.6 Habit Tracker (`resources/js/pages/habits/index.tsx` — `F11`)

- **Status:** `Not Implemented` (`Planned` — Minggu 6).
- **Tujuan:** Melacak kebiasaan positif harian dan memvisualisasikan _streak_ konsistensi pengguna.
- **Fitur UI yang Direncanakan:**
    - Daftar kebiasaan (`nama`, `target`) beserta tombol centang selesai untuk tanggal hari ini (`habit_logs`).
    - Badge jumlah _streak_ hari berturut-turut dan tampilan grid 7 hari terakhir sebagai bahan evaluasi menuju Ringkasan Mingguan (`F11`).

### 4.7 Settings (`resources/js/pages/settings/*` — `F3`, `F4`, `F5`)

- **Status:** `Partially Implemented`.
    - **Sudah Ada (`FACT`):** Ubah `name` & `email` serta hapus akun (`profile.tsx`), ubah password, TOTP 2FA, dan Passkeys (`security.tsx`), serta tema tampilan (`appearance.tsx`).
    - **Perlu Ditambahkan (`REQUIREMENT` — Minggu 1 & 3):**
        - Input **`nomor_wa`** pada `settings/profile.tsx` untuk tujuan pengiriman pengingat (`F3`) dan verifikasi pengirim webhook (`F4`).
        - Input **`zona_waktu`** (default `Asia/Jakarta`).
        - Input **`jam_tenang_mulai`** dan **`jam_tenang_selesai`** untuk konfigurasi _Quiet Hours_ (`F5`).
        - Tombol ekspor data pribadi pengguna (sesuai aturan privasi PRD).

---

## 5. Navigasi Sidebar & Konvensi Frontend (`FACT` & `RECOMMENDATION`)

- Menu navigasi utama di `resources/js/components/app-sidebar.tsx` saat ini hanya memuat tautan `Dashboard` (`/dashboard`).
- Seiring implementasi Minggu 1–6, item navigasi sidebar akan diperluas secara bertahap menjadi:
    1. **Hari Ini** (`/dashboard` — `F6`)
    2. **Tugas** (`/tasks` — `F1`, `F10`)
    3. **Jadwal** (`/schedules` — `F2`)
    4. **Obrolan AI** (`/chat` — `F9`, ditambahkan di Minggu 5)
    5. **Kebiasaan** (`/habits` — `F11`, ditambahkan di Minggu 6)
- Seluruh tautan dan form wajib menggunakan helper route/controller dari **Laravel Wayfinder** (`@/routes/*` dan `@/actions/*`), bukan URL hardcoded.

---

## 6. TODO

- [ ] Menambahkan tipe TypeScript domain (`Task`, `Schedule`, `Reminder`, `Habit`, `HabitLog`, `Message`, `DailyPlan`) di `resources/js/types/` saat masing-masing modul diimplementasikan.
- [ ] Menyelaraskan tipe `User` di `resources/js/types/auth.ts` dengan atribut `nomor_wa`, `zona_waktu`, `jam_tenang_mulai`, dan `jam_tenang_selesai`.
