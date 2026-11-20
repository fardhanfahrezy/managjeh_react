<?php

use App\Enums\AiActionProposalStatus;
use App\Enums\AiChatRole;
use App\Models\AiActionProposal;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiCircuitBreaker;
use App\Services\AiPlanner\AiPlannerService;
use App\Services\Calendar\FreeSlotCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('user preference helpers return safe defaults and normalize stored jsonb values', function () {
    $user = User::factory()->create();

    expect($user->resolvedAiPreferences())->toBe([
        'tone' => 'balanced',
        'preferred_study_start' => '19:00',
        'preferred_study_end' => '22:00',
        'default_focus_minutes' => 60,
        'custom_instruction' => null,
    ])->and($user->resolvedNotificationPreferences())->toBe([
        'daily_summary_enabled' => true,
        'daily_summary_time' => '07:00',
        'weekly_summary_enabled' => true,
        'weekly_summary_day' => 0,
        'weekly_summary_time' => '19:00',
        'event_prep_enabled' => true,
    ]);

    $user->update([
        'ai_preferences' => [
            'tone' => 'concise',
            'preferred_study_start' => '20:00',
            'preferred_study_end' => '23:00',
            'default_focus_minutes' => 90,
            'custom_instruction' => '  Fokus pada tugas praktikum dulu.  ',
        ],
        'notification_preferences' => [
            'daily_summary_enabled' => false,
            'daily_summary_time' => '06:30',
            'weekly_summary_enabled' => true,
            'weekly_summary_day' => 6,
            'weekly_summary_time' => '20:00',
            'event_prep_enabled' => false,
        ],
    ]);

    $fresh = $user->fresh();

    expect($fresh?->resolvedAiPreferences())->toBe([
        'tone' => 'concise',
        'preferred_study_start' => '20:00',
        'preferred_study_end' => '23:00',
        'default_focus_minutes' => 90,
        'custom_instruction' => 'Fokus pada tugas praktikum dulu.',
    ])->and($fresh?->resolvedNotificationPreferences())->toBe([
        'daily_summary_enabled' => false,
        'daily_summary_time' => '06:30',
        'weekly_summary_enabled' => true,
        'weekly_summary_day' => 6,
        'weekly_summary_time' => '20:00',
        'event_prep_enabled' => false,
    ]);
});

test('ai chat conversation and proposal policies enforce strict user ownership', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $conversation = AiChatConversation::query()->create([
        'user_id' => $owner->id,
        'title' => 'Jadwal Belajar Minggu Ini',
        'last_message_at' => now(),
    ]);

    $message = AiChatMessage::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $owner->id,
        'role' => AiChatRole::Assistant,
        'content' => 'Berikut rencana jadwal belajar.',
    ]);

    $proposal = AiActionProposal::query()->create([
        'user_id' => $owner->id,
        'conversation_id' => $conversation->id,
        'ai_chat_message_id' => $message->id,
        'tool_name' => 'create_schedule',
        'payload' => ['title' => 'Belajar PBO'],
        'status' => AiActionProposalStatus::Pending,
        'expires_at' => now()->addHours(24),
    ]);

    expect(Gate::forUser($owner)->allows('view', $conversation))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $conversation))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $conversation))->toBeTrue()
        ->and(Gate::forUser($other)->allows('view', $conversation))->toBeFalse()
        ->and(Gate::forUser($other)->allows('update', $conversation))->toBeFalse()
        ->and(Gate::forUser($other)->allows('delete', $conversation))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $proposal))->toBeTrue()
        ->and(Gate::forUser($other)->allows('view', $proposal))->toBeFalse()
        ->and(Gate::forUser($other)->allows('update', $proposal))->toBeFalse()
        ->and($proposal->isPendingAndActive())->toBeTrue()
        ->and($proposal->isExpired())->toBeFalse();
});

