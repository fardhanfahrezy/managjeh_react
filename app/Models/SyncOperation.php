<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $operation_id
 * @property string $entity_type
 * @property string $entity_uuid
 * @property string $status
 * @property array<string, mixed> $response_payload
 * @property CarbonImmutable $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SyncOperation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'operation_id',
        'entity_type',
        'entity_uuid',
        'status',
        'response_payload',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
