<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add hourly_rate, budget_hours to track Harvest retainer details.
 * Budget = budget_hours × hourly_rate for proper monthly retainer display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'hourly_rate')) {
                $table->decimal('hourly_rate', 10, 2)->nullable()->after('budget');
            }
            if (! Schema::hasColumn('projects', 'budget_hours')) {
                $table->decimal('budget_hours', 8, 2)->nullable()->after('hourly_rate');
            }
            if (! Schema::hasColumn('projects', 'budget_is_monthly')) {
                $table->boolean('budget_is_monthly')->default(false)->after('budget_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $columns = ['hourly_rate', 'budget_hours', 'budget_is_monthly'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