test('free slot calculator computes gaps accurately and handles overlapping events and custom windows', function () {
    $calculator = new FreeSlotCalculator;

    $occurrences = [
        [
            'is_all_day' => false,
            'starts_at_local' => '2026-11-20T09:00',
            'ends_at_local' => '2026-11-20T11:00',
        ],
        [
            'is_all_day' => false,
            'starts_at_local' => '2026-11-20T10:30',
            'ends_at_local' => '2026-11-20T12:00',
        ],
        [
            'is_all_day' => true,
            'starts_at_local' => null,
            'ends_at_local' => null,
        ],
    ];

    $slots = $calculator->calculateForDate(
        occurrences: $occurrences,
        dateKey: '2026-11-20',
        dayStartMinutes: $calculator->parseTimeToMinutes('08:00', 360),
        dayEndMinutes: $calculator->parseTimeToMinutes('15:00', 1320),
    );

    expect($slots)->toBe([
        ['start' => '08:00', 'end' => '09:00', 'minutes' => 60],
        ['start' => '12:00', 'end' => '15:00', 'minutes' => 180],
    ]);
});

test('ai circuit breaker isolates namespaces and opens after three consecutive failures', function () {
    Cache::flush();

    $breaker = new AiCircuitBreaker;

    expect($breaker->isOpen('gemini', 'ai_chat'))->toBeFalse();

    $breaker->recordFailure('gemini', 'ai_chat');
    $breaker->recordFailure('gemini', 'ai_chat');
    expect($breaker->isOpen('gemini', 'ai_chat'))->toBeFalse();

    $breaker->recordFailure('gemini', 'ai_chat');
    expect($breaker->isOpen('gemini', 'ai_chat'))->toBeTrue()
        ->and($breaker->isOpen('gemini', 'ai_planner'))->toBeFalse();

    $breaker->reset('gemini', 'ai_chat');
    expect($breaker->isOpen('gemini', 'ai_chat'))->toBeFalse();
});

test('ai planner context includes sanitized ai preferences without leaking user pii', function () {
    $user = User::factory()->create([
        'email' => 'student-secret@example.test',
        'whatsapp_number' => '+6289999999999',
        'ai_preferences' => [
            'tone' => 'detailed',
            'preferred_study_start' => '18:30',
            'preferred_study_end' => '21:30',
            'default_focus_minutes' => 45,
            'custom_instruction' => 'Utamakan mata kuliah inti.',
        ],
    ]);

    Task::factory()->for($user)->create(['title' => 'Tugas Basis Data']);

    $context = app(AiPlannerService::class)->buildContext($user);
    $snapshot = $context->toSanitizedSnapshot();
    $json = json_encode($snapshot, JSON_THROW_ON_ERROR);

    expect($snapshot['ai_preferences'])->toBe([
        'tone' => 'detailed',
        'preferred_study_start' => '18:30',
        'preferred_study_end' => '21:30',
        'default_focus_minutes' => 45,
        'custom_instruction' => 'Utamakan mata kuliah inti.',
    ])->and($json)->not->toContain('student-secret@example.test')
        ->and($json)->not->toContain('+6289999999999');
});

