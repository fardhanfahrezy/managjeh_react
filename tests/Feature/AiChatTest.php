<?php

use App\Enums\AiActionProposalStatus;
use App\Enums\AiChatRole;
use App\Enums\TaskPriority;
use App\Models\AiActionProposal;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\CalendarEvent;
use App\Models\Task;
use App\Models\User;
use App\Services\AiChat\AiChatPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();

    config()->set('services.gemini.api_key', 'test-gemini-key');
    config()->set('services.gemini.model', 'gemini-flash-test');
    config()->set('services.gemini.endpoint', 'https://generativelanguage.googleapis.com/v1beta');

    config()->set('services.grok.api_key', 'test-grok-key');
    config()->set('services.grok.model', 'grok-3-mini');
    config()->set('services.grok.endpoint', 'https://api.x.ai/v1/chat/completions');

    config()->set('services.openrouter.api_key', 'test-openrouter-key');
    config()->set('services.openrouter.model', 'google/gemini-flash');
    config()->set('services.openrouter.endpoint', 'https://openrouter.ai/api/v1/chat/completions');
});

test('orchestrator runs multi-turn Gemini read and intercepts write tools into pending proposals with conflict preview', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $tomorrowLocal = CarbonImmutable::now('Asia/Jakarta')->addDay();

    Task::factory()->for($user)->create([
        'title' => 'Laporan Jaringan Komputer',
        'priority' => TaskPriority::High,
    ]);

    CalendarEvent::factory()->for($user)->create([
        'title' => 'Kuliah Sistem Operasi',
        'is_all_day' => false,
        'starts_at' => $tomorrowLocal->setTime(13, 0)->utc(),
        'ends_at' => $tomorrowLocal->setTime(15, 0)->utc(),
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::sequence()
            // Round 1: read tasks
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'get_my_tasks',
                                'args' => new stdClass,
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            // Round 2: propose create_task + conflicting create_schedule
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'create_task',
                                    'args' => [
                                        'title' => 'Latihan Soal UTS',
                                        'priority' => 'high',
                                    ],
                                ],
                            ],
                            [
                                'functionCall' => [
                                    'name' => 'create_schedule',
                                    'args' => [
                                        'title' => 'Sesi Belajar UTS',
                                        'starts_at' => $tomorrowLocal->setTime(14, 0)->format('Y-m-d H:i'),
                                        'ends_at' => $tomorrowLocal->setTime(16, 0)->format('Y-m-d H:i'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
            ], 200)
            // Round 3: final Bahasa Indonesia explanation
            ->push([
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        'parts' => [[
                            'text' => 'Saya sudah menyiapkan usulan tugas dan jadwal belajar. Perhatikan ada bentrok dengan Kuliah Sistem Operasi. Silakan tinjau dan setujui.',
                        ]],
                    ],
                ]],
            ], 200),
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('ai-chat.messages.store'), [
            'message' => 'Bantu susun tugas dan jadwal belajar besok',
        ]);

    $response->assertCreated()
        ->assertJsonPath('assistant_message.provider', 'gemini')
        ->assertJsonCount(2, 'assistant_message.proposals');

    // Write tools must NOT mutate database before approval.
    expect(Task::where('title', 'Latihan Soal UTS')->exists())->toBeFalse()
        ->and(CalendarEvent::where('title', 'Sesi Belajar UTS')->exists())->toBeFalse();

    $proposals = AiActionProposal::where('user_id', $user->id)->orderBy('id')->get();
    expect($proposals)->toHaveCount(2)
        ->and($proposals[0]->tool_name)->toBe('create_task')
        ->and($proposals[0]->status)->toBe(AiActionProposalStatus::Pending)
        ->and($proposals[1]->tool_name)->toBe('create_schedule')
        ->and($proposals[1]->conflict_preview)->toHaveCount(1)
        ->and($proposals[1]->conflict_preview[0]['title'])->toBe('Kuliah Sistem Operasi');
});

