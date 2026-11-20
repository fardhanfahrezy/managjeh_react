<?php

namespace App\Http\Requests\Sync;

use Illuminate\Foundation\Http\FormRequest;

class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operations' => ['required', 'array', 'min:1', 'max:50'],
            'operations.*.operation_id' => ['required', 'uuid'],
            'operations.*.entity_type' => ['required', 'string', 'in:project,task,calendar_event'],
            'operations.*.entity_uuid' => ['required', 'uuid'],
            'operations.*.action' => ['required', 'string', 'in:upsert,delete'],
            'operations.*.base_version' => ['required', 'integer', 'min:0'],
            'operations.*.changed_fields' => ['sometimes', 'array'],
            'operations.*.changed_fields.*' => ['string', 'max:64'],
            'operations.*.base_snapshot' => ['nullable', 'array'],
            'operations.*.payload' => ['present', 'array'],
            'operations.*.force_overwrite' => ['sometimes', 'boolean'],
        ];
    }
}