test('prune retention data command expires stale pending proposals and prunes old chat records past 30 days', function () {
    $user = User::factory()->create();

    $oldConversation = AiChatConversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Percakapan Lama',
        'last_message_at' => now()->subDays(35),
    ]);
    $oldConversation->forceFill(['created_at' => now()->subDays(35)])->saveQuietly();

    $oldMessage = AiChatMessage::query()->create([
        'conversation_id' => $oldConversation->id,
        'user_id' => $user->id,
        'role' => AiChatRole::User,
        'content' => 'Halo lama',
    ]);
    $oldMessage->forceFill(['created_at' => now()->subDays(35)])->saveQuietly();

    $oldProposal = AiActionProposal::query()->create([
        'user_id' => $user->id,
        'conversation_id' => $oldConversation->id,
        'ai_chat_message_id' => $oldMessage->id,
        'tool_name' => 'create_task',
        'payload' => ['title' => 'Old proposal'],
        'status' => AiActionProposalStatus::Executed,
        'expires_at' => now()->subDays(34),
    ]);
    $oldProposal->forceFill(['created_at' => now()->subDays(35)])->saveQuietly();

    $recentConversation = AiChatConversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Percakapan Baru',
        'last_message_at' => now()->subDays(2),
    ]);

    $recentMessage = AiChatMessage::query()->create([
        'conversation_id' => $recentConversation->id,
        'user_id' => $user->id,
        'role' => AiChatRole::Assistant,
        'content' => 'Usulan baru',
    ]);

    $stalePendingProposal = AiActionProposal::query()->create([
        'user_id' => $user->id,
        'conversation_id' => $recentConversation->id,
        'ai_chat_message_id' => $recentMessage->id,
        'tool_name' => 'create_schedule',
        'payload' => ['title' => 'Expired soon'],
        'status' => AiActionProposalStatus::Pending,
        'expires_at' => now()->subHour(),
    ]);

    $this->artisan('app:prune-retention-data')->assertSuccessful();

    expect(AiChatConversation::query()->find($oldConversation->id))->toBeNull()
        ->and(AiChatMessage::query()->find($oldMessage->id))->toBeNull()
        ->and(AiActionProposal::query()->find($oldProposal->id))->toBeNull()
        ->and(AiChatConversation::query()->find($recentConversation->id))->not->toBeNull()
        ->and($stalePendingProposal->fresh()?->status)->toBe(AiActionProposalStatus::Expired);
});

test('profile export includes whitelisted ai preferences, notification preferences, and own ai action proposals only', function () {
    $user = User::factory()->create([
        'ai_preferences' => ['tone' => 'concise', 'default_focus_minutes' => 45],
        'notification_preferences' => ['daily_summary_time' => '06:45'],
    ]);
    $otherUser = User::factory()->create();

    $myConv = AiChatConversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Chat Saya',
        'last_message_at' => now(),
    ]);
    $myMsg = AiChatMessage::query()->create([
        'conversation_id' => $myConv->id,
        'user_id' => $user->id,
        'role' => AiChatRole::Assistant,
        'content' => 'OK',
    ]);
    $myProposal = AiActionProposal::query()->create([
        'user_id' => $user->id,
        'conversation_id' => $myConv->id,
        'ai_chat_message_id' => $myMsg->id,
        'tool_name' => 'create_task',
        'payload' => ['title' => 'Secret raw payload'],
        'status' => AiActionProposalStatus::Executed,
        'expires_at' => now()->addHours(24),
        'target_type' => 'task',
        'target_id' => 42,
        'result_summary' => 'Tugas dibuat.',
    ]);

    $otherConv = AiChatConversation::query()->create([
        'user_id' => $otherUser->id,
        'title' => 'Chat Orang Lain',
        'last_message_at' => now(),
    ]);
    $otherMsg = AiChatMessage::query()->create([
        'conversation_id' => $otherConv->id,
        'user_id' => $otherUser->id,
        'role' => AiChatRole::Assistant,
        'content' => 'Secret',
    ]);
    AiActionProposal::query()->create([
        'user_id' => $otherUser->id,
        'conversation_id' => $otherConv->id,
        'ai_chat_message_id' => $otherMsg->id,
        'tool_name' => 'create_task',
        'payload' => ['title' => 'Other user proposal'],
        'status' => AiActionProposalStatus::Executed,
        'expires_at' => now()->addHours(24),
    ]);

    $response = $this->actingAs($user)->get(route('profile.export'))->assertOk();
    $data = $response->json();

    expect($data['user']['ai_preferences']['tone'])->toBe('concise')
        ->and($data['user']['notification_preferences']['daily_summary_time'])->toBe('06:45')
        ->and($data['ai_action_proposals'])->toHaveCount(1)
        ->and($data['ai_action_proposals'][0]['id'])->toBe($myProposal->id)
        ->and($data['ai_action_proposals'][0]['result_summary'])->toBe('Tugas dibuat.')
        ->and($data['ai_action_proposals'][0])->not->toHaveKey('payload');
});
