# Laporan Optimasi Performa — Managjeh (`managjeh_react`)

- **Tanggal:** `2026-11-16`
- **Target Halaman Audit:** `https://managjeh.up.railway.app/dashboard`
- **Dokumen Audit & Rencana:** [docs/PERFORMANCE-AUDIT.md](PERFORMANCE-AUDIT.md)
- **Status:** Implementasi kode & verifikasi lokal **Selesai (`Verified`)**; perubahan infrastruktur produksi Railway & deployment menunggu persetujuan pengguna.

---

## 1. Masalah Awal (`Observed Baseline`)

Berdasarkan laporan Lighthouse (`/dashboard`, `managjeh.up.railway.app-20261116T185327.json`), log HTTP produksi Railway, inspeksi konfigurasi service Railway/Supabase, dan codebase lokal:

| Metrik / Audit                       | Nilai Baseline Terukur                                                                  | Skor / Status                    |
| :----------------------------------- | :-------------------------------------------------------------------------------------- | :------------------------------- |
| **Performance Category Score**       | `0.84` (`84/100`)                                                                       | Needs Optimization               |
| **First Contentful Paint (FCP)**     | `2.2 s`                                                                                 | `0.77`                           |
| **Largest Contentful Paint (LCP)**   | `2.7 s` (`timeToFirstByte: 8,241.9 ms`, `elementRenderDelay: 3,988.2 ms`)               | `0.86` (Warning: exceeded limit) |
| **Speed Index**                      | `18.2 s`                                                                                | `0.00` (Critical)                |
| **Total Blocking Time (TBT)**        | `30 ms`                                                                                 | `1.00` (Good)                    |
| **Cumulative Layout Shift (CLS)**    | `0`                                                                                     | `1.00` (Good)                    |
| **Server Response Time (TTFB)**      | `8,240 ms` (`8,237 ms` root document)                                                   | `0.00` (Critical)                |
| **Unused JavaScript**                | `~103 KiB` (`105,126 B`: `wayfinder-*.js` `74,490 B` + `app-*.js` `30,636 B`)           | `0.00`                           |
| **Cache Lifetime (`cache-insight`)** | `~213 KiB` potential savings (`cacheLifetimeMs: 0` pada seluruh aset `/build/assets/*`) | `0.00`                           |
| **Render-Blocking Stylesheet**       | `~280 ms` (`app-*.css`: `18,045 B` / `300 ms`)                                          | `0.00`                           |
| **Meta Description**                 | Tidak tersedia                                                                          | `0.00`                           |
| **Dashboard SQL Queries (Lokal)**    | `11` query per request `GET /dashboard`                                                 | Dapat dikonsolidasi              |

Log HTTP produksi Railway juga menunjukkan latensi server dasar yang tinggi bahkan sebelum query domain kompleks dijalankan:

- `GET /`: `3,011 – 3,014 ms`
- `GET /login`: `2,968 ms`
- `GET /projects`: `3,517 ms`
- `GET /tasks`: `4,017 ms`
- `GET /dashboard`: `7,882 – 8,109 ms` (p95 `8,041 ms`)
- `POST /login`: `11,218 ms`

---

## 2. Akar Masalah (`Root Cause`)

1. **Cross-Region Topology & Non-Persistent PostgreSQL TLS:** Service Railway `managjeh_react` dideploy di region **`iad` (US East)** sementara database PostgreSQL Supabase `ulmkuftbqeuzkehtsnds` berada di **`ap-northeast-1` (Tokyo)**. Ditambah `config/database.php` belum menyediakan opsi `PDO::ATTR_PERSISTENT`, sehingga setiap HTTP request melakukan TCP + TLS + SCRAM-SHA-256 handshake baru melintasi Samudra Pasifik dan membayar ~180–220 ms per round-trip SQL.
2. **Database Session Driver pada Setiap Request:** `SESSION_DRIVER=database` menambah 2 remote SQL round-trip (`SELECT` + `INSERT/UPDATE` tabel `sessions`) pada **semua** request termasuk rute tanpa data domain (`GET /`, `GET /login`), sementara hasil audit kode mengonfirmasi tidak ada fitur aplikasi yang membaca tabel `sessions` secara langsung.
3. **Query Berurutan & Unbounded di `DashboardAggregatorService` dan `CalendarEventController`:**
    - `getCompletedSummary()` menjalankan 3 query `COUNT(*)` terpisah ke `tasks`.
    - `getTodayTasks()` dan `getUpcomingTasks()` menjalankan dua query hampir identik beserta eager-load `project` terpisah.
    - `getTodayEvents()` dan `CalendarEventController::index()` mengambil seluruh riwayat `calendar_events` milik user (plus relasi `project`, `task`, dan seluruh `overrides`) ke PHP sebelum memfilter rentang tanggal.
