<?php

namespace App\Models;

use App\Enums\AiPlannerRunStatus;
use Carbon\CarbonInterface;
use Database\Factories\AiPlannerRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property CarbonInterface $planning_date
 * @property string $trigger_type
 * @property string $provider
 * @property string|null $model
 * @property AiPlannerRunStatus $status
 * @property array<string, mixed>|null $input_snapshot
 * @property array<string, mixed>|null $result
 * @property string|null $error_message
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id',
    'planning_date',
    'trigger_type',
    'provider',
    'model',
    'status',
    'input_snapshot',
    'result',
    'error_message',
])]
class AiPlannerRun extends Model
{
    /** @use HasFactory<AiPlannerRunFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'trigger_type' => 'manual',
        'status' => AiPlannerRunStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planning_date' => 'date:Y-m-d',
            'status' => AiPlannerRunStatus::class,
            'input_snapshot' => 'array',
            'result' => 'array',
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
