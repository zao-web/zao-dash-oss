<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing columns to harvest_projects that SyncHarvestJob expects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harvest_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('harvest_projects', 'client_name')) {
                $table->string('client_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('harvest_projects', 'client_harvest_id')) {
                $table->bigInteger('client_harvest_id')->nullable()->after('client_name');
            }
            if (! Schema::hasColumn('harvest_projects', 'is_fixed_fee')) {
                $table->boolean('is_fixed_fee')->default(false)->after('is_billable');
            }
            if (! Schema::hasColumn('harvest_projects', 'cost_budget')) {
                $table->decimal('cost_budget', 12, 2)->nullable()->after('budget');
            }
            if (! Schema::hasColumn('harvest_projects', 'fee')) {
                $table->decimal('fee', 12, 2)->nullable()->after('cost_budget');
            }
            if (! Schema::hasColumn('harvest_projects', 'notes')) {
                $table->text('notes')->nullable()->after('fee');
            }
            if (! Schema::hasColumn('harvest_projects', 'starts_on')) {
                $table->date('starts_on')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('harvest_projects', 'ends_on')) {
                $table->date('ends_on')->nullable()->after('starts_on');
            }
        });
    }

    public function down(): void
    {
        Schema::table('harvest_projects', function (Blueprint $table) {
            $columns = ['client_name', 'client_harvest_id', 'is_fixed_fee', 'cost_budget', 'fee', 'notes', 'starts_on', 'ends_on'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('harvest_projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
