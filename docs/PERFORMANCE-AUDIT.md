# Performance Audit & Optimization Plan — Managjeh

- **Audit Date:** `2026-11-16`
- **Target URL:** `https://managjeh.up.railway.app/dashboard`
- **Baseline Source:** Lighthouse Report (`managjeh.up.railway.app-20261116T185327.json`), Railway Runtime HTTP & Deploy Logs, Railway Service Config, Supabase Schema & Performance Advisors (`get_advisors`), and Repository Source Code.
- **Status:** Phase 1 (Investigation) & Phase 2 (Implementation Plan) Complete — **No application source code modified yet.**

---

## 1. Executive Summary & Observed Baseline

### 1.1 Lighthouse Baseline (`/dashboard`)

| Metric / Audit                       | Observed Baseline                                                               | Lighthouse Score | Status                                   |
| :----------------------------------- | :------------------------------------------------------------------------------ | :--------------- | :--------------------------------------- |
| **Performance Category Score**       | **0.84 (84/100)**                                                               | —                | Needs Optimization                       |
| **First Contentful Paint (FCP)**     | **2.2 s**                                                                       | 0.77             | Slow                                     |
| **Largest Contentful Paint (LCP)**   | **2.7 s** (breakdown: TTFB `8,241.9 ms` + elementRenderDelay `3,988.2 ms`)      | 0.86             | Slow (Warning: page load exceeded limit) |
| **Speed Index**                      | **18.2 s**                                                                      | 0.00             | Critical                                 |
| **Total Blocking Time (TBT)**        | **30 ms**                                                                       | 1.00             | Good                                     |
| **Cumulative Layout Shift (CLS)**    | **0**                                                                           | 1.00             | Good                                     |
| **Server Response Time (TTFB)**      | **8,240 ms** (`8,237 ms` root document)                                         | 0.00             | Critical                                 |
| **Unused JavaScript**                | **~103 KiB** (`105,126` B: `wayfinder-*.js` `74,490` B + `app-*.js` `30,636` B) | 0.00             | High                                     |
| **Cache Lifetime (`cache-insight`)** | **~213 KiB** potential savings (`cacheLifetimeMs: 0` on all `/build/assets/*`)  | 0.00             | High                                     |
| **Render-Blocking Insight**          | **~280 ms** (`app-*.css`: `18,045` B / `300 ms`)                                | 0.00             | Medium                                   |
| **Meta Description**                 | Missing                                                                         | 0.00             | Low (SEO/A11y)                           |

> **Catatan Lighthouse Warning:** Lighthouse mencatat `"The page loaded too slowly to finish within the time limit. Results may be incomplete."` dan font `Inter-VariableFont_opsz_wght-c8O0ljhh.ttf` (`874.70 kB`) baru mulai diminta pada `12,171.7 ms` serta tidak selesai sebelum timeout audit.

### 1.2 Observed Railway Runtime HTTP Timings (Production Logs)

Dari log HTTP produksi `managjeh_react` (`f2dd6f63-987f-4e5b-8330-bb29b36edd84`):

- `GET /` (0 domain queries; hanya Laravel boot + middleware sesi): **`3,011 – 3,014 ms`**
- `GET /login` (hanya Laravel boot + middleware sesi): **`2,968 ms`**
- `GET /projects`: **`3,517 ms`**
- `GET /tasks`: **`4,017 ms`**
- `GET /dashboard`: **`7,882 – 8,109 ms`** (p95 bucket: **`8,041 ms`**)
- `POST /login`: **`11,218 ms`**
- Asset statis (`/build/assets/*.js`, `/build/assets/*.css`): **`1 – 20 ms`** (terkecuali transfer size ke client/Lighthouse tanpa `Cache-Control`).

**Temuan Kunci:** Bahkan route statis `GET /` yang tidak menjalankan query domain memakan **`~3,014 ms`** pada server, dan `GET /dashboard` memakan **`~8,000 ms`**. Ini membuktikan masalah utama berada pada **latensi jaringan lintas-region ke PostgreSQL + pembukaan koneksi TLS Postgres baru per-request + jumlah query SQL berurutan (10–14+ round-trips)**, bukan pada kompleksitas CPU React (`TBT = 30 ms`).

