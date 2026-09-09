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
        Schema::create('tax_optimization_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('tax_year');
            $table->string('scenario_type'); // current, optimized, what_if
            $table->decimal('gross_income', 14, 2);
            $table->decimal('s_corp_salary', 14, 2);
            $table->decimal('distributions', 14, 2);
            $table->json('retirement_contributions')->nullable();
            $table->json('real_estate_deductions')->nullable();
            $table->decimal('rd_credit_amount', 14, 2)->default(0);
            $table->json('deductions')->nullable();
            $table->decimal('qbi_deduction', 14, 2)->default(0);
            $table->decimal('total_taxable_income', 14, 2);
            $table->decimal('federal_tax', 14, 2);
            $table->decimal('state_tax', 14, 2);
            $table->decimal('se_tax', 14, 2);
            $table->decimal('fica_tax', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2);
            $table->decimal('effective_rate', 5, 2);
            $table->json('strategies_applied')->nullable();
            $table->foreignId('comparison_baseline_id')->nullable()->constrained('tax_optimization_scenarios')->nullOnDelete();
            $table->decimal('savings_vs_baseline', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('retirement_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('account_type'); // solo_401k, sep_ira, defined_benefit, traditional_ira, roth_ira, hsa
            $table->string('institution_name')->nullable();
            $table->string('account_name');
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->decimal('ytd_contributions', 14, 2)->default(0);
            $table->decimal('employee_deferral_ytd', 14, 2)->default(0);
            $table->decimal('employer_match_ytd', 14, 2)->default(0);
            $table->decimal('annual_limit', 14, 2);
            $table->integer('tax_year');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('real_estate_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('property_type'); // residential, commercial, str, land
            $table->decimal('purchase_price', 14, 2);
            $table->date('purchase_date');
            $table->decimal('land_value', 14, 2)->default(0);
            $table->decimal('building_value', 14, 2)->default(0);
            $table->decimal('fair_market_value', 14, 2)->nullable();
            $table->boolean('cost_segregation_done')->default(false);
            $table->json('depreciation_schedule')->nullable();
            $table->decimal('annual_depreciation', 14, 2)->default(0);
            $table->decimal('accumulated_depreciation', 14, 2)->default(0);
            $table->boolean('is_str')->default(false);
            $table->decimal('average_stay_days', 5, 1)->nullable();
            $table->decimal('material_participation_hours', 8, 1)->default(0);
            $table->decimal('rental_income_annual', 14, 2)->default(0);
            $table->decimal('rental_expenses_annual', 14, 2)->default(0);
            $table->boolean('opportunity_zone')->default(false);
            $table->date('oz_investment_date')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rd_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('activity_date');
            $table->decimal('hours', 5, 2);
            $table->text('description');
            $table->boolean('qualifies_for_rd')->default(false);
            $table->string('qualification_reason')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('wage_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['user_id', 'activity_date', 'qualifies_for_rd']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rd_activity_logs');
        Schema::dropIfExists('real_estate_properties');
        Schema::dropIfExists('retirement_accounts');
        Schema::dropIfExists('tax_optimization_scenarios');
    }
};
