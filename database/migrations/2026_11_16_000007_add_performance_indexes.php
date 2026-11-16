<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index('parent_id');
            $table->index(['user_id', 'parent_id', 'status', 'due_at'], 'tasks_user_root_status_due_idx');
            $table->index(['user_id', 'status', 'completed_at'], 'tasks_user_status_completed_at_idx');
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->index('project_id');
            $table->index('task_id');
            $table->index(['user_id', 'parent_event_id'], 'calendar_events_user_parent_idx');
        });

        Schema::table('reminders', function (Blueprint $table): void {
            $table->index('task_id');
            $table->index('calendar_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['parent_id']);
            $table->dropIndex('tasks_user_root_status_due_idx');
            $table->dropIndex('tasks_user_status_completed_at_idx');
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropIndex(['project_id']);
            $table->dropIndex(['task_id']);
            $table->dropIndex('calendar_events_user_parent_idx');
        });

        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropIndex(['task_id']);
            $table->dropIndex(['calendar_event_id']);
        });
    }
};
