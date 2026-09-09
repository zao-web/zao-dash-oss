<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 50);
            $table->string('description');
            $table->json('metadata')->nullable();

            $table->decimal('hours_logged', 8, 2)->nullable();
            $table->decimal('hours_estimated', 8, 2)->nullable();
            $table->foreignId('time_entry_id')->nullable();
            $table->unsignedBigInteger('harvest_time_entry_id')->nullable();

            $table->string('pr_url')->nullable();
            $table->string('pr_number')->nullable();

            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();

            $table->timestamps();

            $table->index(['task_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('agent_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
