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
        Schema::create('pm_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_task_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation'); // 'import', 'update', 'sync_back', 'full_sync', 'error'
            $table->string('status'); // 'success', 'partial', 'failed'
            $table->unsignedInteger('tasks_processed')->default(0);
            $table->unsignedInteger('tasks_created')->default(0);
            $table->unsignedInteger('tasks_updated')->default(0);
            $table->unsignedInteger('tasks_skipped')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->json('details')->nullable(); // Error messages, stats, etc.
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['pm_connection_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pm_sync_logs');
    }
};
