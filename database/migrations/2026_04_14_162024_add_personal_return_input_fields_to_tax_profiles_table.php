<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->decimal('mortgage_interest_paid', 12, 2)->nullable()->after('prior_year_shareholder_basis');
            $table->decimal('property_tax_paid', 12, 2)->nullable()->after('mortgage_interest_paid');
            $table->decimal('charitable_contributions_paid', 12, 2)->nullable()->after('property_tax_paid');
            $table->decimal('medical_expenses_paid', 12, 2)->nullable()->after('charitable_contributions_paid');
            $table->decimal('hsa_contributions_paid', 12, 2)->nullable()->after('medical_expenses_paid');
            $table->decimal('education_expenses_paid', 12, 2)->nullable()->after('hsa_contributions_paid');
        });
    }

    public function down(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'mortgage_interest_paid',
                'property_tax_paid',
                'charitable_contributions_paid',
                'medical_expenses_paid',
                'hsa_contributions_paid',
                'education_expenses_paid',
            ]);
        });
    }
};
