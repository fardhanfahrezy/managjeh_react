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
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->string('whatsapp_number', 32)->nullable()->index();
            $table->timestamp('whatsapp_verified_at')->nullable();
            $table->boolean('whatsapp_enabled')->default(false);
            $table->time('reminder_quiet_start')->nullable()->default('00:00:00');
            $table->time('reminder_quiet_end')->nullable()->default('05:00:00');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_number']);
            $table->dropColumn([
                'timezone',
                'whatsapp_number',
                'whatsapp_verified_at',
                'whatsapp_enabled',
                'reminder_quiet_start',
                'reminder_quiet_end',
            ]);
        });
    }
};
