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
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('agent_id')->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->after('project_id')->constrained()->nullOnDelete();

            $table->index('project_id');
            $table->index('task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['task_id']);
            $table->dropColumn(['project_id', 'task_id']);
        });
    }
};
