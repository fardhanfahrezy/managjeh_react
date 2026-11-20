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

class UpdateScheduleTool implements McpMutationToolInterface
{
    public function __construct(
        private RecurrenceExpanderService $recurrenceExpander,
        private ScheduleConflictDetector $conflictDetector,
        private ReminderSynchronizer $reminderSynchronizer,
    ) {}

    public function name(): string
    {
        return 'update_schedule';
    }

    public function description(): string
    {
        return 'Mengusulkan pembaruan agenda kalender milik pengguna (judul, deskripsi, waktu mulai/selesai, proyek, tugas, atau aturan pengulangan). Perubahan tidak langsung disimpan sebelum disetujui pengguna.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => [
                    'type' => 'integer',
                    'description' => 'ID agenda kalender milik pengguna yang ingin diperbarui (wajib).',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Judul baru agenda (maksimal 200 karakter).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Deskripsi baru agenda.',
                ],
                'is_all_day' => [
                    'type' => 'boolean',
                    'description' => 'Ubah menjadi agenda seharian penuh atau berjadwal jam.',
                ],
                'starts_at' => [
                    'type' => 'string',
                    'description' => 'Waktu mulai lokal baru format YYYY-MM-DD HH:MM atau ISO 8601.',
                ],
                'ends_at' => [
                    'type' => 'string',
                    'description' => 'Waktu selesai lokal baru format YYYY-MM-DD HH:MM atau ISO 8601.',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Tanggal mulai baru format YYYY-MM-DD (untuk agenda seharian).',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'Tanggal selesai baru format YYYY-MM-DD (untuk agenda seharian).',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID proyek terkait milik pengguna.',
                ],
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'ID tugas terkait milik pengguna.',
                ],
                'recurrence_rule' => [
                    'type' => 'string',
                    'description' => 'Aturan pengulangan RRULE baru (kirim string kosong untuk menghapus pengulangan).',
                ],
                'recurrence_until' => [
                    'type' => 'string',
                    'description' => 'Tanggal batas pengulangan baru format YYYY-MM-DD.',
                ],
            ],
            'required' => ['event_id'],
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
        $resolved = $this->resolveAndValidate($user, $arguments);

        if ($resolved['error'] !== null || $resolved['event'] === null) {
            return [
                'valid' => false,
                'payload' => [],
                'conflict_preview' => [],
                'summary' => '',
                'error' => $resolved['error'] ?? 'Agenda kalender tidak ditemukan.',
            ];
        }

        $event = $resolved['event'];
        $payload = $resolved['payload'];
        $conflicts = [];

        if (! $resolved['effective_is_all_day'] && $resolved['effective_starts_at_utc'] !== null && $resolved['effective_ends_at_utc'] !== null) {
            $conflicts = $this->detectConflicts(
                $user,
                $resolved['effective_starts_at_utc'],
                $resolved['effective_ends_at_utc'],
                $event->id,
            );
        }

        return [
            'valid' => true,
            'payload' => $payload,
            'conflict_preview' => $conflicts,
            'summary' => sprintf('Perbarui agenda: %s', (string) ($payload['title'] ?? $event->title)),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(User $user, array $arguments): McpToolResult
    {
        $resolved = $this->resolveAndValidate($user, $arguments);

        if ($resolved['error'] !== null || $resolved['event'] === null) {
            return McpToolResult::failure($resolved['error'] ?? 'Agenda kalender tidak ditemukan.');
        }

        $event = $resolved['event'];
        Gate::forUser($user)->authorize('update', $event);

        $payload = $resolved['payload'];
        $updates = $payload;
        unset($updates['event_id'], $updates['current_title']);

        if ($resolved['effective_is_all_day']) {
            $updates['is_all_day'] = true;
            $updates['starts_at'] = null;
            $updates['ends_at'] = null;

            if (array_key_exists('start_date', $updates) && ! array_key_exists('end_date', $updates)) {
                $updates['end_date'] = $updates['start_date'];
            }
        } else {
            $updates['is_all_day'] = false;
            $updates['start_date'] = null;
            $updates['end_date'] = null;

            if (array_key_exists('starts_at', $updates)) {
                $updates['starts_at'] = $resolved['effective_starts_at_utc'];
            }

            if (array_key_exists('ends_at', $updates)) {
                $updates['ends_at'] = $resolved['effective_ends_at_utc'];
            }
        }

        $event->update($updates);
        $event->refresh();
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
            summary: sprintf('Agenda "%s" berhasil diperbarui.', $event->title),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     event: CalendarEvent|null,
     *     payload: array<string, mixed>,
     *     effective_is_all_day: bool,
     *     effective_starts_at_utc: CarbonImmutable|null,
     *     effective_ends_at_utc: CarbonImmutable|null,
     *     error: string|null,
     * }
     */
    private function resolveAndValidate(User $user, array $arguments): array
    {
        $eventId = isset($arguments['event_id']) && is_numeric($arguments['event_id'])
            ? (int) $arguments['event_id']
            : 0;

        if ($eventId <= 0) {
            return $this->invalid('Parameter event_id wajib diisi dengan angka valid.');
        }

        $event = CalendarEvent::find($eventId);

        if ($event === null || ! Gate::forUser($user)->allows('update', $event)) {
            return $this->invalid('Agenda kalender tidak ditemukan atau bukan milik Anda.');
        }

        $input = $arguments;
        if (array_key_exists('recurrence_rule', $input) && is_string($input['recurrence_rule']) && trim($input['recurrence_rule']) === '') {
            $input['recurrence_rule'] = null;
        }
        if (array_key_exists('recurrence_until', $input) && is_string($input['recurrence_until']) && trim($input['recurrence_until']) === '') {
            $input['recurrence_until'] = null;
        }

        $effectiveIsAllDay = array_key_exists('is_all_day', $input)
            ? filter_var($input['is_all_day'], FILTER_VALIDATE_BOOLEAN)
            : $event->is_all_day;

        $validator = Validator::make($input, [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_all_day' => ['sometimes', 'required', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('projects', 'id')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at'),
            ],
            'task_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at'),
            ],
            'recurrence_rule' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^FREQ=(DAILY|WEEKLY|MONTHLY)(;INTERVAL=\d+)?(;BYDAY=(MO|TU|WE|TH|FR|SA|SU)(,(MO|TU|WE|TH|FR|SA|SU))*)?$/i',
            ],
            'recurrence_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            $firstError = (string) collect($validator->errors()->all())->first();

            return $this->invalid($firstError !== '' ? $firstError : 'Data pembaruan agenda tidak valid.');
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        if ($validated === []) {
            return $this->invalid('Minimal satu perubahan field agenda harus disertakan.');
        }

        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $payload = [
            'event_id' => $event->id,
            'current_title' => $event->title,
        ];

        foreach (['title', 'description', 'project_id', 'task_id', 'recurrence_until'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $field === 'title' && is_string($validated[$field])
                    ? trim($validated[$field])
                    : $validated[$field];
            }
        }

        if (array_key_exists('is_all_day', $validated)) {
            $payload['is_all_day'] = $effectiveIsAllDay;
        }

        if (array_key_exists('recurrence_rule', $validated)) {
            $payload['recurrence_rule'] = is_string($validated['recurrence_rule']) && trim($validated['recurrence_rule']) !== ''
                ? strtoupper(trim($validated['recurrence_rule']))
                : null;
        }

        $effectiveStartsAtUtc = null;
        $effectiveEndsAtUtc = null;

        if ($effectiveIsAllDay) {
            $effectiveStartDate = isset($validated['start_date']) && is_string($validated['start_date'])
                ? $validated['start_date']
                : $event->start_date?->format('Y-m-d');
            $effectiveEndDate = isset($validated['end_date']) && is_string($validated['end_date'])
                ? $validated['end_date']
                : ($event->end_date?->format('Y-m-d') ?? $effectiveStartDate);

            if ($effectiveStartDate === null) {
                return $this->invalid('Agenda seharian memerlukan start_date format YYYY-MM-DD.');
            }

            if ($effectiveEndDate !== null && $effectiveEndDate < $effectiveStartDate) {
                return $this->invalid('Tanggal end_date harus sama dengan atau setelah start_date.');
            }

            if (array_key_exists('start_date', $validated)) {
                $payload['start_date'] = $effectiveStartDate;
            }
            if (array_key_exists('end_date', $validated)) {
                $payload['end_date'] = $effectiveEndDate;
            }
        } else {
            $effectiveStartsAtUtc = isset($validated['starts_at']) && is_string($validated['starts_at'])
                ? CarbonImmutable::parse($validated['starts_at'], $timezone)->utc()
                : ($event->starts_at !== null ? CarbonImmutable::instance($event->starts_at)->utc() : null);

            $effectiveEndsAtUtc = isset($validated['ends_at']) && is_string($validated['ends_at'])
                ? CarbonImmutable::parse($validated['ends_at'], $timezone)->utc()
                : ($event->ends_at !== null ? CarbonImmutable::instance($event->ends_at)->utc() : null);

            if ($effectiveStartsAtUtc === null || $effectiveEndsAtUtc === null) {
                return $this->invalid('Agenda berjadwal memerlukan starts_at dan ends_at.');
            }

            if ($effectiveEndsAtUtc->lte($effectiveStartsAtUtc)) {
                return $this->invalid('Waktu selesai (ends_at) harus lebih akhir daripada waktu mulai (starts_at).');
            }

            if (array_key_exists('starts_at', $validated)) {
                $payload['starts_at'] = $effectiveStartsAtUtc->setTimezone($timezone)->format('Y-m-d H:i');
            }
            if (array_key_exists('ends_at', $validated)) {
                $payload['ends_at'] = $effectiveEndsAtUtc->setTimezone($timezone)->format('Y-m-d H:i');
            }
        }

        return [
            'event' => $event,
            'payload' => $payload,
            'effective_is_all_day' => $effectiveIsAllDay,
            'effective_starts_at_utc' => $effectiveStartsAtUtc,
            'effective_ends_at_utc' => $effectiveEndsAtUtc,
            'error' => null,
        ];
    }

    /**
     * @return array{
     *     event: null,
     *     payload: array<string, mixed>,
     *     effective_is_all_day: false,
     *     effective_starts_at_utc: null,
     *     effective_ends_at_utc: null,
     *     error: string,
     * }
     */
    private function invalid(string $error): array
    {
        return [
            'event' => null,
            'payload' => [],
            'effective_is_all_day' => false,
            'effective_starts_at_utc' => null,
            'effective_ends_at_utc' => null,
            'error' => $error,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectConflicts(
        User $user,
        CarbonImmutable $startsAtUtc,
        CarbonImmutable $endsAtUtc,
        int $excludeEventId,
    ): array {
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $rangeStartLocal = $startsAtUtc->setTimezone($timezone)->startOfDay();
        $rangeEndLocal = $endsAtUtc->setTimezone($timezone)->endOfDay();

        $events = $user->calendarEvents()
            ->forDisplayRange($rangeStartLocal, $rangeEndLocal)
            ->get();

        $this->recurrenceExpander->loadRelationsForRange($events, $rangeStartLocal, $rangeEndLocal);

        $occurrences = $this->recurrenceExpander->expand($events, $rangeStartLocal, $rangeEndLocal, $timezone);

        return $this->conflictDetector->findConflictsForInterval(
            $startsAtUtc,
            $endsAtUtc,
            $occurrences,
            excludeEventId: $excludeEventId,
        );
    }
}
