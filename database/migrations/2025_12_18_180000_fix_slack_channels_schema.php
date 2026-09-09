<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix slack_channels schema to match what SyncSlackJob expects.
 * The original migration used different column names than the job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slack_channels', function (Blueprint $table) {
            // Add slack_id if missing (job expects this instead of channel_id)
            if (! Schema::hasColumn('slack_channels', 'slack_id')) {
                $table->string('slack_id')->after('workspace_id')->nullable();
            }

            // Add name if missing (job expects this instead of channel_name)
            if (! Schema::hasColumn('slack_channels', 'name')) {
                $table->string('name')->after('slack_id')->nullable();
            }

            // Add is_archived if missing
            if (! Schema::hasColumn('slack_channels', 'is_archived')) {
                $table->boolean('is_archived')->default(false)->after('is_private');
            }

            // Add member_count if missing
            if (! Schema::hasColumn('slack_channels', 'member_count')) {
                $table->integer('member_count')->default(0)->after('is_archived');
            }

            // Add topic if missing
            if (! Schema::hasColumn('slack_channels', 'topic')) {
                $table->text('topic')->nullable()->after('member_count');
            }

            // Add purpose if missing
            if (! Schema::hasColumn('slack_channels', 'purpose')) {
                $table->text('purpose')->nullable()->after('topic');
            }

            // Add last_message_at if missing
            if (! Schema::hasColumn('slack_channels', 'last_message_at')) {
                $table->timestamp('last_message_at')->nullable()->after('last_synced_at');
            }

            // Add sync_bot_messages if missing
            if (! Schema::hasColumn('slack_channels', 'sync_bot_messages')) {
                $table->boolean('sync_bot_messages')->default(false)->after('monitoring_enabled');
            }

            // Add is_monitored if missing (job expects this instead of monitoring_enabled)
            if (! Schema::hasColumn('slack_channels', 'is_monitored')) {
                $table->boolean('is_monitored')->default(true)->after('sync_bot_messages');
            }
        });

        // Copy data from old columns to new ones if both exist
        if (Schema::hasColumn('slack_channels', 'channel_id') && Schema::hasColumn('slack_channels', 'slack_id')) {
            \DB::statement('UPDATE slack_channels SET slack_id = channel_id WHERE slack_id IS NULL');
        }
        if (Schema::hasColumn('slack_channels', 'channel_name') && Schema::hasColumn('slack_channels', 'name')) {
            \DB::statement('UPDATE slack_channels SET name = channel_name WHERE name IS NULL');
        }
        if (Schema::hasColumn('slack_channels', 'monitoring_enabled') && Schema::hasColumn('slack_channels', 'is_monitored')) {
            \DB::statement('UPDATE slack_channels SET is_monitored = monitoring_enabled');
        }

        // Add unique index on slack_id if not exists
        Schema::table('slack_channels', function (Blueprint $table) {
            // Drop old unique index if exists and add new one
            try {
                $table->unique(['workspace_id', 'slack_id'], 'slack_channels_workspace_slack_unique');
            } catch (\Exception $e) {
                // Index might already exist
            }
        });
    }

    public function down(): void
    {
        Schema::table('slack_channels', function (Blueprint $table) {
            $columns = ['slack_id', 'name', 'is_archived', 'member_count', 'topic', 'purpose', 'last_message_at', 'sync_bot_messages', 'is_monitored'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('slack_channels', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
