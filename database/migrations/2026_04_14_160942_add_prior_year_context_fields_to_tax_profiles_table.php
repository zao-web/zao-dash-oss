<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->decimal('prior_year_federal_overpayment_applied', 12, 2)->nullable()->after('prior_year_agi');
            $table->decimal('prior_year_oregon_overpayment_applied', 12, 2)->nullable()->after('prior_year_federal_overpayment_applied');
            $table->decimal('prior_year_capital_loss_carryforward', 12, 2)->nullable()->after('prior_year_oregon_overpayment_applied');
            $table->decimal('prior_year_nol_carryforward', 12, 2)->nullable()->after('prior_year_capital_loss_carryforward');
            $table->decimal('prior_year_shareholder_basis', 12, 2)->nullable()->after('prior_year_nol_carryforward');
        });
    }

    public function down(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'prior_year_federal_overpayment_applied',
                'prior_year_oregon_overpayment_applied',
                'prior_year_capital_loss_carryforward',
                'prior_year_nol_carryforward',
                'prior_year_shareholder_basis',
            ]);
        });
    }
};