4. **Ketiadaan Indeks FK & Komposit:** Supabase Performance Advisor mencatat 5 foreign key tanpa indeks (`tasks.parent_id`, `calendar_events.project_id`, `calendar_events.task_id`, `reminders.task_id`, `reminders.calendar_event_id`) serta belum adanya indeks komposit untuk pola query root tasks dan completion summary Dashboard.
5. **Tidak Ada Header Cache pada Aset Ber-Hash Vite:** `Caddyfile` menyajikan `/build/assets/*` tanpa `Cache-Control`, membuat Lighthouse mencatat `cacheLifetimeMs: 0` (`~213 KiB`).
6. **OPcache Belum Dikonfigurasi & Queue Polling Agresif:** Container FrankenPHP tidak memiliki `production.ini` (`opcache.validate_timestamps=0`, `realpath_cache_size`), sedangkan `queue:work --sleep=3` mem-polling tabel `jobs` di database jarak jauh setiap 3 detik saat idle.
7. **Bundle Entry Frontend & Meta Description:** `resources/js/app.tsx` memuat `AuthLayout`, `SettingsLayout`, dan `Toaster` (`sonner`) secara eager di entry bundle, dan `resources/views/app.blade.php` belum menyertakan `<meta name="description">`.
8. **Ukuran Font Lokal `.ttf`:** Font lokal `Inter` masih memakai `.ttf` (`874.70 kB` regular & `904.53 kB` italic) karena perkakas konversi `.woff2` (`brotli`) belum tersedia di environment lokal.

---

## 3. File yang Diubah / Ditambahkan

| File                                                                                                                                      | Kategori           | Ringkasan Perubahan                                                                                                                     |
| :---------------------------------------------------------------------------------------------------------------------------------------- | :----------------- | :-------------------------------------------------------------------------------------------------------------------------------------- |
| [app/Models/CalendarEvent.php](../app/Models/CalendarEvent.php)                                                                           | Query Optimization | Menambahkan `scopeForDisplayRange()` untuk SQL push-down agenda non-berulang & agenda berulang aktif.                                   |
| [app/Services/Calendar/RecurrenceExpanderService.php](../app/Services/Calendar/RecurrenceExpanderService.php)                             | Query Optimization | Menambahkan `loadRelationsForRange()` agar `project`, `task`, dan `overrides` dalam rentang hanya di-load bila ada hasil.               |
| [app/Services/DashboardAggregatorService.php](../app/Services/DashboardAggregatorService.php)                                             | Query Optimization | Konsolidasi `getCompletedSummary()` menjadi 1 query `COUNT(*) FILTER`, `baseDashboardTaskQuery()` dengan `leftJoin`, & push-down event. |
| [app/Http/Controllers/CalendarEventController.php](../app/Http/Controllers/CalendarEventController.php)                                   | Query Optimization | Menggunakan `forDisplayRange()` + `loadRelationsForRange()` pada `index()`.                                                             |
| [database/migrations/2026_11_16_000007_add_performance_indexes.php](../database/migrations/2026_11_16_000007_add_performance_indexes.php) | Database Index     | Menambahkan 5 indeks FK yang hilang + 3 indeks komposit query pada `tasks`, `calendar_events`, dan `reminders`.                         |
| [docs/DATABASE_SCHEMA.md](../docs/DATABASE_SCHEMA.md)                                                                                     | Dokumentasi DB     | Memperbarui daftar migrasi aktif (12 migrasi) & indeks pada spesifikasi tabel.                                                          |
| [config/database.php](../config/database.php)                                                                                             | DB Connection      | Menambahkan dukungan opt-in `PDO::ATTR_PERSISTENT` untuk driver `pgsql` via `DB_PERSISTENT`.                                            |
| [.env.example](../.env.example)                                                                                                           | Environment Docs   | Menambahkan `DB_PERSISTENT=false` sebagai default aman untuk pengembangan & testing lokal.                                              |
| [Caddyfile](../Caddyfile)                                                                                                                 | HTTP Caching       | Menambahkan header `Cache-Control: public, max-age=31536000, immutable` pada `/build/assets/*` & `max-age=86400` pada ikon/robots.      |
| [docker/php/production.ini](../docker/php/production.ini)                                                                                 | PHP Runtime        | Konfigurasi `opcache` produksi (`validate_timestamps=0`, `memory_consumption=128`, `interned_strings_buffer=16`) & `realpath_cache`.    |
| [Dockerfile](../Dockerfile)                                                                                                               | Docker Build       | Menyalin `docker/php/production.ini` ke `/usr/local/etc/php/conf.d/99-production.ini`.                                                  |
| [docker/start.sh](../docker/start.sh)                                                                                                     | Worker Tuning      | Mengubah polling idle `queue:work` dari `--sleep=3` menjadi `--sleep=15`.                                                               |
| [resources/js/app.tsx](../resources/js/app.tsx)                                                                                           | Frontend Bundle    | Lazy-loading `AuthLayout`, `SettingsLayout`, dan `Toaster` (`sonner`) via `React.lazy` + `Suspense`.                                    |
| [resources/views/app.blade.php](../resources/views/app.blade.php)                                                                         | SEO / Meta         | Menambahkan `<meta name="description">`.                                                                                                |
| [tests/Feature/DashboardTest.php](../tests/Feature/DashboardTest.php)                                                                     | Regression Test    | Menambahkan test konsolidasi query Dashboard (`count($queries) <= 6` dan verifikasi data teragregasi).                                  |
| [tests/Feature/CalendarEventTest.php](../tests/Feature/CalendarEventTest.php)                                                             | Regression Test    | Menambahkan test batas tanggal SQL pada `CalendarEventController::index()` (standalone jauh & recurring kedaluwarsa terfilter).         |
| [docs/PERFORMANCE-AUDIT.md](PERFORMANCE-AUDIT.md)                                                                                         | Dokumentasi        | Laporan audit Phase 1 & rencana optimasi Phase 2.                                                                                       |

