<?php

namespace Database\Factories;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'task_id' => null,
            'calendar_event_id' => null,
            'channel' => 'whatsapp',
            'reminder_type' => 'custom',
            'scheduled_at' => now()->addHour(),
            'status' => ReminderStatus::Pending,
            'message' => 'Pengingat tugas: '.$this->faker->sentence(4),
            'idempotency_key' => 'test:'.Str::uuid()->toString(),
        ];
    }
}