test('batch proposal decision executes approved items, rejects unselected items, stays idempotent, and marks expired proposals', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    $conversation = $user->aiChatConversations()->create([
        'title' => 'Rencana Belajar',
        'last_message_at' => now(),
    ]);
    $message = $conversation->messages()->create([
        'user_id' => $user->id,
        'role' => AiChatRole::Assistant,
        'content' => 'Berikut 3 usulan tindakan.',
    ]);

    $approvedProposal = AiActionProposal::create([
        'user_id' => $user->id,
        'conversation_id' => $conversation->id,
        'ai_chat_message_id' => $message->id,
        'tool_name' => 'create_task',
        'payload' => ['title' => 'Kerjakan Modul 4', 'priority' => 'high', 'status' => 'todo'],
        'status' => AiActionProposalStatus::Pending,
        'expires_at' => now()->addHours(24),
    ]);

    $rejectedProposal = AiActionProposal::create([
        'user_id' => $user->id,
        'conversation_id' => $conversation->id,
        'ai_chat_message_id' => $message->id,
        'tool_name' => 'create_task',
        'payload' => ['title' => 'Tugas Yang Ditolak', 'priority' => 'low', 'status' => 'todo'],
        'status' => AiActionProposalStatus::Pending,
        'expires_at' => now()->addHours(24),
    ]);

    $expiredProposal = AiActionProposal::create([
        'user_id' => $user->id,
        'conversation_id' => $conversation->id,
        'ai_chat_message_id' => $message->id,
        'tool_name' => 'create_task',
        'payload' => ['title' => 'Tugas Kedaluwarsa', 'priority' => 'medium', 'status' => 'todo'],
        'status' => AiActionProposalStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('ai-chat.proposals.decide', $message), [
            'approved_proposal_ids' => [$approvedProposal->id, $expiredProposal->id],
        ]);

    $response->assertOk()
        ->assertJsonPath('summary.executed', 1)
        ->assertJsonPath('summary.rejected', 1)
        ->assertJsonPath('summary.expired', 1)
        ->assertJsonPath('summary.failed', 0);

    expect(Task::where('title', 'Kerjakan Modul 4')->count())->toBe(1)
        ->and(Task::where('title', 'Tugas Yang Ditolak')->exists())->toBeFalse()
        ->and(Task::where('title', 'Tugas Kedaluwarsa')->exists())->toBeFalse();

    $approvedProposal->refresh();
    expect($approvedProposal->status)->toBe(AiActionProposalStatus::Executed)
        ->and($approvedProposal->target_type)->toBe('task')
        ->and($approvedProposal->target_id)->not->toBeNull();

    // Idempotent second call must not duplicate task creation.
    $this->actingAs($user)
        ->postJson(route('ai-chat.proposals.decide', $message), [
            'approved_proposal_ids' => [$approvedProposal->id],
        ])
        ->assertOk()
        ->assertJsonPath('summary.executed', 0);

    expect(Task::where('title', 'Kerjakan Modul 4')->count())->toBe(1);
});

test('orchestrator falls back from Gemini to Grok with OpenAI-compatible tool calls', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'overloaded'], 503),
        'https://api.x.ai/*' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_grok_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_my_projects',
                                'arguments' => json_encode(['status' => 'active']),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Saat ini belum ada proyek aktif di akun Anda.',
                    ],
                ]],
            ], 200),
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('ai-chat.messages.store'), [
            'message' => 'Proyek aktif saya apa saja?',
        ]);

    $response->assertCreated()
        ->assertJsonPath('assistant_message.provider', 'grok')
        ->assertJsonPath('assistant_message.content', 'Saat ini belum ada proyek aktif di akun Anda.');
});

test('orchestrator returns 503 with fallback url and leaves no orphan records when all providers fail', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([], 500),
        'https://api.x.ai/*' => Http::response([], 500),
        'https://openrouter.ai/*' => Http::response([], 500),
    ]);

    $this->actingAs($user)
        ->postJson(route('ai-chat.messages.store'), [
            'message' => 'Tes semua gagal',
        ])
        ->assertStatus(503)
        ->assertJsonStructure(['message', 'fallback_url']);

    expect(AiChatConversation::count())->toBe(0)
        ->and(AiChatMessage::count())->toBe(0);
});

