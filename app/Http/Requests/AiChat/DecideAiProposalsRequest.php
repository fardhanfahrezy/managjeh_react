<?php

namespace App\Http\Requests\AiChat;

use App\Models\AiChatMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DecideAiProposalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        /** @var AiChatMessage|null $message */
        $message = $this->route('message');

        return $user !== null && $message !== null && $message->user_id === $user->id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'approved_proposal_ids' => ['sometimes', 'array'],
            'approved_proposal_ids.*' => ['integer'],
            'reject_all' => ['sometimes', 'boolean'],
        ];
    }
}
