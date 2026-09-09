<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connection tables that need sync status tracking.
     */
    protected array $tables = [
        'quickbooks_connections',
        'google_credentials',
        'slack_workspaces',
        'github_installations',
        'harvest_credentials',
        'notion_connections',
        'wordpress_sites',
        'pm_connections', // ClickUp
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'sync_status')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->string('sync_status')->default('pending'); // pending, syncing, completed, failed
                    $table->timestamp('sync_started_at')->nullable();
                    $table->timestamp('sync_completed_at')->nullable();
                    $table->text('sync_error')->nullable();
                    $table->unsignedTinyInteger('sync_progress')->default(0); // 0-100
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'sync_status')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn([
                        'sync_status',
                        'sync_started_at',
                        'sync_completed_at',
                        'sync_error',
                        'sync_progress',
                    ]);
                });
            }
        }
    }
};
