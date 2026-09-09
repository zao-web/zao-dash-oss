<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix remaining schema mismatches for Slack and Harvest.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fix slack_channels: make channel_id nullable since job uses slack_id
        // PostgreSQL requires raw SQL, SQLite doesn't support ALTER COLUMN
        if (Schema::hasColumn('slack_channels', 'channel_id') && DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE slack_channels ALTER COLUMN channel_id DROP NOT NULL');
        }

        // Fix harvest_task_categories: add missing billable_by_default column
        if (! Schema::hasColumn('harvest_task_categories', 'billable_by_default')) {
            Schema::table('harvest_task_categories', function (Blueprint $table) {
                $table->boolean('billable_by_default')->default(true)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        // Reverting these changes could cause data loss, so we don't
    }
};
