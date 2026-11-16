<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date
 * @property CarbonImmutable|null $recurrence_until
 * @property array<int, string>|null $recurrence_exceptions
 */
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'project_id',
        'task_id',
        'parent_event_id',
        'recurrence_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'start_date',
        'end_date',
        'is_all_day',
        'is_cancelled',
        'recurrence_rule',
        'recurrence_until',
        'recurrence_exceptions',
    ];

    protected static function booted(): void
    {
        static::saving(function (CalendarEvent $event): void {
            if ($event->project_id !== null) {
                $projectOwnerId = Project::withTrashed()
                    ->whereKey($event->project_id)
                    ->value('user_id');

                if ($projectOwnerId !== null && (int) $projectOwnerId !== (int) $event->user_id) {
                    throw new InvalidArgumentException('Calendar event project_id must belong to the same user.');
                }
            }

            if ($event->task_id !== null) {
                $taskOwnerId = Task::withTrashed()
                    ->whereKey($event->task_id)
                    ->value('user_id');

                if ($taskOwnerId !== null && (int) $taskOwnerId !== (int) $event->user_id) {
                    throw new InvalidArgumentException('Calendar event task_id must belong to the same user.');
                }
            }

            if ($event->parent_event_id !== null) {
                if ((int) $event->parent_event_id === (int) $event->id) {
                    throw new InvalidArgumentException('A calendar event cannot be its own parent.');
                }

                $parent = self::withTrashed()->find($event->parent_event_id);

                if ($parent === null || (int) $parent->user_id !== (int) $event->user_id) {
                    throw new InvalidArgumentException('Calendar event parent_event_id must belong to the same user.');
                }

                if ($parent->parent_event_id !== null) {
                    throw new InvalidArgumentException('Single-occurrence overrides cannot be nested.');
                }
            }

            if ($event->is_all_day) {
                if ($event->start_date === null) {
                    throw new InvalidArgumentException('All-day events require start_date.');
                }

                if ($event->end_date === null) {
                    $event->end_date = $event->start_date;
                }

                if ($event->end_date->lt($event->start_date)) {
                    throw new InvalidArgumentException('All-day end_date must be on or after start_date.');
                }

                $event->starts_at = null;
                $event->ends_at = null;
            } else {
                if ($event->starts_at === null || $event->ends_at === null) {
                    throw new InvalidArgumentException('Timed calendar events require starts_at and ends_at.');
                }

                if ($event->ends_at->lte($event->starts_at)) {
                    throw new InvalidArgumentException('Timed calendar event ends_at must be after starts_at.');
                }

                $event->start_date = null;
                $event->end_date = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'is_all_day' => 'boolean',
            'is_cancelled' => 'boolean',
            'recurrence_until' => 'immutable_date',
            'recurrence_exceptions' => 'array',
        ];
    }

    /**
     * Restrict top-level events to those that can produce occurrences inside [rangeStartLocal, rangeEndLocal].
     *
     * @param  Builder<CalendarEvent>  $query
     * @return Builder<CalendarEvent>
     */
    public function scopeForDisplayRange(
        Builder $query,
        CarbonImmutable $rangeStartLocal,
        CarbonImmutable $rangeEndLocal,
    ): Builder {
        $rangeStartUtc = $rangeStartLocal->startOfDay()->utc();
        $rangeEndUtc = $rangeEndLocal->endOfDay()->utc();
        $rangeStartDate = $rangeStartLocal->format('Y-m-d');
        $rangeEndDate = $rangeEndLocal->format('Y-m-d');

        return $query
            ->whereNull('parent_event_id')
            ->where('is_cancelled', false)
            ->where(function (Builder $outer) use ($rangeStartUtc, $rangeEndUtc, $rangeStartDate, $rangeEndDate): void {
                $outer->where(function (Builder $recurring) use ($rangeEndUtc, $rangeStartDate, $rangeEndDate): void {
                    $recurring->whereNotNull('recurrence_rule')
                        ->where('recurrence_rule', '!=', '')
                        ->where(function (Builder $until) use ($rangeStartDate): void {
                            $until->whereNull('recurrence_until')
                                ->orWhere('recurrence_until', '>=', $rangeStartDate);
                        })
                        ->where(function (Builder $started) use ($rangeEndUtc, $rangeEndDate): void {
                            $started->where(function (Builder $allDay) use ($rangeEndDate): void {
                                $allDay->where('is_all_day', true)
                                    ->where('start_date', '<=', $rangeEndDate);
                            })->orWhere(function (Builder $timed) use ($rangeEndUtc): void {
                                $timed->where('is_all_day', false)
                                    ->where('starts_at', '<=', $rangeEndUtc);
                            });
                        });
                })->orWhere(function (Builder $standalone) use ($rangeStartUtc, $rangeEndUtc, $rangeStartDate, $rangeEndDate): void {
                    $standalone->where(function (Builder $noRule): void {
                        $noRule->whereNull('recurrence_rule')
                            ->orWhere('recurrence_rule', '');
                    })->where(function (Builder $overlap) use ($rangeStartUtc, $rangeEndUtc, $rangeStartDate, $rangeEndDate): void {
                        $overlap->where(function (Builder $allDay) use ($rangeStartDate, $rangeEndDate): void {
                            $allDay->where('is_all_day', true)
                                ->where('start_date', '<=', $rangeEndDate)
                                ->whereRaw('COALESCE(end_date, start_date) >= ?', [$rangeStartDate]);
                        })->orWhere(function (Builder $timed) use ($rangeStartUtc, $rangeEndUtc): void {
                            $timed->where('is_all_day', false)
                                ->where('starts_at', '<=', $rangeEndUtc)
                                ->where('ends_at', '>=', $rangeStartUtc);
                        });
                    });
                });
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
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<CalendarEvent, $this>
     */
    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    /**
     * @return HasMany<CalendarEvent, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_id');
    }
}
