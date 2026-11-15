<?php

namespace App\Http\Requests\Calendar;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CalendarEvent|null $event */
        $event = $this->route('calendar_event');

        return $event !== null && ($this->user()?->can('update', $event) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $userId = $user !== null ? $user->id : 0;
        /** @var CalendarEvent|null $event */
        $event = $this->route('calendar_event');
        $isAllDay = $this->has('is_all_day')
            ? $this->boolean('is_all_day')
            : ($event !== null ? $event->is_all_day : false);
        $endDateRules = ['sometimes', 'nullable', 'date_format:Y-m-d'];
        if ($isAllDay) {
            $endDateRules[] = 'after_or_equal:start_date';
        }

        return [
            'edit_scope' => ['sometimes', 'in:single,series'],
            'recurrence_id' => ['nullable', 'string', 'max:64'],
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_all_day' => ['sometimes', 'required', 'boolean'],
            'starts_at' => [$isAllDay ? 'nullable' : 'sometimes', 'required_if:is_all_day,false', 'nullable', 'date'],
            'ends_at' => [$isAllDay ? 'nullable' : 'sometimes', 'required_if:is_all_day,false', 'nullable', 'date', 'after:starts_at'],
            'start_date' => [$isAllDay ? 'sometimes' : 'nullable', 'required_if:is_all_day,true', 'nullable', 'date_format:Y-m-d'],
            'end_date' => $endDateRules,
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('projects', 'id')
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at'),
            ],
            'task_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')
                    ->where('user_id', $userId)
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizedValidated(CalendarEvent $existing): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        unset($validated['edit_scope'], $validated['recurrence_id']);

        $isAllDay = array_key_exists('is_all_day', $validated)
            ? (bool) $validated['is_all_day']
            : $existing->is_all_day;

        $user = $this->user();
        $timezone = $user !== null ? $user->timezone : 'Asia/Jakarta';

        if ($isAllDay) {
            $validated['starts_at'] = null;
            $validated['ends_at'] = null;

            if (array_key_exists('start_date', $validated) && ! array_key_exists('end_date', $validated)) {
                $validated['end_date'] = $validated['start_date'];
            }
        } else {
            $validated['start_date'] = null;
            $validated['end_date'] = null;

            if (isset($validated['starts_at']) && is_string($validated['starts_at'])) {
                $validated['starts_at'] = CarbonImmutable::parse($validated['starts_at'], $timezone)->utc();
            }

            if (isset($validated['ends_at']) && is_string($validated['ends_at'])) {
                $validated['ends_at'] = CarbonImmutable::parse($validated['ends_at'], $timezone)->utc();
            }
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
