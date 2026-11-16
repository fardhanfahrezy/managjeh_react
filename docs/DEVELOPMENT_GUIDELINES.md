# Development Guidelines & Implementation Reference — Managjeh (MVP 1)

Dokumen ini merupakan **referensi implementasi aktual Phase 0 s.d. Phase 7** sekaligus **panduan pengembangan lanjutan (Extension Playbook)** yang dirancang secara eksplisit dan terperinci agar **AI Coding Agent** (termasuk model AI yang lebih ringan/hemat biaya) maupun developer baru dapat memahami, memelihara, dan memperluas **Managjeh** tanpa merusak arsitektur yang sudah berjalan.

---

## 1. Peta Lengkap File yang Sudah Berjalan (`FACT` — Jangan Buat Ulang!)

Seluruh komponen di bawah ini **sudah terimplementasi dan diuji oleh 82 test Pest (`551` assertions)**. Sebelum membuat class, fungsi, atau komponen baru, periksa daftar ini:

### 1.1 Contracts & Enums

- **Contracts (`app/Contracts/`):**
    - [WhatsAppGatewayInterface.php](../app/Contracts/WhatsAppGatewayInterface.php): `sendMessage($phoneNumber, $message, $idempotencyKey): WhatsAppSendResult`, `sendVerificationOtp($phoneNumber, $otpCode): WhatsAppSendResult`.
    - [AiPlannerProviderInterface.php](../app/Contracts/AiPlannerProviderInterface.php): `name(): string`, `model(): ?string`, `generatePlan(AiPlannerContext $context): AiPlannerProviderResponse`.
- **Enums (`app/Enums/`):**
    - [ProjectStatus.php](../app/Enums/ProjectStatus.php): `Active = 'active'`, `Completed = 'completed'`, `Archived = 'archived'`.
    - [TaskStatus.php](../app/Enums/TaskStatus.php): `Todo = 'todo'`, `InProgress = 'in_progress'`, `Completed = 'completed'`, `Cancelled = 'cancelled'` (`isActive(): bool`).
    - [TaskPriority.php](../app/Enums/TaskPriority.php): `Low = 'low'`, `Medium = 'medium'`, `High = 'high'`, `Urgent = 'urgent'`.
    - [ReminderStatus.php](../app/Enums/ReminderStatus.php): `Pending = 'pending'`, `Processing = 'processing'`, `Sent = 'sent'`, `Failed = 'failed'`, `Cancelled = 'cancelled'`.
    - [AiPlannerRunStatus.php](../app/Enums/AiPlannerRunStatus.php): `Pending = 'pending'`, `Success = 'success'`, `Failed = 'failed'`.

### 1.2 Models & Policies

- **Models (`app/Models/`):**
    - [User.php](../app/Models/User.php): relasi `projects()`, `tasks()`, `calendarEvents()`, `reminders()`, `aiPlannerRuns()`, `passkeys()`; helper `canReceiveWhatsAppReminders(): bool`.
    - [Project.php](../app/Models/Project.php): `SoftDeletes`, scope `withProgressCounts()`, method `calculateProgress(): int`.
    - [Task.php](../app/Models/Task.php): `SoftDeletes`, relasi `project()`, `parent()`, `subtasks()`, `reminders()`; scope `active()`; method `isActive(): bool`, `dueAtInUserTimezone(?string $timezone)`; validasi otomatis kepemilikan lintas-user & kedalaman subtugas maks. 1 tingkat (`D-09`) di `booted()`.
    - [CalendarEvent.php](../app/Models/CalendarEvent.php): `SoftDeletes`, relasi `project()`, `task()`, `parentEvent()`, `overrides()`, `reminders()`; validasi otomatis aturan `is_all_day` (`starts_at`/`ends_at` vs `start_date`/`end_date`) & kepemilikan relasi di `booted()`.
    - [Reminder.php](../app/Models/Reminder.php): relasi `user()`, `task()`, `calendarEvent()`.
    - [AiPlannerRun.php](../app/Models/AiPlannerRun.php): relasi `user()`, cast JSON `input_snapshot` & `result`.
- **Policies (`app/Policies/`):**
    - [ProjectPolicy.php](../app/Policies/ProjectPolicy.php), [TaskPolicy.php](../app/Policies/TaskPolicy.php), [CalendarEventPolicy.php](../app/Policies/CalendarEventPolicy.php).

### 1.3 Services, Jobs & Console Commands

- **Calendar Services (`app/Services/Calendar/`):**
    - [RecurrenceExpanderService.php](../app/Services/Calendar/RecurrenceExpanderService.php): `expandForRange($events, CarbonImmutable $rangeStartLocal, CarbonImmutable $rangeEndLocal, string $timezone)`.
    - [ScheduleConflictDetector.php](../app/Services/Calendar/ScheduleConflictDetector.php): `detectConflicts(array $expandedOccurrences)`.
