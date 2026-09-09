<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // 'manual' = user-entered or imported from Harvest historically
            // 'ai_estimated' = synthesized from AI narrative topic
            // 'imported' = bulk import from external system
            $table->string('source')->default('manual')->after('hours');

            // Links AI-estimated entries to the period they belong to so the
            // narrative regeneration knows which rows to replace.
            $table->foreignId('retainer_period_id')->nullable()->after('source')
                ->constrained('retainer_periods')->nullOnDelete();

            // Stable identifier per (period, topic) — used as an upsert key so
            // re-running the narrative refreshes existing rows in place rather
            // than appending duplicates.
            $table->string('source_ref')->nullable()->after('retainer_period_id');

            $table->index(['source', 'retainer_period_id'], 'time_entries_source_period_idx');
        });

        // Existing rows are tracked time. Default 'manual' is fine; no
        // backfill needed.
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex('time_entries_source_period_idx');
            $table->dropForeign(['retainer_period_id']);
            $table->dropColumn(['source', 'retainer_period_id', 'source_ref']);
        });
    }
};
