<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_event_id')->nullable()->constrained('calendar_events')->cascadeOnDelete();
            $table->string('recurrence_id', 64)->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_all_day')->default(false);
            $table->boolean('is_cancelled')->default(false);
            $table->string('recurrence_rule', 255)->nullable();
            $table->date('recurrence_until')->nullable();
            $table->jsonb('recurrence_exceptions')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'starts_at', 'ends_at']);
            $table->index(['user_id', 'start_date', 'end_date']);
            $table->index(['parent_event_id', 'recurrence_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