- **Dashboard Service (`app/Services/`):**
    - [DashboardAggregatorService.php](../app/Services/DashboardAggregatorService.php): `aggregate(User $user): array`.
- **Reminder & WhatsApp Services (`app/Services/Reminder/` & `app/Services/WhatsApp/`):**
    - [QuietHoursAdjuster.php](../app/Services/Reminder/QuietHoursAdjuster.php): `adjust(CarbonImmutable $scheduledAtUtc, User $user): CarbonImmutable`.
    - [ReminderSynchronizer.php](../app/Services/Reminder/ReminderSynchronizer.php): `syncForTask(Task $task)`, `cancelPendingForTask(Task $task)`, `syncForCalendarEvent(CalendarEvent $event)`, `cancelPendingForCalendarEvent(CalendarEvent $event)`.
    - [FonnteWhatsAppGateway.php](../app/Services/WhatsApp/FonnteWhatsAppGateway.php), [HttpWhatsAppGateway.php](../app/Services/WhatsApp/HttpWhatsAppGateway.php), [NullWhatsAppGateway.php](../app/Services/WhatsApp/NullWhatsAppGateway.php), [WhatsAppSendResult.php](../app/Services/WhatsApp/WhatsAppSendResult.php).
- **AI Planner Services (`app/Services/AiPlanner/`):**
    - [AiPlannerService.php](../app/Services/AiPlanner/AiPlannerService.php): `generateForUser(User $user, string $triggerType = 'manual'): AiPlannerRun`, `approveReminder(User $user, int $taskId, string $remindAtLocal, string $reason): Reminder`.
    - [AiPromptBuilder.php](../app/Services/AiPlanner/AiPromptBuilder.php), [AiPlannerContext.php](../app/Services/AiPlanner/AiPlannerContext.php), [AiPlannerProviderResponse.php](../app/Services/AiPlanner/AiPlannerProviderResponse.php).
    - [GeminiPlannerProvider.php](../app/Services/AiPlanner/GeminiPlannerProvider.php), [GrokPlannerProvider.php](../app/Services/AiPlanner/GrokPlannerProvider.php), [OpenRouterPlannerProvider.php](../app/Services/AiPlanner/OpenRouterPlannerProvider.php), [OpenAiCompatiblePlannerProvider.php](../app/Services/AiPlanner/OpenAiCompatiblePlannerProvider.php), [FallbackPlannerProvider.php](../app/Services/AiPlanner/FallbackPlannerProvider.php).
- **Jobs & Commands (`app/Jobs/` & `app/Console/Commands/`):**
    - [SendWhatsAppMessageJob.php](../app/Jobs/SendWhatsAppMessageJob.php)
    - [ProvisionUserCommand.php](../app/Console/Commands/ProvisionUserCommand.php) (`user:provision`)
    - [DispatchDueRemindersCommand.php](../app/Console/Commands/DispatchDueRemindersCommand.php) (`reminders:dispatch-due`)
    - [GenerateDailyAiPlansCommand.php](../app/Console/Commands/GenerateDailyAiPlansCommand.php) (`ai-planner:generate-daily`)
    - [PruneRetentionDataCommand.php](../app/Console/Commands/PruneRetentionDataCommand.php) (`app:prune-retention-data`)

### 1.4 Controllers & Form Requests

- **Controllers (`app/Http/Controllers/`):**
    - [DashboardController.php](../app/Http/Controllers/DashboardController.php)
    - [ProjectController.php](../app/Http/Controllers/ProjectController.php)
    - [TaskController.php](../app/Http/Controllers/TaskController.php)
    - [CalendarEventController.php](../app/Http/Controllers/CalendarEventController.php)
    - [AiPlannerController.php](../app/Http/Controllers/AiPlannerController.php)
    - [Settings/ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php) (termasuk `export()`)
    - [Settings/WhatsAppVerificationController.php](../app/Http/Controllers/Settings/WhatsAppVerificationController.php)
    - [Settings/SecurityController.php](../app/Http/Controllers/Settings/SecurityController.php)
- **Form Requests (`app/Http/Requests/`):**
    - `Projects\StoreProjectRequest`, `Projects\UpdateProjectRequest`
    - `Tasks\StoreTaskRequest`, `Tasks\UpdateTaskRequest` (gunakan `$request->normalizedValidated()` untuk konversi `due_at` ke UTC)
    - `Calendar\StoreCalendarEventRequest`, `Calendar\UpdateCalendarEventRequest` (gunakan `$request->normalizedValidated()` untuk konversi waktu lokal ke UTC)
    - `Settings\ProfileUpdateRequest`, `Settings\ProfileDeleteRequest`, `Settings\PasswordUpdateRequest`, `Settings\TwoFactorAuthenticationRequest`

