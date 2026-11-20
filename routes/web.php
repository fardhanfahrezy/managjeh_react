<?php

use App\Http\Controllers\AiChatController;
use App\Http\Controllers\AiPlannerController;
use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('offline-shell', 'offline-shell')->name('offline-shell');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::resource('projects', ProjectController::class)->except(['create', 'edit']);
    Route::resource('tasks', TaskController::class)->except(['create', 'edit', 'show']);
    Route::get('calendar', [CalendarEventController::class, 'index'])->name('calendar.index');
    Route::resource('calendar-events', CalendarEventController::class)->only(['store', 'update', 'destroy']);
    Route::get('ai-planner', [AiPlannerController::class, 'index'])->name('ai-planner.index');
    Route::post('ai-planner/generate', [AiPlannerController::class, 'generate'])
        ->middleware('throttle:ai-planner')
        ->name('ai-planner.generate');
    Route::post('ai-planner/reminders/approve', [AiPlannerController::class, 'approveReminder'])
        ->name('ai-planner.approve-reminder');

    Route::get('ai-chat', [AiChatController::class, 'index'])->name('ai-chat.index');
    Route::get('ai-chat/audits', [AiChatController::class, 'audits'])->name('ai-chat.audits');
    Route::get('ai-chat/conversations/{conversation}', [AiChatController::class, 'show'])
        ->name('ai-chat.conversations.show');
    Route::post('ai-chat/messages', [AiChatController::class, 'storeMessage'])
        ->middleware('throttle:ai-chat')
        ->name('ai-chat.messages.store');
    Route::patch('ai-chat/conversations/{conversation}', [AiChatController::class, 'updateConversation'])
        ->name('ai-chat.conversations.update');
    Route::delete('ai-chat/conversations/{conversation}', [AiChatController::class, 'destroyConversation'])
        ->name('ai-chat.conversations.destroy');
    Route::post('ai-chat/messages/{message}/proposals/decide', [AiChatController::class, 'decideProposals'])
        ->name('ai-chat.proposals.decide');

    Route::prefix('sync')->middleware('throttle:sync')->name('sync.')->group(function () {
        Route::post('push', [SyncController::class, 'push'])->name('push');
        Route::get('pull', [SyncController::class, 'pull'])->name('pull');
        Route::get('status', [SyncController::class, 'status'])->name('status');
    });
});

require __DIR__.'/settings.php';
