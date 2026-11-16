<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reminders:dispatch-due')->everyMinute();
Schedule::command('ai-planner:generate-daily')->dailyAt('05:30');
Schedule::command('app:prune-retention-data')->dailyAt('02:30');