---

## 2. Pola Standar untuk Menambah Fitur atau Modul Baru (Playbook untuk AI Agent)

Apabila di masa mendatang Anda diminta menambahkan fitur baru atau memperluas modul yang ada, ikuti 7 langkah baku ini secara berurutan:

### Langkah 1: Migration & Model (`database/migrations/` & `app/Models/`)

1. Gunakan penamaan tabel dan kolom **Bahasa Inggris `snake_case`**.
2. Sertakan `foreignId('user_id')->constrained()->cascadeOnDelete()` pada setiap tabel milik pengguna.
3. Simpan semua `timestamp` dalam **UTC**, dan gunakan `SoftDeletes` jika data perlu dilindungi masa retensi (`D-11`). Jangan lupa daftarkan pembersihan `onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete()` di [PruneRetentionDataCommand.php](../app/Console/Commands/PruneRetentionDataCommand.php) dan tambahkan ke whitelist ekspor di [ProfileController.php](../app/Http/Controllers/Settings/ProfileController.php) bila relevan.
4. Definisikan atribut `#[Fillable([...])]` dan relasi Eloquent dengan return type eksplisit (`BelongsTo`, `HasMany`) agar lolos PHPStan Level 7.

### Langkah 2: Policy & FormRequest (`app/Policies/` & `app/Http/Requests/`)

1. Buat Policy yang memverifikasi `$user->id === $model->user_id`.
2. Buat FormRequest untuk `store` dan `update`. Jangan pernah menerima `user_id` dari input request.
3. Jika ada input tanggal/jam ber-timestamp dari pengguna, konversikan dari `$request->user()->timezone` ke UTC menggunakan `CarbonImmutable::parse($value, $timezone)->utc()`.

### Langkah 3: Controller & Routes (`app/Http/Controllers/` & `routes/web.php`)

1. Letakkan route di dalam grup `Route::middleware(['auth', 'verified'])->group(...)` di [routes/web.php](../routes/web.php).
2. Di dalam Controller:
    - Selalu panggil `Gate::authorize('view'|'update'|'delete', $model)` untuk resource spesifik.
    - Kembalikan halaman dengan `Inertia::render('nama-modul/index', [...])`.
    - Untuk aksi mutasi (`store`/`update`/`destroy`), gunakan `Inertia::flash('toast', ['type' => 'success', 'message' => '...'])` lalu `return back()` atau `return to_route(...)`.
3. Setelah menambah/mengubah route atau method controller, **wajib** jalankan:
    ```bash
    php artisan wayfinder:generate --with-form
    ```

### Langkah 4: TypeScript Types & React Page (`resources/js/`)

1. Tambahkan tipe data baru di [resources/js/types/models.ts](../resources/js/types/models.ts) dan ekspor ulang melalui [resources/js/types/index.ts](../resources/js/types/index.ts).
2. Buat halaman di `resources/js/pages/...` dengan struktur konsisten:
    ```tsx
    return (
        <>
            <Head title="Judul Halaman" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Konten halaman */}
            </div>
        </>
    );
    ```
3. Gunakan route/action helper dari `@/routes/*` atau `@/actions/*` (hasil generate Wayfinder), komponen UI dari `@/components/ui/*`, dan patuhi aturan visual **"The Quiet Desk"** di [DESIGN.md](../DESIGN.md) (`tabular-nums` untuk angka/waktu, `aria-label` pada seluruh tombol ikon).

### Langkah 5: Automated Feature Tests (`tests/Feature/`)

Buat test Pest yang menguji:

1. Pengguna terautentikasi dapat melakukan CRUD pada datanya sendiri.
2. Pengguna **ditolak (`403 Forbidden` atau `422 Unprocessable Content`)** saat mencoba mengakses atau mengaitkan data milik pengguna lain.
3. Konversi zona waktu (`users.timezone` <-> UTC) dan aturan bisnis spesifik berjalan akurat.

### Langkah 6: Verifikasi Kualitas Kode Wajib (`composer ci:check`)

Jalankan seluruh perintah berikut dan pastikan semuanya lulus tanpa error:

```bash
php vendor/bin/pint --dirty --format agent
php vendor/bin/phpstan analyse --memory-limit=512M
npx.cmd tsc --noEmit
npx.cmd vp check --fix
php artisan test
npm.cmd run build
```

### Langkah 7: Sinkronisasi Dokumentasi

Perbarui [README.md](../README.md), [AGENTS.md](../AGENTS.md), dan file terkait di `docs/` apabila ada penambahan route, tabel database, variabel `.env`, atau command CLI.
