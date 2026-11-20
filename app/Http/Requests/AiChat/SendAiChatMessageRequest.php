<?php

namespace App\Http\Requests\AiChat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendAiChatMessageRequest extends FormRequest
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
        $user = $this->user();
        $userId = $user !== null ? $user->id : 0;

        return [
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => [
                'nullable',
                'integer',
                Rule::exists('ai_chat_conversations', 'id')->where('user_id', $userId),
            ],
        ];
    }
}