---

## 4. Solusi yang Diterapkan

1. **Konsolidasi Query Dashboard (`11` → `<= 6` query):**
    - `getCompletedSummary()` digabung dari 3 query `COUNT(*)` terpisah menjadi 1 query tunggal memakai agregasi PostgreSQL `COUNT(*) FILTER (WHERE ...)`.
    - `getTodayTasks()` dan `getUpcomingTasks()` berbagi `baseDashboardTaskQuery()` dengan `leftJoin('projects', ...)` yang tetap menjaga isolasi `user_id` dan menghapus 2 query eager-load `projects` tambahan.
    - `getTodayEvents()` dan `CalendarEventController@index` menggunakan shared scope `CalendarEvent::scopeForDisplayRange()` agar SQL langsung menyaring non-recurring event dalam rentang dan recurring event aktif (`recurrence_until IS NULL OR >= rangeStart`), diikuti `RecurrenceExpanderService::loadRelationsForRange()` untuk memuat `overrides` hanya pada tanggal di dalam rentang.
2. **Indeks Foreign Key & Komposit PostgreSQL:**
    - Migration `2026_11_16_000007_add_performance_indexes` menambahkan indeks `tasks(parent_id)`, `tasks(user_id, parent_id, status, due_at)`, `tasks(user_id, status, completed_at)`, `calendar_events(project_id)`, `calendar_events(task_id)`, `calendar_events(user_id, parent_event_id)`, `reminders(task_id)`, dan `reminders(calendar_event_id)`.
3. **Hardening Runtime FrankenPHP, Cache HTTP, & PDO Persistent Opt-In:**
    - `Caddyfile` mengatur `Cache-Control: public, max-age=31536000, immutable` untuk aset Vite ber-hash di `/build/assets/*`.
    - `docker/php/production.ini` mengaktifkan tuning OPcache produksi untuk image container immutable.
    - `config/database.php` mendukung `DB_PERSISTENT=true` (opt-in via env var) di produksi untuk mengurangi handshake TLS/SCRAM berulang per request.
    - `docker/start.sh` melonggarkan interval polling idle worker antrean ke `--sleep=15`.
4. **Code-Splitting Frontend & Meta Description:**
    - `resources/js/app.tsx` memisahkan `AuthLayout`, `SettingsLayout`, dan `Toaster` (`sonner` `34.28 kB` / `9.69 kB gzip`) ke `dynamicImports` agar tidak dimuat sinkron pada initial load Dashboard.

---

## 5. Hasil Verifikasi & Pengujian Lokal

Seluruh pemeriksaan dijalankan secara penuh pada environment lokal (`managjeh_test` PostgreSQL) setelah perubahan kode selesai:

