<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Project|null $project */
        $project = $this->route('project');

        return $project !== null && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', Rule::enum(ProjectStatus::class)],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];

        if ($this->filled('start_date')) {
            $rules['due_date'][] = 'after_or_equal:start_date';
        }

        return $rules;
    }
}
