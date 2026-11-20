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
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->string('provider', 50)->nullable();
            $table->string('model', 100)->nullable();
            $table->jsonb('tool_calls_summary')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('ai_action_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ai_chat_conversations')->cascadeOnDelete();
            $table->foreignId('ai_chat_message_id')->constrained('ai_chat_messages')->cascadeOnDelete();
            $table->string('tool_name', 50);
            $table->jsonb('payload');
            $table->jsonb('conflict_preview')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('target_type', 40)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('result_summary')->nullable();
            $table->timestamps();

            $table->index(['ai_chat_message_id']);
            $table->index(['conversation_id']);
            $table->index(['user_id', 'status', 'expires_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_action_proposals');
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_conversations');
    }
};
