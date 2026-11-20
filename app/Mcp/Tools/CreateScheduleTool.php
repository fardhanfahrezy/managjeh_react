<?php

namespace App\Mcp\Tools;

use App\Mcp\Contracts\McpMutationToolInterface;
use App\Mcp\McpToolResult;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Calendar\RecurrenceExpanderService;
use App\Services\Calendar\ScheduleConflictDetector;
use App\Services\Reminder\ReminderSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateScheduleTool implements McpMutationToolInterface
{
    public function __construct(
        private RecurrenceExpanderService $recurrenceExpander,
        private ScheduleConflictDetector $conflictDetector,
        private ReminderSynchronizer $reminderSynchronizer,
    ) {}

    public function name(): string
    {
        return 'create_schedule';
    }

    public function description(): string
    {
        return 'Mengusulkan pembuatan agenda kalender baru (jadwal belajar, kuliah, rapat, atau agenda berulang). Perubahan tidak langsung disimpan sebelum disetujui pengguna.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Judul agenda (wajib, maksimal 200 karakter).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Catatan atau deskripsi agenda (opsional).',
                ],
                'is_all_day' => [
                    'type' => 'boolean',
                    'description' => 'True jika agenda seharian penuh. Default false.',
                ],
                'starts_at' => [
                    'type' => 'string',
                    'description' => 'Waktu mulai lokal pengguna format YYYY-MM-DD HH:MM atau ISO 8601 (wajib jika is_all_day=false).',
                ],
                'ends_at' => [
                    'type' => 'string',
                    'description' => 'Waktu selesai lokal pengguna format YYYY-MM-DD HH:MM atau ISO 8601, harus setelah starts_at (wajib jika is_all_day=false).',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Tanggal mulai format YYYY-MM-DD (wajib jika is_all_day=true).',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'Tanggal selesai format YYYY-MM-DD (opsional jika is_all_day=true).',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID proyek terkait milik pengguna (opsional).',
                ],
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'ID tugas terkait milik pengguna (opsional).',
                ],
                'recurrence_rule' => [
                    'type' => 'string',
                    'description' => 'Aturan pengulangan RRULE opsional, mis. FREQ=WEEKLY;BYDAY=MO,WE,FR.',
                ],
                'recurrence_until' => [
                    'type' => 'string',
                    'description' => 'Tanggal batas pengulangan format YYYY-MM-DD (opsional).',
                ],
            ],
            'required' => ['title'],
        ];
    }

    public function isMutation(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     valid: bool,
     *     payload: array<string, mixed>,
     *     conflict_preview: array<int, array<string, mixed>>,
     *     summary: string,
     *     error: string|null,
     * }
     */
    public function preview(User $user, array $arguments): array
    {
        if (! Gate::forUser($user)->allows('create', CalendarEvent::class)) {
            return [
                'valid' => false,
                'payload' => [],
                'conflict_preview' => [],
                'summary' => '',
                'error' => 'Pengguna tidak memiliki izin membuat agenda kalender.',
            ];
        }

        $validated = $this->validate($user, $arguments);

        if ($validated['error'] !== null) {
            return [
                'valid' => false,
                'payload' => [],
                'conflict_preview' => [],
                'summary' => '',
                'error' => $validated['error'],
            ];
        }

        $payload = $validated['payload'];
        $conflicts = [];

        if (! ($payload['is_all_day'] ?? false) && $validated['starts_at_utc'] !== null && $validated['ends_at_utc'] !== null) {
            $conflicts = $this->detectConflicts($user, $validated['starts_at_utc'], $validated['ends_at_utc']);
        }

        return [
            'valid' => true,
            'payload' => $payload,
            'conflict_preview' => $conflicts,
            'summary' => sprintf('Buat agenda: %s', (string) $payload['title']),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(User $user, array $arguments): McpToolResult
    {
        Gate::forUser($user)->authorize('create', CalendarEvent::class);

        $validated = $this->validate($user, $arguments);

        if ($validated['error'] !== null) {
            return McpToolResult::failure($validated['error']);
        }

        $payload = $validated['payload'];
        $isAllDay = (bool) ($payload['is_all_day'] ?? false);

        $attributes = [
            'title' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'is_all_day' => $isAllDay,
            'starts_at' => $isAllDay ? null : $validated['starts_at_utc'],
            'ends_at' => $isAllDay ? null : $validated['ends_at_utc'],
            'start_date' => $isAllDay ? ($payload['start_date'] ?? null) : null,
            'end_date' => $isAllDay ? ($payload['end_date'] ?? $payload['start_date'] ?? null) : null,
            'project_id' => $payload['project_id'] ?? null,
            'task_id' => $payload['task_id'] ?? null,
            'recurrence_rule' => $payload['recurrence_rule'] ?? null,
            'recurrence_until' => $payload['recurrence_until'] ?? null,
        ];

        $event = $user->calendarEvents()->create($attributes);
        $this->reminderSynchronizer->syncForCalendarEvent($event);

        $timezone = $user->timezone ?: 'Asia/Jakarta';

        return McpToolResult::ok(
            data: [
                'id' => $event->id,
                'title' => $event->title,
                'is_all_day' => $event->is_all_day,
                'starts_at_local' => $event->starts_at?->utc()->setTimezone($timezone)->format('Y-m-d H:i'),
                'ends_at_local' => $event->ends_at?->utc()->setTimezone($timezone)->format('Y-m-d H:i'),
                'start_date' => $event->start_date?->format('Y-m-d'),
                'end_date' => $event->end_date?->format('Y-m-d'),
            ],
            targetType: 'calendar_event',
            targetId: $event->id,
            summary: sprintf('Agenda "%s" berhasil ditambahkan ke kalender.', $event->title),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     payload: array<string, mixed>,
     *     starts_at_utc: CarbonImmutable|null,
     *     ends_at_utc: CarbonImmutable|null,
     *     error: string|null,
     * }
     */
    private function validate(User $user, array $arguments): array
    {
        $input = $arguments;
        $isAllDay = filter_var($input['is_all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $input['is_all_day'] = $isAllDay;

        $endDateRules = ['nullable', 'date_format:Y-m-d'];
        if ($isAllDay) {
            $endDateRules[] = 'after_or_equal:start_date';
        }

        $validator = Validator::make($input, [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'is_all_day' => ['required', 'boolean'],
            'starts_at' => [$isAllDay ? 'nullable' : 'required', 'date'],
            'ends_at' => [$isAllDay ? 'nullable' : 'required', 'date', 'after:starts_at'],
            'start_date' => [$isAllDay ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'end_date' => $endDateRules,
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at'),
            ],
            'task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at'),
            ],
            'recurrence_rule' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^FREQ=(DAILY|WEEKLY|MONTHLY)(;INTERVAL=\d+)?(;BYDAY=(MO|TU|WE|TH|FR|SA|SU)(,(MO|TU|WE|TH|FR|SA|SU))*)?$/i',
            ],
            'recurrence_until' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            $firstError = (string) collect($validator->errors()->all())->first();

            return [
                'payload' => [],
                'starts_at_utc' => null,
                'ends_at_utc' => null,
                'error' => $firstError !== '' ? $firstError : 'Argumen agenda kalender tidak valid.',
            ];
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();
        $timezone = $user->timezone ?: 'Asia/Jakarta';

        $payload = [
            'title' => trim((string) $validated['title']),
            'is_all_day' => $isAllDay,
        ];

        if (array_key_exists('description', $validated) && $validated['description'] !== null) {
            $payload['description'] = (string) $validated['description'];
        }

        $startsAtUtc = null;
        $endsAtUtc = null;

        if ($isAllDay) {
            $payload['start_date'] = (string) $validated['start_date'];
            $payload['end_date'] = (string) ($validated['end_date'] ?? $validated['start_date']);
        } else {
            $startsAtLocal = CarbonImmutable::parse((string) $validated['starts_at'], $timezone)->setTimezone($timezone);
            $endsAtLocal = CarbonImmutable::parse((string) $validated['ends_at'], $timezone)->setTimezone($timezone);
            $startsAtUtc = $startsAtLocal->utc();
            $endsAtUtc = $endsAtLocal->utc();

            $payload['starts_at'] = $startsAtLocal->format('Y-m-d H:i');
            $payload['ends_at'] = $endsAtLocal->format('Y-m-d H:i');
        }

        if (isset($validated['project_id'])) {
            $payload['project_id'] = (int) $validated['project_id'];
        }

        if (isset($validated['task_id'])) {
            $payload['task_id'] = (int) $validated['task_id'];
        }

        if (isset($validated['recurrence_rule']) && is_string($validated['recurrence_rule']) && trim($validated['recurrence_rule']) !== '') {
            $payload['recurrence_rule'] = strtoupper(trim($validated['recurrence_rule']));
        }

        if (isset($validated['recurrence_until']) && is_string($validated['recurrence_until']) && trim($validated['recurrence_until']) !== '') {
            $payload['recurrence_until'] = trim($validated['recurrence_until']);
        }

        return [
            'payload' => $payload,
            'starts_at_utc' => $startsAtUtc,
            'ends_at_utc' => $endsAtUtc,
            'error' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectConflicts(User $user, CarbonImmutable $startsAtUtc, CarbonImmutable $endsAtUtc): array
    {
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $rangeStartLocal = $startsAtUtc->setTimezone($timezone)->startOfDay();
        $rangeEndLocal = $endsAtUtc->setTimezone($timezone)->endOfDay();

        $events = $user->calendarEvents()
            ->forDisplayRange($rangeStartLocal, $rangeEndLocal)
            ->get();

        $this->recurrenceExpander->loadRelationsForRange($events, $rangeStartLocal, $rangeEndLocal);

        $occurrences = $this->recurrenceExpander->expand($events, $rangeStartLocal, $rangeEndLocal, $timezone);

        return $this->conflictDetector->findConflictsForInterval($startsAtUtc, $endsAtUtc, $occurrences);
    }
}
