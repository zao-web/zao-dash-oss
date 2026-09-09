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
        Schema::table('agents', function (Blueprint $table) {
            // Track if agent is from PHP definition or UI-created
            $table->boolean('is_dynamic')->default(false)->after('status');

            // PHP class path for registered agents
            $table->string('definition_class')->nullable()->after('is_dynamic');

            // Last sync timestamp with definition
            $table->timestamp('definition_synced_at')->nullable()->after('definition_class');

            // Webhook/trigger configuration
            $table->json('trigger_config')->nullable()->after('schedule');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            // How the agent was invoked
            $table->string('invocation_source')->default('manual')->after('task');

            // Who/what triggered the run (user_id, 'system', 'webhook', etc.)
            $table->string('invoked_by')->nullable()->after('invocation_source');

            // Additional trigger metadata (webhook payload, schedule info, etc.)
            $table->json('trigger_metadata')->nullable()->after('invoked_by');

            // Add index for querying by source
            $table->index(['invocation_source', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'is_dynamic',
                'definition_class',
                'definition_synced_at',
                'trigger_config',
            ]);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['invocation_source', 'created_at']);
            $table->dropColumn([
                'invocation_source',
                'invoked_by',
                'trigger_metadata',
            ]);
        });
    }
};
