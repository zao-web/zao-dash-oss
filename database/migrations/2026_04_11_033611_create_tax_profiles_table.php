<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('tax_year');

            // Filing info
            $table->string('filing_status')->default('single'); // single, mfj, mfs, hoh
            $table->string('resident_state', 2)->default('OR');
            $table->string('resident_city')->nullable();

            // Entity info
            $table->string('entity_type')->default('sole_prop'); // s_corp, llc, sole_prop, c_corp
            $table->string('entity_name')->nullable();
            $table->text('entity_ein_encrypted')->nullable();
            $table->string('entity_address')->nullable();
            $table->string('business_activity_code', 10)->nullable(); // NAICS code
            $table->string('accounting_method')->default('cash'); // cash, accrual
            $table->date('s_election_date')->nullable();

            // Personal info (encrypted)
            $table->text('ssn_encrypted')->nullable();
            $table->text('spouse_ssn_encrypted')->nullable();
            $table->string('spouse_name')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip', 10)->nullable();

            // Demographics
            $table->integer('age')->nullable();
            $table->integer('spouse_age')->nullable();
            $table->json('dependents')->nullable(); // [{name, ssn, relationship, age, months_lived}]

            // S-Corp specifics
            $table->decimal('reasonable_salary', 12, 2)->default(0);
            $table->decimal('w2_wages_paid', 12, 2)->default(0);

            // Prior year (for safe harbor)
            $table->decimal('prior_year_tax_liability', 12, 2)->default(0);
            $table->decimal('prior_year_agi', 12, 2)->default(0);

            // Deductions & credits
            $table->boolean('has_solo_401k')->default(false);
            $table->boolean('has_hsa')->default(false);
            $table->decimal('health_insurance_annual', 10, 2)->default(0);
            $table->integer('home_office_sqft')->default(0);
            $table->integer('home_total_sqft')->default(0);
            $table->integer('business_mileage_annual')->default(0);

            $table->timestamps();
            $table->unique(['user_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_profiles');
    }
};