---

## 2. Prioritized Audit Findings & Implementation Plan

Temuan diurutkan berdasarkan **dampak performa terbesar dan tingkat urgensi tertinggi**.

---

### [P0 — Critical] 1. Cross-Region Topology Mismatch & Non-Persistent PostgreSQL TLS Connections

- **Problem Description:** Server Response Time `/dashboard` mencapai **8.24 detik** (`GET /` tanpa query domain pun **3.01 detik**).
- **Root Cause:**
    1. **Cross-Pacific Region Mismatch:** Service Railway `managjeh_react` berjalan di region **`iad` (US East — Ashburn, Virginia)**, sedangkan database Supabase `ulmkuftbqeuzkehtsnds` berada di **`ap-northeast-1` (Tokyo, Japan)** (`aws-1-ap-northeast-1.pooler.supabase.com:5432`). Setiap satu round-trip TCP melintasi Samudra Pasifik memakan ~180–220 ms.
    2. **Fresh PostgreSQL TLS + SCRAM Handshake Per-Request:** [config/database.php](../config/database.php) mengatur koneksi `pgsql` dengan `'options' => []` (tanpa `PDO::ATTR_PERSISTENT`) sementara FrankenPHP berjalan dalam mode `php_server` standar. Akibatnya setiap HTTP request harus membuka koneksi TCP + negosiasi TLS (`DB_SSLMODE=require`) + autentikasi SCRAM-SHA-256 + `SET TIME ZONE` dari awal (~1.2–1.6 detik hangus sebelum query pertama dieksekusi).
- **Affected Files:**
    - Railway service config (`regions`: `iad` → `asia-southeast1-eqsg3a`)
    - [config/database.php](../config/database.php) (`pgsql.options` untuk mendukung `PDO::ATTR_PERSISTENT` via env `DB_PERSISTENT`)
    - [.env.example](../.env.example) & Railway env var `DB_PERSISTENT`
- **Recommended Solution:**
    1. _(Infrastruktur Railway — butuh konfirmasi sebelum apply)_ Pindahkan region deployment Railway service `managjeh_react` dari `iad` ke **`asia-southeast1-eqsg3a` (Singapore)** agar latensi Railway↔Supabase Tokyo turun drastis (~65–75 ms vs ~200 ms) dan latensi user Indonesia↔Railway turun dari ~240 ms ke ~25 ms.
    2. Aktifkan persistent PDO connection yang aman secara opt-in (`PDO::ATTR_PERSISTENT => filter_var(env('DB_PERSISTENT', false), FILTER_VALIDATE_BOOL)` pada koneksi `pgsql` di [config/database.php](../config/database.php)), kompatibel dengan Supabase Session Pooler port `5432`.
- **Risk Assessment:**
    - _Low-Medium:_ Jika `DB_PERSISTENT=true` diaktifkan terhadap Supabase Session Pooler (`port 5432`), jumlah koneksi persisten per worker thread FrankenPHP tetap kecil (single-user / small concurrency), namun harus menjaga batas `max_connections` pooler dengan worker thread terbatas (atau memantau jumlah koneksi aktif). Tetap jadikan opt-in via env var (`DB_PERSISTENT=false` default lokal/test, `true` di produksi) agar PHPUnit test dan CLI tidak terpengaruh.
- **Expected Performance Impact:**
    - Menghilangkan ~1.2–1.5s TLS handshake per request dan memangkas setiap RTT SQL sebesar ~60–70%, menurunkan TTFB dasar dari ~3,000 ms menjadi ~150–400 ms.
- **Required Regression Tests:**
    - Verifikasi seluruh 82 test Pest (`php artisan test`) tetap hijau dengan PostgreSQL `managjeh_test`.
    - Unit/Feature test memastikan `config('database.connections.pgsql.options')` terkonfigurasi bersih tanpa error saat extension `pdo_pgsql` aktif.

---

### [P0 — Critical] 2. Remote Database Session Driver (`SESSION_DRIVER=database`) on Every Request

