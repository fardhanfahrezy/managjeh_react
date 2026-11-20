<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAiPreferencesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiPreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/ai', [
            'ai_preferences' => $user->resolvedAiPreferences(),
            'notification_preferences' => $user->resolvedNotificationPreferences(),
            'whatsapp_ready' => $user->canReceiveWhatsAppReminders(),
            'timezone' => $user->timezone ?: 'Asia/Jakarta',
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(UpdateAiPreferencesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        /** @var array<string, mixed> $aiPrefsInput */
        $aiPrefsInput = $validated['ai_preferences'];
        $customInstruction = isset($aiPrefsInput['custom_instruction']) && is_string($aiPrefsInput['custom_instruction'])
            ? trim($aiPrefsInput['custom_instruction'])
            : '';

        $user->ai_preferences = [
            'tone' => (string) $aiPrefsInput['tone'],
            'preferred_study_start' => (string) $aiPrefsInput['preferred_study_start'],
            'preferred_study_end' => (string) $aiPrefsInput['preferred_study_end'],
            'default_focus_minutes' => (int) $aiPrefsInput['default_focus_minutes'],
            'custom_instruction' => $customInstruction !== '' ? mb_substr($customInstruction, 0, 300) : null,
        ];

        if (isset($validated['notification_preferences']) && is_array($validated['notification_preferences'])) {
            $currentNotif = $user->resolvedNotificationPreferences();
            /** @var array<string, mixed> $notifInput */
            $notifInput = $validated['notification_preferences'];

            $user->notification_preferences = [
                'daily_summary_enabled' => array_key_exists('daily_summary_enabled', $notifInput)
                    ? (bool) $notifInput['daily_summary_enabled']
                    : $currentNotif['daily_summary_enabled'],
                'daily_summary_time' => isset($notifInput['daily_summary_time']) && is_string($notifInput['daily_summary_time'])
                    ? $notifInput['daily_summary_time']
                    : $currentNotif['daily_summary_time'],
                'weekly_summary_enabled' => array_key_exists('weekly_summary_enabled', $notifInput)
                    ? (bool) $notifInput['weekly_summary_enabled']
                    : $currentNotif['weekly_summary_enabled'],
                'weekly_summary_day' => isset($notifInput['weekly_summary_day']) && is_numeric($notifInput['weekly_summary_day'])
                    ? (int) $notifInput['weekly_summary_day']
                    : $currentNotif['weekly_summary_day'],
                'weekly_summary_time' => isset($notifInput['weekly_summary_time']) && is_string($notifInput['weekly_summary_time'])
                    ? $notifInput['weekly_summary_time']
                    : $currentNotif['weekly_summary_time'],
                'event_prep_enabled' => array_key_exists('event_prep_enabled', $notifInput)
                    ? (bool) $notifInput['event_prep_enabled']
                    : $currentNotif['event_prep_enabled'],
            ];
        }

        $user->save();

        return back()->with('status', 'Preferensi AI dan notifikasi berhasil disimpan.');
    }
}
