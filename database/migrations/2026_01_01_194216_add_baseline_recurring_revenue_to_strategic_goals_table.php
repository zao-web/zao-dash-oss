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
        Schema::table('strategic_goals', function (Blueprint $table) {
            // Monthly recurring revenue (e.g., retainers, subscriptions)
            $table->decimal('monthly_recurring_revenue', 12, 2)->default(0)->after('profit_target');
            // Number of months this MRR is expected (defaults to 12 for annual)
            $table->unsignedTinyInteger('mrr_months')->default(12)->after('monthly_recurring_revenue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('strategic_goals', function (Blueprint $table) {
            $table->dropColumn(['monthly_recurring_revenue', 'mrr_months']);
        });
    }
};
