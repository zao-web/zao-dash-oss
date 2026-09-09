<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weekly action plans created by Business Strategist
        Schema::create('weekly_plans', function (Blueprint $table) {
            $table->id();
            $table->date('week_starting'); // Always a Monday
            $table->foreignId('strategic_goal_id')->nullable()->constrained()->nullOnDelete();

            // Plan content
            $table->json('focus_areas')->nullable(); // ["lead_gen", "closing", "upsells"]
            $table->json('targets')->nullable(); // {"leads": 10, "revenue": 50000}
            $table->text('strategy_notes')->nullable();

            // Status tracking
            $table->string('status')->default('draft'); // draft, approved, active, completed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Results (filled at week end)
            $table->json('week_results')->nullable(); // Actual performance

            $table->timestamps();

            $table->unique('week_starting');
        });

        // Individual action items within a weekly plan
        Schema::create('weekly_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_plan_id')->constrained()->cascadeOnDelete();

            // Action details
            $table->string('action'); // Description of the action
            $table->string('owner_type')->default('human'); // human, agent
            $table->string('agent_slug')->nullable(); // For agent tasks
            $table->string('priority')->default('medium'); // low, medium, high, critical
            $table->string('due_day')->nullable(); // monday, tuesday, etc.

            // Success metrics
            $table->string('success_metric')->nullable();
            $table->json('expected_outcome')->nullable();

            // Status
            $table->string('status')->default('pending'); // pending, in_progress, completed, skipped
            $table->json('result')->nullable(); // Actual outcome
            $table->foreignId('agent_run_id')->nullable(); // If executed by agent

            $table->timestamps();

            $table->index(['weekly_plan_id', 'status']);
        });

        // Tasks assigned to agents by the strategist
        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('weekly_plan_item_id')->nullable()->constrained()->nullOnDelete();

            // Task details
            $table->text('task_description');
            $table->json('context')->nullable(); // Additional context for the agent
            $table->string('priority')->default('normal'); // low, normal, high, urgent

            // Status
            $table->string('status')->default('pending'); // pending, scheduled, running, completed, failed
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Results
            $table->json('result')->nullable();
            $table->foreignId('agent_run_id')->nullable();

            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tasks');
        Schema::dropIfExists('weekly_plan_items');
        Schema::dropIfExists('weekly_plans');
    }
};
