<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string $timezone
 * @property string|null $whatsapp_number
 * @property CarbonInterface|null $whatsapp_verified_at
 * @property bool $whatsapp_enabled
 * @property string|null $reminder_quiet_start
 * @property string|null $reminder_quiet_end
 * @property array<string, mixed>|null $ai_preferences
 * @property array<string, mixed>|null $notification_preferences
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
    'timezone',
    'whatsapp_number',
    'whatsapp_verified_at',
    'whatsapp_enabled',
    'reminder_quiet_start',
    'reminder_quiet_end',
    'ai_preferences',
    'notification_preferences',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'whatsapp_verified_at' => 'datetime',
            'whatsapp_enabled' => 'boolean',
            'ai_preferences' => 'array',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<CalendarEvent, $this>
     */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    /**
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * @return HasMany<AiPlannerRun, $this>
     */
    public function aiPlannerRuns(): HasMany
    {
        return $this->hasMany(AiPlannerRun::class);
    }

    /**
     * @return HasMany<AiChatConversation, $this>
     */
    public function aiChatConversations(): HasMany
    {
        return $this->hasMany(AiChatConversation::class);
    }

    /**
     * @return HasMany<AiChatMessage, $this>
     */
    public function aiChatMessages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class);
    }

    /**
     * @return HasMany<AiActionProposal, $this>
     */
    public function aiActionProposals(): HasMany
    {
        return $this->hasMany(AiActionProposal::class);
    }

    /**
     * @return array{
     *     tone: string,
     *     preferred_study_start: string,
     *     preferred_study_end: string,
     *     default_focus_minutes: int,
     *     custom_instruction: string|null,
     * }
     */
    public function resolvedAiPreferences(): array
    {
        $raw = is_array($this->ai_preferences) ? $this->ai_preferences : [];

        $tone = is_string($raw['tone'] ?? null) && in_array($raw['tone'], ['concise', 'balanced', 'detailed'], true)
            ? $raw['tone']
            : 'balanced';

        $studyStart = is_string($raw['preferred_study_start'] ?? null) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw['preferred_study_start'])
            ? $raw['preferred_study_start']
            : '19:00';

        $studyEnd = is_string($raw['preferred_study_end'] ?? null) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw['preferred_study_end'])
            ? $raw['preferred_study_end']
            : '22:00';

        $focusMinutes = is_numeric($raw['default_focus_minutes'] ?? null) && in_array((int) $raw['default_focus_minutes'], [30, 45, 60, 90], true)
            ? (int) $raw['default_focus_minutes']
            : 60;

        $customInstruction = is_string($raw['custom_instruction'] ?? null) && trim($raw['custom_instruction']) !== ''
            ? mb_substr(trim($raw['custom_instruction']), 0, 300)
            : null;

        return [
            'tone' => $tone,
            'preferred_study_start' => $studyStart,
            'preferred_study_end' => $studyEnd,
            'default_focus_minutes' => $focusMinutes,
            'custom_instruction' => $customInstruction,
        ];
    }

    /**
     * @return array{
     *     daily_summary_enabled: bool,
     *     daily_summary_time: string,
     *     weekly_summary_enabled: bool,
     *     weekly_summary_day: int,
     *     weekly_summary_time: string,
     *     event_prep_enabled: bool,
     * }
     */
    public function resolvedNotificationPreferences(): array
    {
        $raw = is_array($this->notification_preferences) ? $this->notification_preferences : [];

        $dailyTime = is_string($raw['daily_summary_time'] ?? null) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw['daily_summary_time'])
            ? $raw['daily_summary_time']
            : '07:00';

        $weeklyDay = is_numeric($raw['weekly_summary_day'] ?? null) && (int) $raw['weekly_summary_day'] >= 0 && (int) $raw['weekly_summary_day'] <= 6
            ? (int) $raw['weekly_summary_day']
            : 0;

        $weeklyTime = is_string($raw['weekly_summary_time'] ?? null) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw['weekly_summary_time'])
            ? $raw['weekly_summary_time']
            : '19:00';

        return [
            'daily_summary_enabled' => array_key_exists('daily_summary_enabled', $raw) ? (bool) $raw['daily_summary_enabled'] : true,
            'daily_summary_time' => $dailyTime,
            'weekly_summary_enabled' => array_key_exists('weekly_summary_enabled', $raw) ? (bool) $raw['weekly_summary_enabled'] : true,
            'weekly_summary_day' => $weeklyDay,
            'weekly_summary_time' => $weeklyTime,
            'event_prep_enabled' => array_key_exists('event_prep_enabled', $raw) ? (bool) $raw['event_prep_enabled'] : true,
        ];
    }

    public function canReceiveWhatsAppReminders(): bool
    {
        return $this->whatsapp_enabled
            && $this->whatsapp_number !== null
            && $this->whatsapp_number !== ''
            && $this->whatsapp_verified_at !== null;
    }
}
