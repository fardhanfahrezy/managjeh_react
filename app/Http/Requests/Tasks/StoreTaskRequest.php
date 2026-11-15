<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Task::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $userId = $user !== null ? $user->id : 0;

        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'required', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TaskPriority::class)],
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at'),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')
                    ->where('user_id', $userId)
                    ->whereNull('parent_id')
                    ->whereNull('deleted_at'),
            ],
            'due_at' => ['nullable', 'date'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Return validated attributes with due_at normalized from user timezone to UTC.
     *
     * @return array<string, mixed>
     */
    public function normalizedValidated(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        if (isset($validated['due_at']) && is_string($validated['due_at']) && $validated['due_at'] !== '') {
            $user = $this->user();
            $userTimezone = $user !== null ? $user->timezone : 'Asia/Jakarta';
            $validated['due_at'] = CarbonImmutable::parse($validated['due_at'], $userTimezone)->utc();
        }

        return $validated;
    }
}
