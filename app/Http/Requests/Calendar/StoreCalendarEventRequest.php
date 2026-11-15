<?php

namespace App\Http\Requests\Calendar;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CalendarEvent::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $userId = $user !== null ? $user->id : 0;
        $isAllDay = $this->boolean('is_all_day');
        $endDateRules = ['nullable', 'date_format:Y-m-d'];
        if ($isAllDay) {
            $endDateRules[] = 'after_or_equal:start_date';
        }

        return [
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
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at'),
            ],
            'task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at'),
            ],
            'recurrence_rule' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^FREQ=(DAILY|WEEKLY|MONTHLY)(;INTERVAL=\d+)?(;BYDAY=(MO|TU|WE|TH|FR|SA|SU)(,(MO|TU|WE|TH|FR|SA|SU))*)?$/i',
            ],
            'recurrence_until' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizedValidated(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $isAllDay = (bool) ($validated['is_all_day'] ?? false);
        $user = $this->user();
        $timezone = $user !== null ? $user->timezone : 'Asia/Jakarta';

        if ($isAllDay) {
            $validated['starts_at'] = null;
            $validated['ends_at'] = null;
            $validated['end_date'] = $validated['end_date'] ?? $validated['start_date'];
        } else {
            $validated['start_date'] = null;
            $validated['end_date'] = null;
            $validated['starts_at'] = CarbonImmutable::parse((string) $validated['starts_at'], $timezone)->utc();
            $validated['ends_at'] = CarbonImmutable::parse((string) $validated['ends_at'], $timezone)->utc();
        }

        if (isset($validated['recurrence_rule']) && is_string($validated['recurrence_rule'])) {
            $validated['recurrence_rule'] = strtoupper(trim($validated['recurrence_rule']));
            if ($validated['recurrence_rule'] === '') {
                $validated['recurrence_rule'] = null;
            }
        }

        return $validated;
    }
}
