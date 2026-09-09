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
        Schema::table('retainer_periods', function (Blueprint $table) {
            $table->string('tier')->nullable()->after('status');
            $table->decimal('monthly_amount', 12, 2)->nullable()->after('tier');
            $table->json('included_services')->nullable()->after('monthly_amount');
            $table->decimal('internal_hourly_rate', 8, 2)->default(250.00)->after('included_services');
            $table->decimal('ai_equivalent_hourly_rate', 8, 2)->default(50.00)->after('internal_hourly_rate');
            $table->decimal('agent_cost_usd', 10, 4)->default(0)->after('ai_equivalent_hourly_rate');
            $table->integer('agent_tasks_completed')->default(0)->after('agent_cost_usd');
            $table->decimal('effective_margin_percent', 5, 2)->nullable()->after('agent_tasks_completed');
            $table->string('health_status')->default('healthy')->after('effective_margin_percent');
            $table->timestamp('last_client_activity_at')->nullable()->after('health_status');

            $table->index(['health_status', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::table('retainer_periods', function (Blueprint $table) {
            $table->dropIndex(['health_status', 'period_start']);
            $table->dropColumn([
                'tier',
                'monthly_amount',
                'included_services',
                'internal_hourly_rate',
                'ai_equivalent_hourly_rate',
                'agent_cost_usd',
                'agent_tasks_completed',
                'effective_margin_percent',
                'health_status',
                'last_client_activity_at',
            ]);
        });
    }
};