- **Problem Description:** Setiap request HTTP (termasuk `GET /` dan `GET /login`) melakukan 2 query tulis/baca ke database PostgreSQL jarak jauh hanya untuk manajemen sesi.
- **Root Cause:**
    - `SESSION_DRIVER=database` aktif di produksi ([config/session.php](../config/session.php) & Railway env).
    - Hasil audit kode (`Select-String` pada `app/`, `config/`, `tests/`): **tidak ada fitur** yang membaca atau mengelola tabel `sessions` secara langsung (tidak ada halaman "Active Browser Sessions" ataupun fitur revoke session DB).
    - Setiap HTTP request membayar biaya `SELECT * FROM sessions WHERE id = ?` saat `StartSession` dimulai dan `INSERT/UPDATE sessions` saat response dikirim — menambah ~500–600 ms overhead konstan bahkan untuk halaman tanpa query domain.
- **Affected Files:**
    - Railway env variable `SESSION_DRIVER` (`database` → `cookie`) — _(atau cache/cookie)_
    - [docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)
- **Recommended Solution:**
    - Pertimbangkan mengubah `SESSION_DRIVER=cookie` di Railway (data sesi Laravel hanya berisi ID user login, CSRF token, flash toast singkat, dan state 2FA/Passkey, jauh di bawah batas 4 KB, serta tetap terenkripsi AES-256-CBC & HMAC-signed dengan `APP_KEY` dan `SESSION_SECURE_COOKIE=true`).
    - Catatan: Karena mengubah driver ke `cookie` pada produksi membutuhkan persetujuan deployment, perubahan env produksi ditunda hingga tahap verifikasi/persetujuan deploy, sementara aplikasi tetap mendukung konfigurasi `SESSION_DRIVER` via env.
- **Risk Assessment:**
    - _Low:_ Memastikan payload session (seperti flash message atau passkey assertion state) tidak melebihi batas 4 KB cookie browser. Passkey options / assertion challenge bersifat sementara dan berukuran kecil (< 1 KB). Jika user ingin mempertahankan tabel `sessions` untuk audit, opsi ini dapat dilewati dan diganti dengan region `asia-southeast1` + `PDO::ATTR_PERSISTENT`.
- **Expected Performance Impact:**
    - Menghapus **2 remote SQL round-trips** dari **setiap** HTTP request (`GET /`, `/login`, `/dashboard`, `/tasks`, `/projects`, `/calendar`, `/ai-planner`), menghemat ~400–600 ms (atau ~150 ms pasca pindah region).
- **Required Regression Tests:**
    - Uji fitur Auth (Login, Logout, 2FA, Passkeys), Flash Toast, dan Dashboard dengan `SESSION_DRIVER=cookie` maupun `database` dalam test suite.

---

### [P0 — High] 3. Sequential & Unbounded Database Queries in `DashboardAggregatorService`

- **Problem Description:** Endpoint `GET /dashboard` mengeksekusi **8–12 query SQL secara berurutan**, memicu pembengkakan Server Response Time menjadi **~8,000 ms** di lingkungan produksi.
- **Root Cause:**
    1. **`getCompletedSummary()` (`DashboardAggregatorService.php:207–233`):** Menjalankan **3 query `COUNT(*)` terpisah** pada tabel `tasks`:
        - `$todayCount` (`status = 'completed'` & `completed_at BETWEEN ...`)
        - `$last7DaysCount` (`status = 'completed'` & `completed_at >= ...`)
        - `$activeCount` (`parent_id IS NULL` & `status IN ('todo', 'in_progress')`)
    2. **`getTodayEvents()` (`DashboardAggregatorService.php:141–159`):** Memanggil `$user->calendarEvents()->whereNull('parent_event_id')->with([...])->get()` **tanpa filter tanggal SQL**. Seluruh riwayat agenda sepanjang masa diambil dari PostgreSQL (plus relasi eager-load `project`, `task`, `overrides.project`, `overrides.task`) lalu disaring di PHP oleh `RecurrenceExpanderService`.
    3. **`getTodayTasks()` & `getUpcomingTasks()` (`DashboardAggregatorService.php:84–136`):** Keduanya melakukan query hampir identik ke `tasks` (`user_id = ? AND parent_id IS NULL AND status IN ('todo', 'in_progress')`) dengan `->with('project:id,name,status')` secara terpisah.
    4. **Tidak ada user-scoped response/aggregation cache atau single-query consolidation** pada data dashboard saat tidak ada mutasi.
