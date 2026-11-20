<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use Carbon\CarbonInterface;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $version
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property ProjectStatus $status
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $due_date
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property int|null $completed_tasks_count
 * @property int|null $non_cancelled_tasks_count
 */
#[Fillable([
    'uuid',
    'user_id',
    'name',
    'description',
    'status',
    'start_date',
    'due_date',
    'completed_at',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ProjectStatus::Active->value,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->uuid = $project->uuid ?: (string) Str::uuid();
            $project->version = max(1, (int) ($project->version ?? 1));
        });

        static::updating(function (Project $project): void {
            if ($project->getOriginal('uuid') !== null) {
                $project->uuid = (string) $project->getOriginal('uuid');
            }
            $project->version = ((int) $project->getOriginal('version', 1)) + 1;
        });

        static::saving(function (Project $project): void {
            if ($project->status === ProjectStatus::Completed) {
                $project->completed_at ??= now();
            } else {
                $project->completed_at = null;
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeWithProgressCounts(Builder $query): Builder
    {
        return $query->withCount([
            'tasks as completed_tasks_count' => function (Builder $taskQuery): void {
                $taskQuery->where('status', TaskStatus::Completed->value);
            },
            'tasks as non_cancelled_tasks_count' => function (Builder $taskQuery): void {
                $taskQuery->where('status', '!=', TaskStatus::Cancelled->value);
            },
        ]);
    }

    /**
     * Calculate project progress percentage based on completed tasks vs non-cancelled tasks.
     */
    public function calculateProgress(): int
    {
        if (isset($this->completed_tasks_count, $this->non_cancelled_tasks_count)) {
            $total = (int) $this->non_cancelled_tasks_count;
            $completed = (int) $this->completed_tasks_count;
        } elseif ($this->relationLoaded('tasks')) {
            $nonCancelled = $this->tasks->filter(
                fn (Task $task): bool => $task->status !== TaskStatus::Cancelled,
            );
            $total = $nonCancelled->count();
            $completed = $nonCancelled->filter(
                fn (Task $task): bool => $task->status === TaskStatus::Completed,
            )->count();
        } else {
            $total = $this->tasks()
                ->where('status', '!=', TaskStatus::Cancelled->value)
                ->count();

            $completed = $total > 0
                ? $this->tasks()->where('status', TaskStatus::Completed->value)->count()
                : 0;
        }

        if ($total === 0) {
            return 0;
        }

        return (int) round(($completed / $total) * 100);
    }
}
