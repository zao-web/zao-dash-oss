<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->string('workspace_path')->nullable()->after('task_description');
            $table->unsignedInteger('retry_attempt')->nullable()->after('workspace_path');
            $table->timestamp('retry_due_at')->nullable()->after('retry_attempt');
            $table->text('last_error')->nullable()->after('retry_due_at');

            $table->index(['status', 'retry_due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->dropIndex(['status', 'retry_due_at']);
            $table->dropColumn([
                'workspace_path',
                'retry_attempt',
                'retry_due_at',
                'last_error',
            ]);
        });
    }
};