- **Affected Files:**
    - [app/Services/DashboardAggregatorService.php](../app/Services/DashboardAggregatorService.php)
    - [app/Http/Controllers/CalendarEventController.php](../app/Http/Controllers/CalendarEventController.php) (juga memiliki query `calendarEvents()` tanpa pre-filter rentang tanggal pada baris 45–53)
    - [tests/Feature/DashboardTest.php](../tests/Feature/DashboardTest.php)
    - [tests/Feature/CalendarEventTest.php](../tests/Feature/CalendarEventTest.php)
- **Recommended Solution:**
    1. **Gabungkan 3 query `getCompletedSummary()` menjadi 1 query tunggal** menggunakan agregasi kondisional (`SELECT SUM(CASE WHEN ... THEN 1 ELSE 0 END) AS ...`), kompatibel 100% dengan PostgreSQL tanpa mengubah struktur hasil.
    2. **Push-down filter tanggal ke SQL pada `getTodayEvents()` dan `CalendarEventController@index`:**
        - Pada SQL: ambil hanya baris yang `(recurrence_rule IS NOT NULL AND recurrence_rule != '' AND (recurrence_until IS NULL OR recurrence_until >= ?))` **ATAU** agenda non-berulang yang bertampalan dengan `[rangeStart, rangeEnd]` (baik `is_all_day = true` via `start_date`/`end_date` maupun `is_all_day = false` via `starts_at`/`ends_at`).
        - Batasi relasi `overrides` hanya untuk tanggal di dalam rentang (`whereBetween('recurrence_id', [$rangeStartStr, $rangeEndStr])` atau `where('recurrence_id', $todayStr)` di Dashboard).
    3. **Gabungkan query dasar active root tasks atau kurangi round-trip eager-load** pada `getTodayTasks()` & `getUpcomingTasks()` (mis. dengan `JOIN` ringan atau 1 query + partisi di PHP, menjaga batas `limit(10)` & `limit(8)` serta urutan tetap identik).
    4. _(Opsional/Terukur)_ User-scoped short-TTL cache (`dashboard:v1:user:{id}:{local_date}`) dengan key version/invalidation berbasis event/model observer **hanya** jika tidak membocorkan data lintas `user_id` dan selalu di-invalidate saat `Task`, `Project`, `CalendarEvent`, `AiPlannerRun`, atau profil `User` berubah. _(Prioritaskan penggabungan query + SQL pushdown terlebih dahulu karena efeknya bersih tanpa risiko stale cache!)_
- **Risk Assessment:**
    - _Medium (pada SQL push-down recurrence & task partitioning):_ Query SQL `OR` untuk `is_all_day` vs berjam vs `recurrence_rule` harus mempertahankan aturan exact-overlap (termasuk agenda multi-hari dan pergantian hari di timezone pengguna `Asia/Jakarta`). Wajib dikunci oleh test timezone & recurrence yang sudah ada + test baru.
- **Expected Performance Impact:**
    - Mengurangi jumlah SQL round-trip pada `GET /dashboard` dari ~10–14 query menjadi ~5–6 query yang jauh lebih ringan, serta mencegah transfer data historis `calendar_events` yang terus membesar seiring waktu.
- **Required Regression Tests:**
    - `DashboardTest`: pastikan agregasi 8 bagian (`today_tasks`, `upcoming_tasks`, `today_events` termasuk recurrence/override/all-day, `active_projects`, `ai_priorities`, `whatsapp_status`, `completed_summary`) identik dengan jumlah query `DB::getQueryLog()` yang lebih sedikit.
    - `CalendarEventTest`: pastikan non-recurring event di luar rentang tidak termuat, sedangkan recurring event dari bulan sebelumnya yang berulang ke hari ini tetap termuat dengan benar.

