<?php

namespace App\Models;

use App\Enums\AiActionProposalStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $conversation_id
 * @property int $ai_chat_message_id
 * @property string $tool_name
 * @property array<string, mixed> $payload
 * @property array<int, array<string, mixed>>|null $conflict_preview
 * @property AiActionProposalStatus $status
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $decided_at
 * @property CarbonInterface|null $executed_at
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $result_summary
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id',
    'conversation_id',
    'ai_chat_message_id',
    'tool_name',
    'payload',
    'conflict_preview',
    'status',
    'expires_at',
    'decided_at',
    'executed_at',
    'target_type',
    'target_id',
    'result_summary',
])]
class AiActionProposal extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => AiActionProposalStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'conflict_preview' => 'array',
            'status' => AiActionProposalStatus::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AiChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<AiChatMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(AiChatMessage::class, 'ai_chat_message_id');
    }

    public function isExpired(): bool
    {
        return $this->status === AiActionProposalStatus::Expired
            || ($this->status === AiActionProposalStatus::Pending && $this->expires_at->isPast());
    }

    public function isPendingAndActive(): bool
    {
        return $this->status === AiActionProposalStatus::Pending && $this->expires_at->isFuture();
    }
}
