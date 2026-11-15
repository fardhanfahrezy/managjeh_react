<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use Carbon\CarbonInterface;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $task_id
 * @property int|null $calendar_event_id
 * @property string $channel
 * @property string $reminder_type
 * @property CarbonInterface $scheduled_at
 * @property ReminderStatus $status
 * @property string $message
 * @property string|null $provider_message_id
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $failed_at
 * @property string|null $failure_reason
 * @property string $idempotency_key
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id',
    'task_id',
    'calendar_event_id',
    'channel',
    'reminder_type',
    'scheduled_at',
    'status',
    'message',
    'provider_message_id',
    'sent_at',
    'failed_at',
    'failure_reason',
    'idempotency_key',
])]
class Reminder extends Model
{
    /** @use HasFactory<ReminderFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'channel' => 'whatsapp',
        'reminder_type' => 'custom',
        'status' => ReminderStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReminderStatus::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
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
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<CalendarEvent, $this>
     */
    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }
}