---

### [P1 — High] 4. Missing HTTP Cache Headers on Hashed Vite Assets (`cache-insight`: `213 KiB`)

- **Problem Description:** Audit Lighthouse `cache-insight` melaporkan potensi penghematan **`213 KiB`** dengan `cacheLifetimeMs: 0` pada seluruh 18+ file aset di `/build/assets/*` (JS, CSS, dan font Inter).
- **Root Cause:**
    - [Caddyfile](../Caddyfile) hanya memiliki:
        ```caddyfile
        root * /app/public
        encode zstd gzip
        php_server
        ```
        Tanpa direktif `header` untuk `/build/assets/*`, browser dan CDN tidak mendapatkan `Cache-Control: public, max-age=31536000, immutable` sehingga menganggap aset ber-hash konten memiliki TTL `0`.
- **Affected Files:**
    - [Caddyfile](../Caddyfile)
- **Recommended Solution:**
    - Tambahkan header caching jangka panjang pada [Caddyfile](../Caddyfile) khusus untuk aset Vite ber-hash konten (`/build/assets/*`) serta cache moderat untuk ikon statis (`/favicon.ico`, `/favicon.svg`, `/apple-touch-icon.png`):
        ```caddyfile
        @vite_assets path /build/assets/*
        header @vite_assets Cache-Control "public, max-age=31536000, immutable"

        @static_icons path /favicon.ico /favicon.svg /apple-touch-icon.png /robots.txt
        header @static_icons Cache-Control "public, max-age=86400"
        ```
- **Risk Assessment:**
    - _Very Low:_ Seluruh file di `/build/assets/*` memiliki content hash unik di nama filenya (dihasilkan Vite), sehingga `immutable` `max-age=31536000` sepenuhnya aman tanpa risiko stale assets saat deploy baru.
- **Expected Performance Impact:**
    - Menghilangkan temuan `cache-insight` **`213 KiB`** sepenuhnya (`score: 1.0`) dan membuat repeat visit serta navigasi antarhalaman instan untuk seluruh JS/CSS/font.
- **Required Regression Tests:**
    - Inspeksi syntax `Caddyfile` dan verifikasi header `Cache-Control` setelah deploy.

---

### [P1 — High] 5. Missing Database Indexes (Verified by Supabase Performance Advisor)

- **Problem Description:** Beberapa foreign key dan pola query utama pada `tasks`, `calendar_events`, dan `reminders` belum dilindungi indeks, berisiko memicu sequential scan seiring pertumbuhan data.
- **Root Cause:**
    - Hasil `get_advisors(type: 'performance')` pada Supabase mengidentifikasi **5 foreign keys tanpa covering index**:
        1. `tasks(parent_id)`
        2. `calendar_events(project_id)`
        3. `calendar_events(task_id)`
        4. `reminders(task_id)`
        5. `reminders(calendar_event_id)`
    - Selain itu, query `DashboardAggregatorService` dan `TaskController` melakukan filter spesifik:
        - `tasks`: `(user_id, parent_id, status, due_at)` (untuk `whereNull('parent_id')->whereIn('status', ...)` + `orderBy('due_at')`)
        - `tasks`: `(user_id, status, completed_at)` (untuk ringkasan `completed_at` 7 hari terakhir)
        - `calendar_events`: `(user_id, parent_event_id)` (untuk `whereNull('parent_event_id')`)
- **Affected Files:**
    - Migration baru di `database/migrations/` (mis. `2026_11_16_000007_add_performance_indexes.php`)
    - [docs/DATABASE_SCHEMA.md](../docs/DATABASE_SCHEMA.md)
- **Recommended Solution:**
    - Buat **1 migration baru** yang menambahkan covering indexes untuk kelima FK di atas serta indeks komposit query Dashboard/Tasks:
        - `tasks`: index `(parent_id)`, `(user_id, parent_id, status, due_at)`, `(user_id, status, completed_at)`
        - `calendar_events`: index `(project_id)`, `(task_id)`, `(user_id, parent_event_id)`
        - `reminders`: index `(task_id)`, `(calendar_event_id)`