test('system prompt excludes PII, conversation access is isolated per user, and AI settings validate properly', function () {
    $owner = User::factory()->create([
        'email' => 'secret-student@campus.ac.id',
        'whatsapp_number' => '+6281299998888',
        'timezone' => 'Asia/Jakarta',
    ]);
    $intruder = User::factory()->create();

    $prompt = app(AiChatPromptBuilder::class)->build($owner);
    expect($prompt)->not->toContain('secret-student@campus.ac.id')
        ->and($prompt)->not->toContain('+6281299998888')
        ->and($prompt)->toContain('Asia/Jakarta');

    $conversation = $owner->aiChatConversations()->create([
        'title' => 'Catatan Pribadi',
        'last_message_at' => now(),
    ]);
    $message = $conversation->messages()->create([
        'user_id' => $owner->id,
        'role' => AiChatRole::Assistant,
        'content' => 'Halo',
    ]);

    // Intruder cannot read, rename, delete, append to, or decide proposals on owner's conversation.
    $this->actingAs($intruder)
        ->getJson(route('ai-chat.conversations.show', $conversation))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => 'Dibajak'])
        ->assertForbidden();

    $this->actingAs($intruder)
        ->deleteJson(route('ai-chat.conversations.destroy', $conversation))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->postJson(route('ai-chat.messages.store'), [
            'message' => 'Coba sisip',
            'conversation_id' => $conversation->id,
        ])
        ->assertUnprocessable();

    $this->actingAs($intruder)
        ->postJson(route('ai-chat.proposals.decide', $message), [
            'reject_all' => true,
        ])
        ->assertForbidden();

    // Owner can rename and delete own conversation.
    $this->actingAs($owner)
        ->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => 'Judul Baru'])
        ->assertOk()
        ->assertJsonPath('conversation.title', 'Judul Baru');

    $this->actingAs($owner)
        ->getJson(route('ai-chat.audits'))
        ->assertOk()
        ->assertJsonStructure(['audits']);

    $this->actingAs($owner)
        ->deleteJson(route('ai-chat.conversations.destroy', $conversation))
        ->assertOk();

    expect(AiChatConversation::find($conversation->id))->toBeNull();

    // Settings AI preferences update & validation.
    $this->actingAs($owner)
        ->patch(route('ai-preferences.update'), [
            'ai_preferences' => [
                'tone' => 'concise',
                'preferred_study_start' => '21:00',
                'preferred_study_end' => '19:00',
                'default_focus_minutes' => 45,
            ],
        ])
        ->assertSessionHasErrors('ai_preferences.preferred_study_end');

    $this->actingAs($owner)
        ->patch(route('ai-preferences.update'), [
            'ai_preferences' => [
                'tone' => 'concise',
                'preferred_study_start' => '18:30',
                'preferred_study_end' => '21:30',
                'default_focus_minutes' => 45,
                'custom_instruction' => 'Prioritaskan tugas praktikum.',
            ],
            'notification_preferences' => [
                'daily_summary_enabled' => true,
                'daily_summary_time' => '06:30',
                'weekly_summary_enabled' => false,
                'weekly_summary_day' => 0,
                'weekly_summary_time' => '19:00',
                'event_prep_enabled' => true,
            ],
        ])
        ->assertRedirect();

    $owner->refresh();
    expect($owner->resolvedAiPreferences()['tone'])->toBe('concise')
        ->and($owner->resolvedAiPreferences()['default_focus_minutes'])->toBe(45)
        ->and($owner->resolvedNotificationPreferences()['daily_summary_time'])->toBe('06:30')
        ->and($owner->resolvedNotificationPreferences()['weekly_summary_enabled'])->toBeFalse();
});

test('ai-chat and settings ai Inertia pages render with expected props', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);

    $this->actingAs($user)
        ->get(route('ai-chat.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ai-chat/index')
            ->has('conversations')
            ->has('messages')
            ->has('audits')
            ->where('timezone', 'Asia/Jakarta'));

    $this->actingAs($user)
        ->get(route('ai-preferences.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->has('ai_preferences')
            ->has('notification_preferences')
            ->where('timezone', 'Asia/Jakarta'));
});
