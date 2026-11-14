# Managjeh (Asisten AI Mahasiswa) — Panduan Utama AI Agent

- **Produk:** Asisten pribadi *single-user* untuk mahasiswa berbasis web dan pengingat WhatsApp dengan dukungan AI berbiaya Rp0 (Gemini utama, Groq & OpenRouter `:free` sebagai fallback, serta templat tetap tanpa AI).
- **Stack (`FACT`):** Laravel `13.42.0` (PHP `^8.3` / `8.5.12`), Inertia.js v3 (`3.7.0` / `@inertiajs/react` `^3.0.0`), React `19.2.0`, TypeScript `^5.7.2`, Tailwind CSS `v4`, Laravel Fortify `1.42.0` (2FA TOTP & Passkeys/WebAuthn), Laravel Wayfinder `0.1.23`, Vite+ (`vp` `1.4.0`), Pest `5.3.5`, Larastan `3.15.0` (level 7), Pint `1.32.3`.
- **Database (`FACT`):** PostgreSQL (`DB_CONNECTION=pgsql`), database aplikasi `managjeh`, database test di `phpunit.xml` `managjeh_test`. Seluruh timestamp disimpan dalam **UTC** (`config('app.timezone') === 'UTC'`) dan ditampilkan/dievaluasi dalam **`Asia/Jakarta`**.
- **Status Implementasi (`FACT`):**
  - `Implemented`: Fondasi autentikasi (Login, Register, Verify Email, Reset Password, 2FA, Passkeys), pengaturan profil/keamanan/tema, serta koneksi PostgreSQL dan tabel queue dasar (`jobs`, `failed_jobs`).
  - `Partially Implemented`: Halaman `/dashboard` (`resources/js/pages/dashboard.tsx` masih berisi `PlaceholderPattern`).
  - `Not Implemented` (`Planned`): Seluruh fitur domain `F1`–`F11` (tabel `tasks`, `schedules`, `reminders`, `habits`, `habit_logs`, `messages`, `ai_usage`, kolom preferensi WA/jam tenang di `users`, Laravel Scheduler pengingat, Queue Jobs pengiriman WA, integrasi WhatsApp Gateway & Webhook, serta `AiService`).

## Indeks Dokumentasi Proyek

