<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['agent_run_id']);

            // Modify the column to be nullable
            $table->foreignId('agent_run_id')
                ->nullable()
                ->change();

            // Re-add the foreign key constraint with nullable support
            $table->foreign('agent_run_id')
                ->references('id')
                ->on('agent_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            // Drop the nullable foreign key
            $table->dropForeign(['agent_run_id']);

            // Revert to non-nullable (will fail if null values exist)
            $table->foreignId('agent_run_id')
                ->nullable(false)
                ->change();

            // Re-add strict foreign key
            $table->foreign('agent_run_id')
                ->references('id')
                ->on('agent_runs')
                ->cascadeOnDelete();
        });
    }
};
