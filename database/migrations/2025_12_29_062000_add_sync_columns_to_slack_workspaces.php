<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slack_workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('slack_workspaces', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable();
            }
            if (! Schema::hasColumn('slack_workspaces', 'sync_status')) {
                $table->string('sync_status')->nullable();
            }
            if (! Schema::hasColumn('slack_workspaces', 'sync_error')) {
                $table->text('sync_error')->nullable();
            }
            if (! Schema::hasColumn('slack_workspaces', 'sync_started_at')) {
                $table->timestamp('sync_started_at')->nullable();
            }
            if (! Schema::hasColumn('slack_workspaces', 'sync_completed_at')) {
                $table->timestamp('sync_completed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('slack_workspaces', function (Blueprint $table) {
            $cols = ['last_synced_at', 'sync_status', 'sync_error', 'sync_started_at', 'sync_completed_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('slack_workspaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
