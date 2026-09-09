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
        Schema::table('slack_workspaces', function (Blueprint $table) {
            $table->boolean('sync_client_dms')->default(true)->after('is_active');
        });

        // Also add is_dm flag to slack_channels to distinguish DMs from channels
        Schema::table('slack_channels', function (Blueprint $table) {
            if (! Schema::hasColumn('slack_channels', 'is_dm')) {
                $table->boolean('is_dm')->default(false)->after('is_shared');
            }
            if (! Schema::hasColumn('slack_channels', 'dm_user_ids')) {
                $table->json('dm_user_ids')->nullable()->after('is_dm');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slack_workspaces', function (Blueprint $table) {
            $table->dropColumn('sync_client_dms');
        });

        Schema::table('slack_channels', function (Blueprint $table) {
            $table->dropColumn(['is_dm', 'dm_user_ids']);
        });
    }
};
