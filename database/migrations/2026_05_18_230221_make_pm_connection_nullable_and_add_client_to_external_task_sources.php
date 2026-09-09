<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_task_sources', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('pm_connection_id')
                ->constrained()
                ->nullOnDelete();

            $table->unsignedBigInteger('pm_connection_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('external_task_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            // Leave pm_connection_id nullable on rollback — restoring NOT NULL
            // would crash if any activity-feed rows remain. Operators can
            // tighten manually after cleanup.
        });
    }
};
