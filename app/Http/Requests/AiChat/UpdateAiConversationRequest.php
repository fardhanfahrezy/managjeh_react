<?php

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AiChatConversation|null $conversation */
        $conversation = $this->route('conversation');

        return $conversation !== null && ($this->user()?->can('update', $conversation) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:60'],
        ];
    }
}