- [README.md](README.md) — Overview produk Asisten AI Mahasiswa, status fitur `F1`–`F11`, setup, dan daftar perintah
- [CLAUDE.md](CLAUDE.md) — Instruksi ringkas untuk Claude Code (merujuk dokumen ini)
- [docs/PRD.md](docs/PRD.md) — Visi produk, kebutuhan fungsional `F1`–`F11`, roadmap 6 minggu, evaluasi risiko & technical gap analysis
- [docs/SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md) — Arsitektur aktual (`FACT`) vs arsitektur target (`REQUIREMENT`) beserta diagram Mermaid
- [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — Skema & ERD PostgreSQL aktual vs target (`users`, `tasks`, `schedules`, `reminders`, `habits`, `habit_logs`, `messages`, `ai_usage`)
- [docs/USER_FLOW.md](docs/USER_FLOW.md) — 14 alur pengguna & sistem (auth, tugas, jadwal, pengingat WA, `"selesai"`, `"tunda"`, jam tenang, AI, kebiasaan)
- [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) — Aturan bisnis aktual vs target (`F1`–`F11`, fallback AI, jam tenang, dan aturan privasi data)
- [docs/API_INTEGRATION.md](docs/API_INTEGRATION.md) — Route Inertia aktual serta spesifikasi integrasi WhatsApp Gateway, Webhook, Gemini, Groq, dan OpenRouter
- [docs/FRONTEND_ARCHITECTURE.md](docs/FRONTEND_ARCHITECTURE.md) — Struktur React 19 + Inertia v3 & rancangan halaman untuk `F1`–`F11`
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Kebutuhan deployment (Web, PostgreSQL, Queue Worker, Scheduler, HTTPS) & evaluasi hosting gratis
- [docs/SECURITY.md](docs/SECURITY.md) — Keamanan single-user, secret token webhook, validasi `nomor_wa`, dan sanitasi privasi AI
- [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) — Standar pengembangan (`AiService` fallback, Queue/Scheduler, webhook, transaksi, idempotency, timezone UTC/`Asia/Jakarta`, testing)

## Aturan Wajib Seluruh AI Agent

1. Baca `AGENTS.md` dan [CLAUDE.md](CLAUDE.md) sebelum mulai bekerja.
2. **Selalu baca [docs/PRD.md](docs/PRD.md) sebelum mengerjakan fitur apa pun**, dan gunakan ID requirement **`F1` s.d. `F11`** untuk melacak kebutuhan, kriteria penerimaan, serta pemetaan fitur yang dikerjakan.
3. **Prioritaskan MVP (`F1`–`F6`, Minggu 1–3)** sebelum mengerjakan fitur Tahap 2 (`F7`–`F10`, Minggu 4–5) atau Tahap 3 (`F11`, Minggu 6), kecuali diminta secara eksplisit oleh pengguna.
4. **Jangan mengembangkan fitur di luar ruang lingkup (*out of scope*)** seperti sistem multi-pengguna/multi-tenant, aplikasi mobile native, pembayaran, keuangan sederhana, timer fokus, atau impor otomatis jadwal kampus tanpa persetujuan eksplisit.
5. **Jangan mengasumsikan layanan eksternal sudah terintegrasi:** Saat ini WhatsApp Gateway, WhatsApp Webhook, Gemini API, Groq API, dan OpenRouter API **belum diimplementasikan (`Not Implemented`)**. Periksa kode aktual sebelum menulis atau memanggil service terkait.
6. Periksa struktur repository dan implementasi kode aktual sebelum melakukan perubahan apa pun.
7. Pahami pemisahan tanggung jawab antara Laravel Backend (routing, controller, FormRequest, Service, Queue Job, Scheduler, DB) dan React Frontend (UI, layout, form Inertia, TypeScript types).
8. Periksa skills (`.agents/skills/`), MCP servers (`.agents/mcp_config.json`), plugins, dan tools yang tersedia serta gunakan jika relevan.
9. Jangan mengarang struktur database, kontrak API gateway eksternal yang belum diverifikasi, fitur, atau business rules di luar yang ditetapkan di [docs/PRD.md](docs/PRD.md) dan [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md).
10. Jangan mengasumsikan library atau package tersedia tanpa memeriksa `composer.json` dan `package.json`.
11. Ikuti coding conventions dan standar arsitektur di [docs/DEVELOPMENT_GUIDELINES.md](docs/DEVELOPMENT_GUIDELINES.md) (termasuk penyimpanan waktu UTC, tampilan `Asia/Jakarta`, `AiService` fallback ke templat statis, dan larangan mengirim catatan harian/mood/`nomor_wa` ke AI).
12. Pastikan integrasi frontend dan backend tetap konsisten (props Inertia, Wayfinder routes/actions, validasi, auth).
13. Jalankan test yang relevan (`php artisan test`, `npm run types:check`) setelah perubahan kode.
14. Jalankan linting dan formatting (`composer lint`, `npm run check`) jika tersedia dan relevan.
15. Pastikan perubahan frontend tidak merusak kontrak endpoint/props backend, dan perubahan backend tidak merusak komponen/halaman frontend.
16. **Jaga konsistensi implementasi dengan dokumentasi:** Perbarui dokumen terkait di `README.md` dan `docs/` setiap kali status fitur `F1`–`F11`, skema database, business rules, user flow, atau arsitektur berubah.
17. Jangan mengubah konfigurasi, dependencies, atau arsitektur tanpa kebutuhan yang jelas dan persetujuan.
18. Jangan pernah menampilkan atau menuliskan secret, password, token webhook, atau API key ke dalam kode maupun dokumentasi.
19. Selalu laporkan ringkasan tugas, ID requirement (`F1`–`F11`) yang dikerjakan, file yang dibuat/diubah, alasan perubahan, serta hasil pengujian secara jujur.

## Dokumentasi sebagai Single Source of Truth

Gunakan dokumentasi di root dan `docs/` sebagai referensi utama, tetapi tetap verifikasi langsung kode aktual saat mengerjakan tugas. Bedakan selalu antara fakta implementasi (`FACT`) dan kebutuhan produk yang direncanakan (`REQUIREMENT` / `PLANNED`). Jika dokumentasi berbeda dengan kode aktual:
- Jangan langsung menganggap fitur yang berlabel `Planned` sudah terimplementasi di kode.
- Periksa implementasi aktual di repository.
- Identifikasi sumber ketidaksesuaian.
- Perbarui dokumentasi apabila status implementasi berubah.
- Jangan mengubah perilaku aplikasi di luar instruksi pengguna.

## Additional AI Agent Development Rules

- **Impact Analysis Before Coding:** Pahami tujuan perubahan dan ID requirement (`F1`–`F11`), identifikasi fitur terdampak, cek komponen React, endpoint/route, Controller/Service/Job/Request/Model, skema database, dan potensi efek samping sebelum menulis kode.
- **Frontend and Backend Consistency:** Jaga konsistensi request/response, TypeScript interfaces (`resources/js/types/`), aturan validasi, autentikasi, zona waktu (`UTC` di DB vs `Asia/Jakarta` di UI), dan relasi database.
- **Code Quality:** Ikuti konvensi proyek, gunakan kembali komponen/trait yang ada, hindari duplikasi kode dan hardcoded values, jaga separation of concerns, dan hindari abstraksi berlebihan.
- **Testing Before Completion:** Uji perubahan backend (Pest Unit/Feature tests dengan `Http::fake()` untuk AI/WhatsApp) dan frontend (`tsc --noEmit`, `vp check`), serta jangan pernah mengklaim test lulus jika belum dijalankan.
- **Documentation Synchronization:** Perbarui dokumen `docs/` terkait setiap kali fitur, API, database, business rules, user flow, arsitektur, keamanan, atau deployment berubah.
- **Scope Control:** Ubah hanya file yang relevan dengan tugas; hindari refactoring, instalasi package, atau perubahan format/konfigurasi yang tidak diminta.
- **Completion Report:** Akhiri setiap tugas dengan laporan yang mencakup: ringkasan tugas, file dibuat/diubah, detail implementasi, perubahan API/DB (jika ada), test yang dijalankan beserta hasilnya, pembaruan dokumentasi, serta keterbatasan dan `TODO`.

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
