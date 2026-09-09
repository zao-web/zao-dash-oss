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
        Schema::create('ad_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('adset_id')->unique()->nullable()->comment('Meta adset ID');
            $table->string('name');
            $table->enum('status', ['draft', 'pending_approval', 'active', 'paused'])->default('draft');
            $table->decimal('daily_budget', 10, 2);
            $table->enum('bid_strategy', [
                'LOWEST_COST_WITH_BID_CAP',
                'COST_CAP',
                'LOWEST_COST_WITHOUT_CAP',
            ])->default('LOWEST_COST_WITHOUT_CAP');
            $table->decimal('bid_amount', 10, 2)->nullable();
            $table->json('targeting')->comment('Full Meta targeting spec');
            $table->enum('optimization_goal', [
                'REACH',
                'IMPRESSIONS',
                'LINK_CLICKS',
                'CONVERSIONS',
                'LEAD_GENERATION',
            ]);
            $table->enum('billing_event', ['IMPRESSIONS', 'LINK_CLICKS'])->default('IMPRESSIONS');
            $table->json('attribution_setting')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('ad_campaign_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_sets');
    }
};
