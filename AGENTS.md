# Managjeh (Personal OS Mahasiswa) — Panduan Utama AI Agent

- **Produk:** Aplikasi web **Personal OS Mahasiswa** (*single-user sekarang, arsitektur siap multi-user*) untuk mengelola **Task**, **Project**, **Calendar (termasuk agenda berulang)**, **Dashboard**, **Pengingat WhatsApp**, dan **AI Planner** (rekomendasi 3 prioritas utama harian).
- **Stack Aktual (`FACT`):** Laravel `13.42.0` (PHP `^8.3` / `8.5.12`), Inertia.js v3 (`3.7.0` / `@inertiajs/react` `^3.0.0`), React `19.2.0`, TypeScript `^5.7.2`, Tailwind CSS `v4`, Laravel Fortify `1.42.0` (2FA TOTP & Passkeys/WebAuthn, registrasi publik **dinonaktifkan** sesuai `D-01`), Laravel Wayfinder `0.1.23`, Vite+ (`vp` `1.4.0`), Pest `5.3.5`, Larastan `3.15.0` (level 7), Pint `1.32.3`.
- **Database & Timezone (`FACT`):**
    - PostgreSQL (`DB_CONNECTION=pgsql`), database aplikasi `managjeh`, database pengujian `managjeh_test` (`phpunit.xml`).
    - Seluruh nama tabel dan kolom menggunakan **Bahasa Inggris `snake_case`**.
    - Semua timestamp absolut disimpan dalam **UTC** (`config('app.timezone') === 'UTC'`, `CarbonImmutable` aktif).
    - Timezone bersifat **user-configurable** pada kolom `users.timezone` (IANA timezone, default `'Asia/Jakarta'`). Tanggal agenda *all-day* disimpan sebagai `date` lokal.

---

## Status Implementasi Saat Ini (`FACT` per Phase)

| Phase | Cakupan | Status Aktual (`FACT`) | Detail Implementasi di Repository |
| :--- | :--- | :--- | :--- |
| **Phase 0** | Repository Audit & Baseline | `Implemented` | Audit struktur, sinkronisasi spesifikasi MVP 1 & keputusan `D-01`–`D-15`. |
| **Phase 1** | Core Data (`users`, `projects`, `tasks`, Auth `D-01`, Policy, Request, Test) | `Implemented` | 8 migrasi aktif, model `User`, `Project`, `Task`, enum `ProjectStatus`, `TaskStatus`, `TaskPriority`, `ProjectPolicy`, `TaskPolicy`, FormRequests, command `php artisan user:provision`, dan **57 Pest tests lulus**. |
| **Phase 2** | Task & Project UI + Settings Timezone/WA | `Not Implemented` (`Planned`) | Perlu membuat `ProjectController`, `TaskController`, routes, dan halaman React Inertia (`tasks/*`, `projects/*`, perluasan `settings/profile.tsx`). |
| **Phase 3** | Calendar & Agenda Berulang (`D-08`) | `Not Implemented` (`Planned`) | Perlu migrasi `calendar_events` (beserta recurrence rule & exception `D-08`), model `CalendarEvent`, `CalendarEventPolicy`, controller, dan halaman `calendar/index.tsx`. |
| **Phase 4** | Dashboard Aggregation | `Partially Implemented` | Route `/dashboard` & `resources/js/pages/dashboard.tsx` masih berisi `PlaceholderPattern`; perlu `DashboardController` dan UI 8 komponen utama. |
| **Phase 5** | WhatsApp Reminder & OTP Verification (`D-02`, `D-05`–`D-07`, `D-14`) | `Not Implemented` (`Planned`) | Perlu migrasi `reminders`, model `Reminder`, `WhatsAppGatewayInterface`, `ReminderService` (quiet hours `00:00–05:00`), `SendWhatsAppMessageJob`, Scheduler, dan OTP verification. |
| **Phase 6** | AI Planner (`Gemini → Grok → OpenRouter → Fallback`, `D-03`, `D-04`, `D-12`) | `Not Implemented` (`Planned`) | Perlu migrasi `ai_planner_runs`, `AiPlannerProviderInterface`, provider adapters (`Gemini`, `Grok`, `OpenRouter`, `Fallback`), `AiPlannerService`, Scheduler/trigger otomatis, dan halaman `ai-planner/index.tsx`. |
| **Phase 7** | Hardening, Retensi Log (`D-11`, `D-15`), Regression & Deployment | `Not Implemented` (`Planned`) | Perlu command retensi/cleanup log & soft-delete, rate limiter endpoint AI/WA, ekspor data, dan regresi menyeluruh. |

---

## Ringkasan Keputusan Final (`D-01` s.d. `D-15`) — WAJIB DIIKUTI