- **Risk Assessment:**
    - _Very Low:_ Penambahan indeks `CREATE INDEX` bersifat non-breaking, menjaga integritas data dan isolasi `user_id`. Catatan: pada proyek single-user saat volume baris masih sangat kecil, PostgreSQL planner mungkin tetap memilih Seq Scan, namun indeks tetap wajib untuk skala jangka panjang dan menghilangkan 5 warning `unindexed_foreign_keys` Supabase.
- **Expected Performance Impact:**
    - Menghilangkan 5 advisory `unindexed_foreign_keys` di Supabase, mempercepat `cascadeOnDelete` / `nullOnDelete`, query subtugas, dan agregasi Dashboard pada dataset yang lebih besar.
- **Required Regression Tests:**
    - Jalankan `php artisan migrate:fresh` pada `managjeh_test` + seluruh `php artisan test`.

---

### [P1 — High] 6. Frontend Root Bundle Bloat & Unused JavaScript (`103 KiB`)

- **Problem Description:** Lighthouse melaporkan **`~103 KiB` Unused JavaScript** (`wayfinder-BPYF1KV3.js`: `74,490 B` / 63.8% unused dari `116,710 B`; `app-qaMGwOpr.js`: `30,636 B` / 65.0% unused dari `47,137 B`), dan `elementRenderDelay` pada LCP mencapai **`3,988 ms`**.
- **Root Cause:**
    1. **`app.tsx` Memuat Layout & Library Non-Dashboard Secara Eager:**
        - [resources/js/app.tsx](../resources/js/app.tsx#L5-L7) mengimpor `AppLayout`, `AuthLayout`, dan `SettingsLayout` secara statis di root bundle, serta membungkus root dengan `<Toaster />` (`sonner`) dan `<TooltipProvider />` (`@radix-ui/react-tooltip`). Akibatnya kode halaman Auth, Settings navigation, dan seluruh `sonner` dimuat saat membuka `/dashboard` (menyebabkan 65% kode `app-*.js` tidak terpakai).
    2. **Monolithic Shared Vendor Chunk (`wayfinder-*.js` 360 KB raw):**
        - Rolldown/Vite menggabungkan `react`, `react-dom`, `@inertiajs/react`, dan `@/wayfinder` ke satu chunk raksasa (`wayfinder-*.js`, `117 KiB` transfer) karena tidak ada pemisahan `manualChunks`, sehingga membutuhkan `3.83 detik` download serial pada profil mobile Lighthouse.
    3. **Komponen `Toaster` di Root:** `sonner` (~15–20 KiB) dimuat sinkron pada initial load padahal hanya dibutuhkan setelah ada flash/action.
- **Affected Files:**
    - [resources/js/app.tsx](../resources/js/app.tsx)
    - [vite.config.ts](../vite.config.ts)
- **Recommended Solution:**
    1. Gunakan lazy loading (`lazy` / dynamic `import()`) untuk `AuthLayout`, `SettingsLayout`, dan `Toaster` di [resources/js/app.tsx](../resources/js/app.tsx) — atau pindahkan wrapping layout ke properti layout dinamis yang di-lazy-load sesuai `name` halaman (di `/dashboard`, hanya `AppLayout` yang dibutuhkan!).
    2. Konfigurasikan `build.rolldownOptions.output.manualChunks` (atau `rollupOptions`) yang aman pada [vite.config.ts](../vite.config.ts) bila membantu memisahkan chunk stabil (`react`, `inertia`), dan biarkan tree-shaking membuang export Wayfinder yang tidak terpakai per halaman.
    3. Pastikan `AppLayout` & halaman `/dashboard` tidak mengalami layout shift (`CLS = 0` dipertahankan) saat `Toaster` dimuat secara lazy dengan `Suspense fallback={null}`.
- **Risk Assessment:**
    - _Low-Medium:_ Dynamic layout loading pada `createInertiaApp` di `@inertiajs/react` v3 harus dipastikan mengembalikan komponen/fungsi layout yang kompatibel dengan `layout` resolver Inertia v3 (cek tipe di `@inertiajs/react` v3 sebelum mengimplementasikan `Suspense` / lazy wrapper!).
- **Expected Performance Impact:**
    - Mengurangi transfer ukuran root `app-*.js` dan total Unused JavaScript pada `/dashboard` (~25–40 KiB reduction) sehingga menurunkan `elementRenderDelay` & mempercepat hydration React.
- **Required Regression Tests:**
    - `npm run types:check`, `npm run check`, `npm run build`, serta verifikasi ukuran chunk hasil `public/build/manifest.json` untuk halaman `dashboard`, `login`, dan `settings/profile`.

---

### [P2 — Medium] 7. Untuned PHP OPcache & Worker Polling Contention in Container

- **Problem Description:** FrankenPHP menjalankan mode classic per-request tanpa konfigurasi `opcache` produksi, sekaligus berbagi CPU container Free-plan dengan dua background loop (`queue:work --sleep=3` dan `schedule:work`).
- **Root Cause:**
    1. [Dockerfile](../Dockerfile) tidak menyertakan konfigurasi `php.ini` untuk production OPcache (`opcache.validate_timestamps=0`, `opcache.memory_consumption=128`, `opcache.interned_strings_buffer=16`, `realpath_cache_size=4096K`).
    2. [docker/start.sh](../docker/start.sh) menjalankan `php artisan queue:work --sleep=3` (memeriksa tabel `jobs` di Supabase setiap 3 detik saat idle = ~20 query/menit terus-menerus melintasi jaringan!) dan `schedule:work` (memicu subprocess PHP artisan tiap 60 detik).
- **Affected Files:**
    - [Dockerfile](../Dockerfile)
    - [docker/start.sh](../docker/start.sh)
- **Recommended Solution:**
    1. Tambahkan file konfigurasi `docker/php/production.ini` yang disalin ke `/usr/local/etc/php/conf.d/99-production.ini` di `Dockerfile` untuk mengoptimalkan `opcache` dan `realpath_cache_size`.
    2. Naikan `--sleep=15` (atau `--sleep=10`) pada `queue:work` di [docker/start.sh](../docker/start.sh) guna mengurangi polling database idle sebesar 70–80% tanpa mengganggu pengiriman pengingat WhatsApp (mengingat dispatcher `reminders:dispatch-due` memang berjalan per 1 menit).
    3. _(Catatan Arsitektur Lanjutan — Opsional)_ Evaluasi Laravel Octane (FrankenPHP Worker mode) hanya jika diperlukan setelah melihat hasil perubahan region + persistent PDO + query consolidation, agar tidak menambah kompleksitas state/memory leak pada MVP 1 saat ini.
- **Risk Assessment:**
    - _Low:_ `opcache.validate_timestamps=0` aman 100% di Docker container immutable karena setiap deploy membangun ulang container baru. Mengubah `--sleep=15` membuat delay maksimal job baru yang baru didispatch saat idle menjadi maks 15 detik (sangat memadai untuk pengingat WhatsApp).
- **Expected Performance Impact:**
    - Mempercepat boot PHP per-request (menghilangkan stat checks) dan menurunkan beban DB polling & CPU background pada container kecil.
- **Required Regression Tests:**
    - Verifikasi container Docker / `php -i` (bila diuji lokal) atau pastikan syntax shell & test suite Laravel tetap lulus.

---

### [P2 — Medium] 8. Uncompressed Local Font Assets (`Inter` `.ttf` 874.7 KB) & Render-Blocking CSS

- **Problem Description:** `Inter-VariableFont_opsz,wght.ttf` berukuran **874.70 kB** (dan italic `904.53 kB`), dan Lighthouse melaporkan `app-*.css` sebagai resource render-blocking (`280 ms`) serta font tidak sempat selesai diunduh selama audit lambat.
- **Root Cause:**
    - [resources/css/app.css](../resources/css/app.css#L10-L26) menggunakan format raw `.ttf` (`format('truetype')`), bukan `.woff2` (`format('woff2')`) yang jauh lebih terkompresi (~250–330 kB vs 874.7 kB).
    - [resources/views/app.blade.php](../resources/views/app.blade.php) tidak memiliki `<meta name="description">`.
- **Affected Files:**
    - `resources/fonts/Inter/*`
    - [resources/css/app.css](../resources/css/app.css)
    - [resources/views/app.blade.php](../resources/views/app.blade.php)
- **Recommended Solution:**
    1. Pertahankan keluarga font lokal tunggal **Inter** sesuai `DESIGN.md` ("The Single-Family Rule"), namun konversi file `.ttf` ke `.woff2` (jika perkakas `fonttools`/`brotli` tersedia saat implementasi) atau siapkan fallback `@font-face` dengan `.woff2` sebagai sumber utama sehingga transfer font turun ~65–70%.
    2. Tambahkan `<meta name="description" content="Managjeh — Personal OS Mahasiswa untuk mengelola tugas, proyek, jadwal kuliah, pengingat WhatsApp, dan AI Planner harian.">` di [resources/views/app.blade.php](../resources/views/app.blade.php) untuk memperbaiki skor Lighthouse `meta-description`.
- **Risk Assessment:**
    - _Very Low:_ `.woff2` didukung oleh seluruh browser modern dan menghasilkan tampilan tipografi Inter (`tabular-nums`) yang identik dengan `.ttf`.
- **Expected Performance Impact:**
    - Penghematan ~550–600 KB bandwidth transfer font saat first-visit dan skor `meta-description` naik dari 0 menjadi 1.
- **Required Regression Tests:**
    - `npm run build` dan cek manifest font pada `public/build/manifest.json`.

---

## 3. Recommended Execution Order (Phase 3 Plan)

Urutan eksekusi yang aman agar setiap perubahan teruji bertahap (TDD) dan tidak mengubah perilaku bisnis maupun UI tanpa kebutuhan terverifikasi:

1. **Step 1 — Backend Query Optimization & Database Indexes (Code + Migration + Tests):**
    - Tulis test regresi baru & assertions jumlah query di `DashboardTest.php` dan `CalendarEventTest.php` (TDD Red → Green).
    - Optimalkan `DashboardAggregatorService.php` (satukan `getCompletedSummary` jadi 1 query, push-down SQL filter tanggal pada `getTodayEvents`, dan konsolidasikan active root task fetch).
    - Optimalkan `CalendarEventController.php` dengan push-down filter tanggal SQL yang sama (extract shared query scope di `CalendarEvent` agar tidak ada duplikasi logika!).
    - Tambahkan migration indeks FK & komposit untuk `tasks`, `calendar_events`, dan `reminders`.
2. **Step 2 — Database & PHP Runtime Hardening (Repo Config + Docker):**
    - Tambahkan dukungan `DB_PERSISTENT` pada `pgsql` di `config/database.php` (dan `.env.example`).
    - Perbarui `Caddyfile` dengan `Cache-Control: public, max-age=31536000, immutable` untuk `/build/assets/*`.
    - Tambahkan `docker/php/production.ini` (OPcache tuning) di `Dockerfile`, serta sesuaikan `queue:work --sleep=15` di `docker/start.sh`.
3. **Step 3 — Frontend Bundle, Font & Blade Meta Optimization:**
    - Lazy-load `AuthLayout`, `SettingsLayout`, dan `Toaster` di `resources/js/app.tsx`; atur clean chunking di `vite.config.ts` jika diperlukan.
    - Konversi `Inter` `.ttf` ke `.woff2` (jika tooling tersedia; jika tidak, dokumentasikan sebagai gap) dan perbarui `resources/css/app.css`.
    - Tambahkan `<meta name="description">` di `resources/views/app.blade.php`.
4. **Step 4 — Local Full Verification (`Phase 4`):**
    - Jalankan `composer test`, `npm run check`, `npm run types:check`, dan `npm run build`.
5. **Step 5 — Deployment & Infrastructure Actions (Membutuhkan Persetujuan User Sesuai Aturan Task):**
    - Jangan deploy atau ubah konfigurasi produksi Railway (`regions`, `SESSION_DRIVER`, `DB_PERSISTENT`, push ke GitHub) sebelum Anda menyetujui implementasi dan deployment pasca-verifikasi lokal.
