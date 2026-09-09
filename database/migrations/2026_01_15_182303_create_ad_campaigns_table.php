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
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_ad_account_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_id')->unique()->nullable()->comment('Meta campaign ID');
            $table->foreignId('client_id')->constrained()->cascadeOnDelete()->comment('For filtering');
            $table->string('name');
            $table->enum('objective', [
                'OUTCOME_AWARENESS',
                'OUTCOME_ENGAGEMENT',
                'OUTCOME_LEADS',
                'OUTCOME_SALES',
                'OUTCOME_TRAFFIC',
            ]);
            $table->enum('status', ['draft', 'pending_approval', 'active', 'paused', 'completed', 'failed'])->default('draft');
            $table->decimal('daily_budget', 10, 2)->nullable();
            $table->decimal('lifetime_budget', 10, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->json('targeting_config')->comment('ICP, location, demographics');
            $table->boolean('automation_enabled')->default(true);
            $table->json('pause_threshold')->nullable()->comment('e.g. {cpa_max: 50, ctr_min: 0.01}');
            $table->json('performance_goal')->nullable()->comment('e.g. {target_cpa: 25, target_roas: 3.0}');
            $table->foreignId('created_by_agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('meta_ad_account_id');
            $table->index('client_id');
            $table->index('status');
            $table->index('created_by_agent_run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