| ID | Keputusan Final (Mengikat Seluruh Implementasi) |
| :--- | :--- |
| **`D-01`** | **Registrasi publik dinonaktifkan** (`Features::registration()` dihapus dari `config/fortify.php`). Akun dibuat melalui provisioning manual (`php artisan user:provision`) atau undangan administrator. |
| **`D-02`** | **WhatsApp Gateway pihak ketiga berbasis API** melalui kontrak `WhatsAppGatewayInterface` (adapter pattern) agar vendor mudah diganti. WhatsApp pada MVP **hanya kanal pengiriman notifikasi** (pengelolaan data dilakukan di website). |
| **`D-03`** | **Urutan fallback AI Planner:** `Gemini` (utama) → `Grok` (cadangan 1) → `OpenRouter` (cadangan 2) → `Rule-based Fallback` (deterministik tanpa AI). |
| **`D-04`** | **Model AI configurable** melalui `.env` dan `config/services.php` (`GEMINI_MODEL`, `GROK_MODEL`, `OPENROUTER_MODEL`). |
| **`D-05`** | **Multi-reminder per task:** Satu tugas dapat memiliki beberapa pengingat (mis. H-1, H-1 jam, atau pengingat tambahan sesuai urgensi). Pengingat yang tidak lagi relevan wajib dibatalkan (`cancelled`). |
| **`D-06`** | **Quiet hours default `00:00–05:00`** berdasarkan `users.timezone`. Pengingat yang jatuh pada jam tenang ditunda ke waktu pertama yang diizinkan (`05:00` waktu lokal pengguna) selama masih relevan. |
| **`D-07`** | **Penjadwalan pengingat cerdas:** Backend menghitung slot waktu kosong dari kalender; AI membantu merekomendasikan waktu/konteks pengingat dan prioritas berdasarkan deadline, prioritas, estimasi durasi, dan ketersediaan waktu. |
| **`D-08`** | **Agenda berulang masuk MVP:** Tabel `calendar_events` mendukung *recurrence rule* (`rrule` / frekuensi), pengubahan satu kejadian (*single occurrence override* via `parent_event_id` & `recurrence_id`), seluruh rangkaian, pengecualian (`recurrence_exceptions`), serta pembatalan kejadian secara idempotent. |
| **`D-09`** | **Subtugas maksimal 1 tingkat:** Tabel `tasks` memiliki `parent_id` (`nullable`). Backend (`Task` model & FormRequest) memvalidasi agar subtugas tidak memiliki subtugas lagi (maksimal kedalaman = 1). UI subtugas dapat dibangun setelah fitur task utama stabil. |
| **`D-10`** | **Kalender internal sebagai sumber utama:** Tidak ada integrasi Google Calendar pada MVP, namun struktur model tidak menghambat penambahan kolom sinkronisasi eksternal di masa depan. |
| **`D-11`** | **Soft delete (`deleted_at`):** Digunakan pada `projects`, `tasks`, dan `calendar_events`. Penghapusan permanen dilakukan melalui kebijakan retensi terjadwal. |
| **`D-12`** | **AI Planner otomatis & manual:** Berjalan otomatis melalui Scheduler harian serta pemicu perubahan data relevan (tugas/agenda berubah signifikan), dan dapat dipicu manual oleh pengguna. **AI dilarang mengubah task, project, atau agenda tanpa persetujuan pengguna.** |
| **`D-13`** | **Reliabilitas AI tanpa batas kuota harian buatan:** Tidak membatasi jumlah pemakaian pribadi secara artifisial, tetapi **wajib** menerapkan timeout, retry terbatas, rate-limit handling, fallback provider berjenjang, pencatatan pemakaian/biaya di `ai_planner_runs`, dan *circuit breaker* (via Laravel Cache). |
| **`D-14`** | **Verifikasi nomor WhatsApp wajib:** Menggunakan OTP (atau verifikasi gateway). Pesan pengingat **dilarang dikirim** jika `whatsapp_enabled === false`, `whatsapp_number === null`, atau `whatsapp_verified_at === null` (gunakan `$user->canReceiveWhatsAppReminders()`). Mengubah nomor WA otomatis mereset `whatsapp_verified_at = null`. |
| **`D-15`** | **Minimisasi data & retensi log:** Jangan pernah menyimpan OTP mentah, API key, kredensial, atau isi percakapan sensitif di log/database. Simpan metadata minimum di `reminders` dan `ai_planner_runs` dengan jadwal pembersihan otomatis. |

---

## Indeks Dokumentasi Proyek (`Single Source of Truth`)

