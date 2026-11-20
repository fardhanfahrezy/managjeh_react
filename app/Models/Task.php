<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Carbon\CarbonInterface;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property string $uuid
 * @property int $version
 * @property int $user_id
 * @property int|null $project_id
 * @property int|null $parent_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $completed_at
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'uuid',
    'user_id',
    'project_id',
    'parent_id',
    'title',
    'description',
    'status',
    'priority',
    'due_at',
    'completed_at',
    'sort_order',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TaskStatus::Todo->value,
        'priority' => TaskPriority::Medium->value,
        'sort_order' => 0,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task): void {
            $task->uuid = $task->uuid ?: (string) Str::uuid();
            $task->version = max(1, (int) ($task->version ?? 1));
        });

        static::updating(function (Task $task): void {
            if ($task->getOriginal('uuid') !== null) {
                $task->uuid = (string) $task->getOriginal('uuid');
            }
            $task->version = ((int) $task->getOriginal('version', 1)) + 1;
        });

        static::saving(function (Task $task): void {
            if ($task->project_id !== null) {
                $project = Project::withTrashed()->find($task->project_id);

                if ($project === null || $project->user_id !== $task->user_id) {
                    throw ValidationException::withMessages([
                        'project_id' => 'The selected project does not belong to the authenticated user.',
                    ]);
                }
            }

            if ($task->parent_id !== null) {
                if ($task->exists && $task->parent_id === $task->id) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'A task cannot be a subtask of itself.',
                    ]);
                }

                $parent = Task::withTrashed()->find($task->parent_id);

                if ($parent === null || $parent->user_id !== $task->user_id) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'The parent task must belong to the same user.',
                    ]);
                }

                if ($parent->parent_id !== null) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'Subtasks cannot be nested more than one level deep.',
                    ]);
                }

                if ($task->exists && $task->subtasks()->exists()) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'A task that already has subtasks cannot become a subtask.',
                    ]);
                }
            }

            if ($task->status === TaskStatus::Completed) {
                $task->completed_at ??= now();
            } else {
                $task->completed_at = null;
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TaskStatus::Todo->value,
            TaskStatus::InProgress->value,
        ]);
    }

    public function dueAtInUserTimezone(?string $timezone = null): ?CarbonInterface
    {
        if ($this->due_at === null) {
            return null;
        }

        $targetTimezone = $timezone
            ?? ($this->relationLoaded('user') ? $this->user?->timezone : null)
            ?? 'Asia/Jakarta';

        return $this->due_at->copy()->setTimezone($targetTimezone);
    }
}
