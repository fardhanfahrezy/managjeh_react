<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_planner_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('planning_date');
            $table->string('trigger_type', 30)->default('manual');
            $table->string('provider', 50);
            $table->string('model', 100)->nullable();
            $table->string('status', 20)->default('pending');
            $table->jsonb('input_snapshot')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'planning_date']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_planner_runs');
    }
};
