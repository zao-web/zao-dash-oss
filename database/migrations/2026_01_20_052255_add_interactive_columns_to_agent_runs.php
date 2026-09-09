<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL, add 'awaiting_input' to the status enum
        // SQLite doesn't have enum constraints, so it works without modification
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE agent_runs MODIFY COLUMN status ENUM('running', 'completed', 'failed', 'pending_approval', 'awaiting_input') DEFAULT 'running'");
        }

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->json('checkpoint')->nullable()->after('context');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First update any awaiting_input statuses to running
        DB::table('agent_runs')->where('status', 'awaiting_input')->update(['status' => 'running']);

        // For MySQL, remove the 'awaiting_input' status from the enum
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE agent_runs MODIFY COLUMN status ENUM('running', 'completed', 'failed', 'pending_approval') DEFAULT 'running'");
        }

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('checkpoint');
        });
    }
};
