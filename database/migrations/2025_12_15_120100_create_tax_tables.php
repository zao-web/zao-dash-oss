<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tax calendar events (deadlines, reminders)
        Schema::create('tax_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type'); // quarterly_estimate, 1099_filing, annual_return, extension
            $table->integer('tax_year');
            $table->integer('quarter')->nullable(); // 1-4 for quarterly estimates
            $table->date('due_date');
            $table->date('reminder_date')->nullable();
            $table->string('form_type')->nullable(); // 1040-ES, 1099-NEC, 1120-S, 1040
            $table->string('filing_jurisdiction')->default('federal'); // federal, state
            $table->string('state_code', 2)->nullable();
            $table->decimal('estimated_amount', 15, 2)->nullable();
            $table->string('status')->default('upcoming'); // upcoming, reminder_sent, completed, overdue
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tax_year']);
            $table->index(['due_date', 'status']);
            $table->index('event_type');
        });

        // 1099 contractor data (aggregated from QBO)
        Schema::create('contractor_1099_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qbo_connection_id')->constrained('quickbooks_connections')->cascadeOnDelete();
            $table->integer('tax_year');
            $table->string('vendor_id', 50);
            $table->string('vendor_name');
            $table->string('vendor_type')->nullable(); // 1099Vendor, IndependentContractor, etc
            $table->decimal('total_payments', 15, 2)->default(0);
            $table->boolean('requires_1099')->default(false); // true if >= $600
            $table->boolean('has_w9')->default(false);
            $table->string('tin_type')->nullable(); // ssn, ein
            $table->string('tin_last_four', 4)->nullable();
            $table->json('payment_breakdown')->nullable(); // monthly breakdown
            $table->string('status')->default('pending'); // pending, w9_needed, ready, filed
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['qbo_connection_id', 'tax_year']);
            $table->index(['tax_year', 'requires_1099']);
            $table->unique(['qbo_connection_id', 'tax_year', 'vendor_id']);
        });

        // Tax estimates (quarterly calculations)
        Schema::create('tax_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qbo_connection_id')->constrained('quickbooks_connections')->cascadeOnDelete();
            $table->integer('tax_year');
            $table->integer('quarter'); // 1-4
            $table->date('calculation_date');

            // YTD financials
            $table->decimal('ytd_gross_income', 15, 2)->default(0);
            $table->decimal('ytd_deductions', 15, 2)->default(0);
            $table->decimal('ytd_net_income', 15, 2)->default(0);

            // Projections
            $table->decimal('projected_annual_income', 15, 2)->default(0);
            $table->decimal('projected_annual_tax', 15, 2)->default(0);
            $table->decimal('quarterly_payment_due', 15, 2)->default(0);
            $table->decimal('ytd_payments_made', 15, 2)->default(0);

            // Calculation details
            $table->json('calculation_breakdown')->nullable();
            $table->string('entity_type')->default('s_corp'); // s_corp, llc, sole_prop, c_corp
            $table->decimal('effective_tax_rate', 5, 2)->nullable();
            $table->decimal('self_employment_tax', 15, 2)->default(0);

            // State estimates
            $table->string('state_code', 2)->nullable();
            $table->decimal('state_tax_estimate', 15, 2)->default(0);

            $table->timestamps();

            $table->index(['qbo_connection_id', 'tax_year']);
            $table->unique(['qbo_connection_id', 'tax_year', 'quarter']);
        });

        // Tax strategies (optimization recommendations)
        Schema::create('tax_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qbo_connection_id')->constrained('quickbooks_connections')->cascadeOnDelete();
            $table->integer('tax_year');

            // Strategy details
            $table->string('strategy_type'); // salary_optimization, retirement_contribution, section_179, etc
            $table->string('title');
            $table->text('description');
            $table->decimal('estimated_savings', 15, 2)->default(0);

            // Implementation
            $table->string('timing_sensitivity')->default('flexible'); // urgent, year_end, quarterly, flexible
            $table->string('complexity')->default('medium'); // low, medium, high
            $table->json('requirements')->nullable(); // prerequisites
            $table->json('action_items')->nullable(); // steps to implement

            // Status
            $table->string('status')->default('identified'); // identified, implementing, completed, dismissed
            $table->text('dismissal_reason')->nullable();
            $table->timestamp('implemented_at')->nullable();

            $table->timestamps();

            $table->index(['qbo_connection_id', 'tax_year']);
            $table->index(['status', 'timing_sensitivity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_strategies');
        Schema::dropIfExists('tax_estimates');
        Schema::dropIfExists('contractor_1099_data');
        Schema::dropIfExists('tax_calendar_events');
    }
};
