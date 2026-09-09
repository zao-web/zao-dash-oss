<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ONE overarching life goal that everything serves
        Schema::create('north_star_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title'); // "Chehalem Mountain Dream Home"
            $table->text('description')->nullable(); // The full vision
            $table->text('why')->nullable(); // WHY this matters — motivation for hard days
            $table->decimal('total_cost_estimate', 14, 2)->nullable(); // Total $ needed
            $table->date('target_date')->nullable();
            $table->string('status')->default('active'); // active, achieved, paused
            $table->json('imagery')->nullable(); // URLs to vision board images
            $table->timestamps();
        });

        // Milestones on the path to the North Star
        Schema::create('north_star_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('north_star_goal_id')->constrained()->cascadeOnDelete();
            $table->string('title'); // "Become completely debt free"
            $table->text('description')->nullable();
            $table->integer('order')->default(0); // Sequence on the pathway
            $table->string('milestone_type')->default('financial'); // financial, action, lifestyle
            $table->decimal('target_amount', 14, 2)->nullable(); // $ target if financial
            $table->decimal('current_amount', 14, 2)->default(0); // Current progress
            $table->string('tracking_method')->default('manual'); // manual, debt_total, savings_total, net_worth, custom
            $table->json('tracking_config')->nullable(); // Config for auto-tracking (e.g., which debts, which accounts)
            $table->date('target_date')->nullable();
            $table->date('estimated_completion')->nullable(); // AI-projected based on velocity
            $table->date('completed_at')->nullable();
            $table->string('status')->default('pending'); // pending, in_progress, completed, blocked
            $table->json('celebration')->nullable(); // What to do when achieved
            $table->timestamps();
        });

        // Daily progress snapshots for trend visualization
        Schema::create('north_star_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('north_star_goal_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('overall_progress_percent', 5, 2)->default(0);
            $table->json('milestone_progress')->nullable(); // Per-milestone snapshot
            $table->decimal('days_ahead_behind', 8, 1)->default(0); // + = ahead, - = behind schedule
            $table->text('ai_insight')->nullable(); // Daily insight about trajectory
            $table->timestamps();

            $table->unique(['north_star_goal_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('north_star_progress');
        Schema::dropIfExists('north_star_milestones');
        Schema::dropIfExists('north_star_goals');
    }
};
