<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Main strategic goal (annual target)
        Schema::create('strategic_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('fiscal_year');
            $table->string('name')->nullable();
            $table->decimal('revenue_target', 14, 2);
            $table->decimal('margin_target_pct', 5, 2)->default(0);
            $table->decimal('profit_target', 14, 2)->nullable();
            $table->string('status')->default('active'); // active, achieved, missed, archived
            $table->json('assumptions')->nullable(); // avg_deal_size, win_rate, cycle_days, etc.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fiscal_year']);
        });

        // Goal broken into periods (quarterly, monthly, weekly)
        Schema::create('goal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategic_goal_id')->constrained()->onDelete('cascade');
            $table->string('period_type'); // yearly, quarterly, monthly, weekly
            $table->string('period_label')->nullable(); // Q1, Jan, Week 1, etc.
            $table->date('period_start');
            $table->date('period_end');

            // Targets (calculated from annual goal)
            $table->decimal('revenue_target', 14, 2)->default(0);
            $table->integer('leads_target')->default(0);
            $table->integer('closed_deals_target')->default(0);
            $table->decimal('pipeline_target', 14, 2)->default(0);

            // Actuals (updated by job)
            $table->decimal('revenue_actual', 14, 2)->default(0);
            $table->integer('leads_actual')->default(0);
            $table->integer('closed_deals_actual')->default(0);
            $table->decimal('pipeline_actual', 14, 2)->default(0);

            // Tracking
            $table->decimal('variance_pct', 8, 2)->nullable();
            $table->string('status')->default('pending'); // pending, on_track, ahead, behind, critical, completed
            $table->json('notes')->nullable();
            $table->timestamps();

            $table->index(['strategic_goal_id', 'period_type']);
            $table->index(['period_start', 'period_end']);
        });

        // Historical funnel metrics snapshots
        Schema::create('funnel_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('period_type'); // daily, weekly, monthly
            $table->date('period_start');
            $table->date('period_end');

            // Conversion rates by stage
            $table->decimal('new_to_qualified_rate', 5, 2)->nullable();
            $table->decimal('qualified_to_proposal_rate', 5, 2)->nullable();
            $table->decimal('proposal_to_negotiation_rate', 5, 2)->nullable();
            $table->decimal('negotiation_to_won_rate', 5, 2)->nullable();
            $table->decimal('overall_win_rate', 5, 2)->nullable();

            // Averages
            $table->decimal('avg_deal_size', 14, 2)->nullable();
            $table->integer('avg_sales_cycle_days')->nullable();
            $table->integer('avg_touches_to_close')->nullable();

            // Counts
            $table->integer('leads_created')->default(0);
            $table->integer('leads_qualified')->default(0);
            $table->integer('proposals_sent')->default(0);
            $table->integer('deals_won')->default(0);
            $table->integer('deals_lost')->default(0);
            $table->decimal('revenue_won', 14, 2)->default(0);
            $table->decimal('revenue_lost', 14, 2)->default(0);

            // Pipeline snapshot
            $table->decimal('pipeline_value', 14, 2)->default(0);
            $table->decimal('weighted_pipeline', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['period_type', 'period_start']);
            $table->index('period_end');
        });

        // Simple KPI targets (for dashboard widgets)
        Schema::create('business_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('type'); // revenue, pipeline, clients, win_rate, leads, deals
            $table->string('period'); // mtd, qtd, ytd, weekly, custom
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('target', 14, 2);
            $table->decimal('current_value', 14, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_goals');
        Schema::dropIfExists('funnel_metrics');
        Schema::dropIfExists('goal_periods');
        Schema::dropIfExists('strategic_goals');
    }
};
