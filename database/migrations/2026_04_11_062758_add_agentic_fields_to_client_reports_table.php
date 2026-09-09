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
        Schema::table('client_reports', function (Blueprint $table) {
            $table->decimal('human_hours', 8, 2)->default(0)->after('meetings_held');
            $table->decimal('total_agent_cost_usd', 10, 4)->default(0)->after('human_hours');
            $table->integer('agent_tasks_completed')->default(0)->after('total_agent_cost_usd');
            $table->decimal('effective_margin_percent', 5, 2)->nullable()->after('agent_tasks_completed');
        });
    }

    public function down(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->dropColumn([
                'human_hours',
                'total_agent_cost_usd',
                'agent_tasks_completed',
                'effective_margin_percent',
            ]);
        });
    }
};
