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
        Schema::table('client_reports', function (Blueprint $table) {
            // Add columns that may be missing from earlier migration runs
            if (! Schema::hasColumn('client_reports', 'highlights')) {
                $table->json('highlights')->nullable()->after('executive_summary');
            }
            if (! Schema::hasColumn('client_reports', 'metrics')) {
                $table->json('metrics')->nullable()->after('highlights');
            }
            if (! Schema::hasColumn('client_reports', 'hours_by_category')) {
                $table->json('hours_by_category')->nullable()->after('total_hours');
            }
            if (! Schema::hasColumn('client_reports', 'hours_by_project')) {
                $table->json('hours_by_project')->nullable()->after('hours_by_category');
            }
            if (! Schema::hasColumn('client_reports', 'tasks_completed')) {
                $table->integer('tasks_completed')->default(0)->after('hours_by_project');
            }
            if (! Schema::hasColumn('client_reports', 'prs_merged')) {
                $table->integer('prs_merged')->default(0)->after('tasks_completed');
            }
            if (! Schema::hasColumn('client_reports', 'issues_closed')) {
                $table->integer('issues_closed')->default(0)->after('prs_merged');
            }
            if (! Schema::hasColumn('client_reports', 'meetings_held')) {
                $table->integer('meetings_held')->default(0)->after('issues_closed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop columns on rollback since they may have been added by the original migration
    }
};
