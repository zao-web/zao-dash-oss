<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('agent_id')->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->after('task_id')->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->after('project_id')->constrained()->nullOnDelete();

            $table->decimal('estimated_human_hours', 8, 2)->nullable()->after('result');
            $table->decimal('actual_agent_seconds', 10, 2)->nullable()->after('estimated_human_hours');
            $table->unsignedBigInteger('harvest_time_entry_id')->nullable()->after('actual_agent_seconds');

            $table->index('task_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['client_id']);
            $table->dropColumn([
                'task_id',
                'project_id',
                'client_id',
                'estimated_human_hours',
                'actual_agent_seconds',
                'harvest_time_entry_id',
            ]);
        });
    }
};
