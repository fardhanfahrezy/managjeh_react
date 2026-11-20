<?php

namespace App\Models;

use App\Enums\AiChatRole;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property AiChatRole $role
 * @property string $content
 * @property string|null $provider
 * @property string|null $model
 * @property array<int, array<string, mixed>>|null $tool_calls_summary
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'conversation_id',
    'user_id',
    'role',
    'content',
    'provider',
    'model',
    'tool_calls_summary',
])]
class AiChatMessage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AiChatRole::class,
            'tool_calls_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AiActionProposal, $this>
     */
    public function actionProposals(): HasMany
    {
        return $this->hasMany(AiActionProposal::class, 'ai_chat_message_id');
    }
}
