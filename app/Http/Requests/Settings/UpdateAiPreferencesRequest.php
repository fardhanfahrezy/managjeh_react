<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ai_preferences' => ['required', 'array'],
            'ai_preferences.tone' => ['required', 'string', Rule::in(['concise', 'balanced', 'detailed'])],
            'ai_preferences.preferred_study_start' => ['required', 'date_format:H:i'],
            'ai_preferences.preferred_study_end' => ['required', 'date_format:H:i', 'after:ai_preferences.preferred_study_start'],
            'ai_preferences.default_focus_minutes' => ['required', 'integer', Rule::in([30, 45, 60, 90])],
            'ai_preferences.custom_instruction' => ['nullable', 'string', 'max:300'],
            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.daily_summary_enabled' => ['sometimes', 'boolean'],
            'notification_preferences.daily_summary_time' => ['sometimes', 'date_format:H:i'],
            'notification_preferences.weekly_summary_enabled' => ['sometimes', 'boolean'],
            'notification_preferences.weekly_summary_day' => ['sometimes', 'integer', 'between:0,6'],
            'notification_preferences.weekly_summary_time' => ['sometimes', 'date_format:H:i'],
            'notification_preferences.event_prep_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
