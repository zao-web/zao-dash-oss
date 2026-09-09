<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks track status but never recorded *when* they were completed, so a
 * retainer report can't attribute delivered work to the period it happened in.
 * Add completed_at; the TaskObserver stamps it on the transition into
 * 'completed' (and clears it if a task is reopened). Backfill existing
 * completed tasks from updated_at as the best available signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('status');
        });

        // Backfill: existing completed tasks get completed_at = updated_at as a
        // reasonable proxy (the last write was the completion in most cases).
        DB::table('tasks')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
