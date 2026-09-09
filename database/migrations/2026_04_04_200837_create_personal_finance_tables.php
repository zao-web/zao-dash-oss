<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal bank/credit/loan accounts
        Schema::create('personal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('institution_name')->nullable();
            $table->string('account_type'); // checking, savings, credit_card, loan, collections, tax_debt, investment, other
            $table->string('account_subtype')->nullable();
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->decimal('available_balance', 14, 2)->nullable();
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->decimal('interest_rate', 5, 2)->nullable();
            $table->string('currency_code')->default('USD');
            $table->boolean('is_business')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->string('plaid_account_id')->nullable();
            $table->string('plaid_item_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'account_type']);
        });

        // Hierarchical transaction categories (null user_id = system default)
        Schema::create('transaction_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->string('name');
            $table->string('type'); // income, expense, transfer, tax_payment, debt_payment
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('tax_category')->nullable();
            $table->boolean('budget_trackable')->default(true);
            $table->timestamps();
        });

        // Individual financial transactions
        Schema::create('personal_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_account_id')->constrained()->cascadeOnDelete();
            $table->date('transaction_date');
            $table->decimal('amount', 14, 2);
            $table->string('description');
            $table->string('original_description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->string('merchant_name')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_tax_deductible')->default(false);
            $table->string('plaid_transaction_id')->nullable()->unique();
            $table->string('import_source')->default('manual'); // manual, ofx, csv, plaid
            $table->string('import_batch_id')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index(['personal_account_id', 'transaction_date']);
            $table->index('import_batch_id');
        });

        // Monthly/periodic budgets per category
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('transaction_categories')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('period_type')->default('monthly');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });

        // Debt tracking (credit cards, loans, tax debts, collections)
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('debt_type'); // credit_card, personal_loan, student_loan, tax_federal, tax_state, collections, medical, other
            $table->string('creditor_name');
            $table->decimal('original_amount', 14, 2);
            $table->decimal('current_balance', 14, 2);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->decimal('minimum_payment', 14, 2)->default(0);
            $table->integer('payment_due_day')->default(1);
            $table->foreignId('personal_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');
            $table->string('priority')->default('medium');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Payments made toward debts
        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('principal_amount', 14, 2)->default(0);
            $table->decimal('interest_amount', 14, 2)->default(0);
            $table->decimal('fees_amount', 14, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('confirmation_number')->nullable();
            $table->foreignId('personal_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // IRS/state tax obligation details
        Schema::create('tax_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->string('tax_type'); // federal_income, state_income, self_employment, payroll
            $table->integer('tax_year');
            $table->decimal('original_assessment', 14, 2);
            $table->decimal('penalties_accrued', 14, 2)->default(0);
            $table->decimal('interest_accrued', 14, 2)->default(0);
            $table->string('resolution_type')->default('none');
            $table->string('irs_notice_number')->nullable();
            $table->decimal('installment_monthly', 14, 2)->nullable();
            $table->decimal('offer_amount', 14, 2)->nullable();
            $table->string('resolution_status')->default('not_started');
            $table->date('collection_statute_expiration')->nullable();
            $table->date('next_action_date')->nullable();
            $table->string('assigned_representative')->nullable();
            $table->timestamps();
        });

        // Collections account details
        Schema::create('collections_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->string('original_creditor');
            $table->string('collection_agency');
            $table->string('agency_contact')->nullable();
            $table->string('agency_phone')->nullable();
            $table->string('agency_reference')->nullable();
            $table->date('date_sent_to_collections');
            $table->date('statute_of_limitations')->nullable();
            $table->date('last_contact_date')->nullable();
            $table->decimal('settlement_offered', 14, 2)->nullable();
            $table->decimal('settlement_accepted', 14, 2)->nullable();
            $table->boolean('dispute_filed')->default(false);
            $table->date('dispute_date')->nullable();
            $table->json('correspondence_log')->nullable();
            $table->timestamps();
        });

        // Plaid bank connection tokens
        Schema::create('plaid_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('institution_name');
            $table->string('institution_id');
            $table->text('access_token'); // encrypted in model
            $table->string('item_id')->unique();
            $table->string('cursor')->nullable();
            $table->string('status')->default('active');
            $table->string('error_code')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('consent_expiration')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('products')->nullable();
            $table->timestamps();
        });

        // Uploaded financial documents (statements, IRS notices, etc.)
        Schema::create('financial_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_type'); // bank_statement, irs_notice, collections_letter, tax_return, payment_confirmation, other
            $table->string('file_path');
            $table->string('file_name');
            $table->integer('file_size');
            $table->string('mime_type');
            $table->json('extracted_data')->nullable();
            $table->decimal('extraction_confidence', 3, 2)->nullable();
            $table->boolean('needs_review')->default(true);
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('debt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tax_obligation_id')->nullable()->constrained()->nullOnDelete();
            $table->date('effective_date')->nullable();
            $table->date('response_deadline')->nullable();
            $table->string('irs_notice_type')->nullable();
            $table->timestamps();
        });

        // Cash flow forecast entries
        Schema::create('cash_flow_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('forecast_date');
            $table->string('type'); // income, expense, debt_payment, tax_payment, transfer
            $table->string('description');
            $table->decimal('projected_amount', 14, 2);
            $table->decimal('actual_amount', 14, 2)->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_rule')->nullable();
            $table->string('source')->default('manual');
            $table->string('confidence')->default('estimated');
            $table->foreignId('personal_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('debt_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flow_forecasts');
        Schema::dropIfExists('financial_documents');
        Schema::dropIfExists('plaid_connections');
        Schema::dropIfExists('collections_accounts');
        Schema::dropIfExists('tax_obligations');
        Schema::dropIfExists('debt_payments');
        Schema::dropIfExists('debts');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('personal_transactions');
        Schema::dropIfExists('transaction_categories');
        Schema::dropIfExists('personal_accounts');
    }
};
