<?php

use App\Http\Controllers\CalendarEventController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::resource('projects', ProjectController::class)->except(['create', 'edit']);
    Route::resource('tasks', TaskController::class)->except(['create', 'edit', 'show']);
    Route::get('calendar', [CalendarEventController::class, 'index'])->name('calendar.index');
    Route::resource('calendar-events', CalendarEventController::class)->only(['store', 'update', 'destroy']);
});

require __DIR__.'/settings.php';
