<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('reminder_type', 40)->default('custom');
            $table->timestamp('scheduled_at');
            $table->string('status', 20)->default('pending');
            $table->text('message');
            $table->string('provider_message_id', 150)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
