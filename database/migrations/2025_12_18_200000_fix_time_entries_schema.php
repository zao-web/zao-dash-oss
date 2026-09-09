<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing columns to time_entries that SyncHarvestJob expects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('time_entries', 'harvest_project_id')) {
                $table->bigInteger('harvest_project_id')->nullable()->after('project_id');
            }
            if (! Schema::hasColumn('time_entries', 'task_category_id')) {
                $table->bigInteger('task_category_id')->nullable()->after('task_id');
            }
            if (! Schema::hasColumn('time_entries', 'harvest_user_id')) {
                $table->bigInteger('harvest_user_id')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('time_entries', 'harvest_user_name')) {
                $table->string('harvest_user_name')->nullable()->after('harvest_user_id');
            }
            if (! Schema::hasColumn('time_entries', 'rounded_hours')) {
                $table->decimal('rounded_hours', 8, 2)->nullable()->after('hours');
            }
            if (! Schema::hasColumn('time_entries', 'notes')) {
                $table->text('notes')->nullable()->after('rounded_hours');
            }
            if (! Schema::hasColumn('time_entries', 'is_locked')) {
                $table->boolean('is_locked')->default(false)->after('is_billed');
            }
            if (! Schema::hasColumn('time_entries', 'is_closed')) {
                $table->boolean('is_closed')->default(false)->after('is_locked');
            }
            if (! Schema::hasColumn('time_entries', 'billable')) {
                $table->boolean('billable')->default(true)->after('is_closed');
            }
            if (! Schema::hasColumn('time_entries', 'budgeted')) {
                $table->boolean('budgeted')->default(true)->after('billable');
            }
            if (! Schema::hasColumn('time_entries', 'billable_rate')) {
                $table->decimal('billable_rate', 10, 2)->nullable()->after('budgeted');
            }
            if (! Schema::hasColumn('time_entries', 'started_time')) {
                $table->string('started_time')->nullable()->after('timer_started_at');
            }
            if (! Schema::hasColumn('time_entries', 'ended_time')) {
                $table->string('ended_time')->nullable()->after('started_time');
            }
            if (! Schema::hasColumn('time_entries', 'external_reference')) {
                $table->json('external_reference')->nullable()->after('ended_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $columns = ['harvest_project_id', 'task_category_id', 'harvest_user_id', 'harvest_user_name',
                'rounded_hours', 'notes', 'is_locked', 'is_closed', 'billable', 'budgeted',
                'billable_rate', 'started_time', 'ended_time', 'external_reference'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('time_entries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
