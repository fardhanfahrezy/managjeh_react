<?php

namespace App\Http\Controllers;

use App\Enums\AiPlannerRunStatus;
use App\Models\AiPlannerRun;
use App\Services\AiPlanner\AiPlannerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiPlannerController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $todayDate = CarbonImmutable::now($timezone)->format('Y-m-d');

        $latestRun = $user->aiPlannerRuns()
            ->where('planning_date', $todayDate)
            ->where('status', AiPlannerRunStatus::Success->value)
            ->latest('id')
            ->first();

        $recentRuns = $user->aiPlannerRuns()
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (AiPlannerRun $run): array => [
                'id' => $run->id,
                'planning_date' => $run->planning_date->format('Y-m-d'),
                'trigger_type' => $run->trigger_type,
                'provider' => $run->provider,
                'model' => $run->model,
                'status' => $run->status->value,
                'result' => $run->result,
                'error_message' => $run->error_message,
                'created_at' => $run->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        return Inertia::render('ai-planner/index', [
            'planning_date' => $todayDate,
            'timezone' => $timezone,
            'latest_run' => $latestRun !== null ? [
                'id' => $latestRun->id,
                'planning_date' => $latestRun->planning_date->format('Y-m-d'),
                'trigger_type' => $latestRun->trigger_type,
                'provider' => $latestRun->provider,
                'model' => $latestRun->model,
                'status' => $latestRun->status->value,
                'result' => $latestRun->result,
                'error_message' => $latestRun->error_message,
                'created_at' => $latestRun->created_at?->toISOString(),
            ] : null,
            'recent_runs' => $recentRuns,
        ]);
    }

    public function generate(Request $request, AiPlannerService $planner): RedirectResponse
    {
        $run = $planner->generateForUser($request->user(), 'manual');

        Inertia::flash('toast', [
            'type' => $run->status === AiPlannerRunStatus::Success ? 'success' : 'error',
            'message' => $run->status === AiPlannerRunStatus::Success
                ? sprintf('Rencana hari ini berhasil disusun (%s).', strtoupper($run->provider))
                : 'Gagal menyusun rencana hari ini. Silakan coba lagi.',
        ]);

        return back();
    }

    public function approveReminder(Request $request, AiPlannerService $planner): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'task_id' => [
                'required',
                'integer',
                Rule::exists('tasks', 'id')
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at'),
            ],
            'remind_at_local' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $planner->approveReminder(
            user: $user,
            taskId: (int) $validated['task_id'],
            remindAtLocal: (string) $validated['remind_at_local'],
            reason: (string) ($validated['reason'] ?? ''),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pengingat rekomendasi AI telah dijadwalkan.',
        ]);

        return back();
    }
}
