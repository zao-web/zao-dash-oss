<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add is_active column to all integration connection tables.
 * Sync jobs filter by is_active to allow disabling connections without deleting.
 */
return new class extends Migration
{
    protected array $tables = [
        'notion_connections',
        'quickbooks_connections',
        'slack_workspaces',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'is_active')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->boolean('is_active')->default(true)->after('id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'is_active')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('is_active');
                });
            }
        }
    }
};