1. [README.md](README.md) — Overview Personal OS Mahasiswa, status Phase 0–7, setup, provisioning user, dan daftar perintah.
2. [CLAUDE.md](CLAUDE.md) — Ringkasan eksekutif untuk AI coding agent.
3. [docs/PRD.md](docs/PRD.md) — Spesifikasi Teknis MVP 1 lengkap, cakupan domain, keputusan `D-01`–`D-15`, roadmap Phase 0–7, dan Acceptance Criteria.
4. [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — Skema PostgreSQL aktual (`Phase 1 Implemented`) & spesifikasi migrasi detail untuk `calendar_events`, `reminders`, dan `ai_planner_runs`.
5. [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — Aturan bisnis detail untuk Task, Project, Calendar (termasuk recurrence `D-08`), Quiet Hours (`00:00–05:00`), WhatsApp Reminder, dan AI Planner.
6. [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) — Arsitektur modular Laravel 13 + Inertia v3 + Queue/Scheduler + Provider/Gateway contracts.
7. [docs/FRONTEND_ARCHITECTURE.md](docs/FRONTEND_ARCHITECTURE.md) — Rancangan 8 halaman MVP, TypeScript interfaces, kontrak Inertia props, dan panduan PAGE ARCHITECT.
8. [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md) — Daftar route Inertia, spesifikasi `WhatsAppGatewayInterface`, `AiPlannerProviderInterface`, dan skema JSON output AI Planner.
9. [docs/USER_FLOW.md](docs/USER_FLOW.md) — Diagram alur pengguna & sistem untuk seluruh modul MVP 1.
10. [docs/SECURITY.md](docs/SECURITY.md) — Otorisasi kepemilikan (`user_id`), proteksi webhook, sanitasi prompt AI, dan privasi data.
11. [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Konfigurasi produksi, Queue Worker, Scheduler, dan evaluasi hosting.
12. [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) — Panduan implementasi langkah demi langkah untuk Phase 2 s.d. Phase 7 bagi AI Agent.

---

## Aturan Wajib Seluruh AI Agent

1. **Baca dokumen sebelum menulis kode:** Selalu baca `AGENTS.md`, `docs/PRD.md`, `docs/DATABASE_SCHEMA.md`, dan `docs/DEVELOPMENT_GUIDELINES.md` sebelum memulai tahap apa pun.
2. **Kerjakan bertahap sesuai urutan Phase (`Phase 2` → `Phase 3` → `Phase 4` → `Phase 5` → `Phase 6` → `Phase 7`):** Jangan melompati tahap fondasi dan jangan mengklaim fitur selesai hanya karena halaman berhasil dirender.
3. **Jaga batas ruang lingkup MVP 1:** Jangan membangun fitur di luar MVP (keuangan, habit tracker, mood/tidur, catatan kuliah, karier, kontak, jurnal mingguan, Google Calendar eksternal, atau perintah perubahan data via balasan WhatsApp).
4. **Gunakan penamaan Bahasa Inggris `snake_case`:** Seluruh tabel (`users`, `projects`, `tasks`, `calendar_events`, `reminders`, `ai_planner_runs`), kolom, model, controller, dan route wajib menggunakan Bahasa Inggris sesuai `docs/DATABASE_SCHEMA.md`. Jangan gunakan nama tabel/kolom lama seperti `schedules`, `judul`, `tenggat`, atau `kirim_pada`.
5. **Hormati kode Phase 1 yang sudah ada:**
    - Gunakan model [User.php](app/Models/User.php), [Project.php](app/Models/Project.php), dan [Task.php](app/Models/Task.php) beserta enum [ProjectStatus.php](app/Enums/ProjectStatus.php), [TaskStatus.php](app/Enums/TaskStatus.php), dan [TaskPriority.php](app/Enums/TaskPriority.php).
    - Gunakan [ProjectPolicy.php](app/Policies/ProjectPolicy.php), [TaskPolicy.php](app/Policies/TaskPolicy.php), serta FormRequest di `app/Http/Requests/Projects/` dan `app/Http/Requests/Tasks/`.
6. **Keamanan & Kepemilikan Data (`user_id`):**
    - Jangan pernah mempercayai `user_id` dari request frontend. Ambil pengguna dari `$request->user()`.
    - Seluruh query wajib dibatasi pada data milik `$request->user()->id`.
    - Gunakan Policy (`$this->authorize(...)` atau `Gate::authorize(...)`) pada setiap aksi controller.
7. **Zona Waktu (`UTC` di DB vs `users.timezone` di UI):**
    - Simpan semua `timestamp` (`due_at`, `completed_at`, `starts_at`, `ends_at`, `scheduled_at`, `sent_at`, `failed_at`) dalam **UTC**.
    - Simpan tanggal kalender (`start_date`, `due_date`, `end_date`, `planning_date`) sebagai `date` (`Y-m-d`) sesuai kalender lokal pengguna.
    - Gunakan `$user->timezone` (default `'Asia/Jakarta'`) saat mengonversi input waktu lokal ke UTC dan saat mengevaluasi "hari ini" atau jam tenang (`00:00–05:00`).
8. **Verifikasi Wajib Sebelum Menyelesaikan Tugas:**
    - Jalankan `php artisan test` (semua test lama dan baru wajib hijau).
    - Jalankan `php vendor/bin/phpstan analyse --memory-limit=512M` (wajib 0 error pada level 7).
    - Jalankan `php vendor/bin/pint --dirty --format agent`.
    - Jalankan `npx.cmd tsc --noEmit` (atau `npm run types:check`).
9. **Format Laporan Setiap Tahap:**
    Setiap selesai mengerjakan satu tahap, laporkan: (1) Tujuan, (2) File yang dibuat, (3) File yang diubah, (4) Keputusan teknis, (5) Migration, (6) Test yang dijalankan & hasilnya, (7) Risiko/keputusan terbuka, dan (8) Pekerjaan yang belum selesai.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
