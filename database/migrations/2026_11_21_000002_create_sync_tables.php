<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('entity_type', 40);
            $table->uuid('entity_uuid');
            $table->string('status', 32);
            $table->jsonb('response_payload');
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(['user_id', 'operation_id'], 'sync_operations_user_op_unique');
            $table->index('processed_at', 'sync_operations_processed_at_idx');
        });

        Schema::create('sync_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 40);
            $table->uuid('entity_uuid');
            $table->timestamp('deleted_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(
                ['user_id', 'entity_type', 'entity_uuid'],
                'sync_tombstones_user_entity_unique',
            );
            $table->index(['user_id', 'deleted_at'], 'sync_tombstones_user_deleted_idx');
            $table->index('expires_at', 'sync_tombstones_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_tombstones');
        Schema::dropIfExists('sync_operations');
    }
};
