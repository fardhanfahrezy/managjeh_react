<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($user->isDirty('whatsapp_number')) {
            $user->whatsapp_verified_at = null;

            if ($user->whatsapp_number === null || $user->whatsapp_number === '') {
                $user->whatsapp_enabled = false;
            }
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Export the authenticated user's personal productivity data as JSON.
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $filename = sprintf('managjeh-export-%s.json', now()->format('Y-m-d'));

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'whatsapp_number' => $user->whatsapp_number,
                'whatsapp_verified_at' => $user->whatsapp_verified_at?->toIso8601String(),
                'whatsapp_enabled' => $user->whatsapp_enabled,
                'reminder_quiet_start' => $user->reminder_quiet_start,
                'reminder_quiet_end' => $user->reminder_quiet_end,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'projects' => $user->projects()
                ->orderBy('id')
                ->get(['id', 'name', 'description', 'status', 'start_date', 'due_date', 'completed_at', 'created_at', 'updated_at']),
            'tasks' => $user->tasks()
                ->orderBy('id')
                ->get(['id', 'project_id', 'parent_id', 'title', 'description', 'status', 'priority', 'due_at', 'completed_at', 'sort_order', 'created_at', 'updated_at']),
            'calendar_events' => $user->calendarEvents()
                ->orderBy('id')
                ->get(['id', 'project_id', 'task_id', 'parent_event_id', 'recurrence_id', 'title', 'description', 'starts_at', 'ends_at', 'start_date', 'end_date', 'is_all_day', 'is_cancelled', 'recurrence_rule', 'recurrence_until', 'recurrence_exceptions', 'created_at', 'updated_at']),
            'reminders' => $user->reminders()
                ->orderBy('id')
                ->get(['id', 'task_id', 'calendar_event_id', 'channel', 'reminder_type', 'scheduled_at', 'sent_at', 'failed_at', 'status', 'created_at', 'updated_at']),
            'ai_planner_runs' => $user->aiPlannerRuns()
                ->orderBy('id')
                ->get(['id', 'planning_date', 'trigger_type', 'provider', 'model', 'status', 'result', 'created_at']),
        ], 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
