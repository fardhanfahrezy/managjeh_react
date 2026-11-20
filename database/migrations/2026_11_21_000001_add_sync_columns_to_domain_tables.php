<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['projects', 'tasks', 'calendar_events'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
                $table->unsignedInteger('version')->default(1)->after('uuid');
            });

            DB::table($tableName)
                ->select('id')
                ->whereNull('uuid')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($tableName): void {
                    foreach ($rows as $row) {
                        DB::table($tableName)
                            ->where('id', $row->id)
                            ->update([
                                'uuid' => (string) Str::uuid(),
                                'version' => 1,
                            ]);
                    }
                });

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->uuid('uuid')->nullable(false)->change();
                $table->unique('uuid', "{$tableName}_uuid_unique");
                $table->index(['user_id', 'updated_at'], "{$tableName}_user_updated_idx");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique("{$tableName}_uuid_unique");
                $table->dropIndex("{$tableName}_user_updated_idx");
                $table->dropColumn(['uuid', 'version']);
            });
        }
    }
};