| Pemeriksaan                | Perintah                            | Hasil Aktual                                                                                                                                                                                                                   |
| :------------------------- | :---------------------------------- | :----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Pint + PHPStan + Pest**  | `composer test`                     | **Lulus**: Pint passed; PHPStan Level 7 `0 errors`; Pest **`84` passed (`569` assertions)**, `2` skipped (`tests/Feature/Auth/RegistrationTest.php` sengaja dilewati karena `Features::registration()` nonaktif sesuai `D-01`) |
| **Cakupan Regresi Modul**  | Bagian dari `composer test`         | Mencakup `DashboardTest`, `TaskAndProjectCrudTest`, `CalendarEventTest`, `AiPlannerTest`, `WhatsAppReminderTest`, `Phase7HardeningTest`, `Auth/*`, `Settings/*`, `CoreData/*`                                                  |
| **Query Count Regression** | `DashboardTest` (`DB::getQueryLog`) | **Lulus**: `count($queries) <= 6` (turun dari `11` query sebelum optimasi)                                                                                                                                                     |
| **TypeScript Type Check**  | `npm.cmd run types:check`           | **Lulus**: `tsc --noEmit` (`0 errors`)                                                                                                                                                                                         |
| **Frontend Lint & Format** | `npm.cmd run check`                 | **Lulus**: `vp check` (101 file terformat benar, 0 warning/error di 74 file)                                                                                                                                                   |
| **Vite Production Build**  | `npm.cmd run build`                 | **Lulus**: `public/build/manifest.json` terbentuk; `sonner` (`34.28 kB`), `auth-layout` (`1.64 kB`), dan `layout` Settings (`1.70 kB`) terpisah menjadi `dynamicImports`                                                       |

---

## 6. Perbandingan Lighthouse (`Before` vs `After`)

| Metrik Lighthouse (`/dashboard`)     | Before (Baseline Terukur `2026-11-16`) | After (Live Production)                                                                     |
| :----------------------------------- | :------------------------------------- | :------------------------------------------------------------------------------------------ |
| **Performance Score**                | `0.84` (`84/100`)                      | **Belum diukur** (menunggu persetujuan deploy Railway & penyesuaian env produksi)           |
| **First Contentful Paint (FCP)**     | `2.2 s`                                | **Belum diukur**                                                                            |
| **Largest Contentful Paint (LCP)**   | `2.7 s`                                | **Belum diukur**                                                                            |
| **Speed Index**                      | `18.2 s`                               | **Belum diukur**                                                                            |
| **Total Blocking Time (TBT)**        | `30 ms`                                | **Belum diukur**                                                                            |
| **CLS**                              | `0`                                    | **Belum diukur**                                                                            |
| **Server Response Time (TTFB)**      | `8,240 ms`                             | **Belum diukur** _(query SQL Dashboard lokal terverifikasi turun dari `11` menjadi `<= 6`)_ |
| **Cache Lifetime (`cache-insight`)** | `~213 KiB` potential savings           | **Belum diukur** _(aturan `immutable` `Caddyfile` terverifikasi di repo, belum live)_       |
| **Unused JavaScript**                | `~103 KiB`                             | **Belum diukur** _(`sonner` `34.28 kB`, `auth-layout`, `settings/layout` dipisah ke lazy)_  |

> **Catatan Kejujuran Verifikasi:** Sesuai aturan proyek, tidak ada klaim angka Lighthouse `After` sebelum perubahan di-deploy ke Railway dan diuji ulang langsung pada URL produksi.

---

## 7. Sisa Masalah & Langkah Lanjutan (Menunggu Persetujuan)

1. **Perubahan Infrastruktur & Environment Railway (Dampak Terbesar pada TTFB `8.24 s`):**
    - Memindahkan region service Railway `managjeh_react` dari **`iad` (US East)** ke **`asia-southeast1-eqsg3a` (Singapore)** agar lebih dekat dengan Supabase **`ap-northeast-1` (Tokyo)** dan pengguna di Indonesia.
    - Mengatur environment variable produksi:
        - `DB_PERSISTENT=true` (mengaktifkan persistent PDO connection di `config/database.php`).
        - `SESSION_DRIVER=cookie` (opsional, direkomendasikan — menghapus 2 SQL round-trip sesi di setiap request sambil mempertahankan enkripsi `APP_KEY` & `SESSION_SECURE_COOKIE=true`).
2. **Konversi Font Lokal `Inter` `.ttf` → `.woff2`:**
    - File `Inter-VariableFont_opsz,wght.ttf` (`874.70 kB`) dan `Inter-Italic-VariableFont_opsz,wght.ttf` (`904.53 kB`) belum dikonversi ke `.woff2` karena environment lokal belum memiliki paket Python `brotli` dan tidak ada penambahan dependensi eksternal sesuai batasan scope.
3. **Deployment & Pengukuran Ulang Lighthouse:**
    - Setelah Anda menyetujui push ke GitHub / perubahan setting Railway / redeploy, migration `2026_11_16_000007_add_performance_indexes` akan berjalan otomatis pada startup container (`docker/start.sh`), dan Lighthouse perlu dijalankan ulang pada `/dashboard` untuk memvalidasi metrik `After` secara nyata.
