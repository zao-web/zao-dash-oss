<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // QuickBooks Online connections
        Schema::create('quickbooks_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('realm_id')->unique();
            $table->string('company_name');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('access_token_expires_at');
            $table->timestamp('refresh_token_expires_at');
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('sync_enabled')->default(true);
            $table->timestamps();
        });

        // Chart of Accounts
        Schema::create('qbo_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qbo_connection_id')->constrained('quickbooks_connections')->cascadeOnDelete();
            $table->string('qbo_id', 50);
            $table->string('name');
            $table->string('account_type', 100);
            $table->string('account_sub_type', 100)->nullable();
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->boolean('active')->default(true);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('qbo_connection_id');
            $table->index('account_type');
            $table->unique(['qbo_connection_id', 'qbo_id']);
        });

        // Transactions (expenses, deposits, transfers)
        Schema::create('qbo_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qbo_connection_id')->constrained('quickbooks_connections')->cascadeOnDelete();
            $table->string('qbo_id', 50);
            $table->string('txn_type', 50);
            $table->date('txn_date');
            $table->decimal('amount', 15, 2);
            $table->foreignId('account_id')->nullable()->constrained('qbo_accounts')->nullOnDelete();
            $table->string('customer_id', 50)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('vendor_id', 50)->nullable();
            $table->string('vendor_name')->nullable();
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('qbo_connection_id');
            $table->index('txn_type');
            $table->index('txn_date');
            $table->index('client_id');
            $table->unique(['qbo_connection_id', 'qbo_id', 'txn_type']);
        });

        // Invoices
        Schema::create('qbo_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qbo_connection_id')->constrained('quickbooks_connections')->cascadeOnDelete();
            $table->string('qbo_id', 50);
            $table->string('doc_number', 50)->nullable();
            $table->string('customer_id', 50);
            $table->string('customer_name');
            $table->date('txn_date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('status', 50);
            $table->string('email_status', 50)->nullable();
            $table->json('line_items')->nullable();
            $table->foreignId('harvest_invoice_id')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('qbo_connection_id');
            $table->index('status');
            $table->index('customer_id');
            $table->unique(['qbo_connection_id', 'qbo_id']);
        });

        // Customers
        Schema::create('qbo_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qbo_connection_id')->constrained('quickbooks_connections')->cascadeOnDelete();
            $table->string('qbo_id', 50);
            $table->string('display_name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->decimal('balance', 15, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('qbo_connection_id');
            $table->index('client_id');
            $table->unique(['qbo_connection_id', 'qbo_id']);
        });

        // Financial snapshots for analysis
        Schema::create('financial_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qbo_connection_id')->constrained('quickbooks_connections')->cascadeOnDelete();
            $table->string('period_type', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_income', 15, 2)->default(0);
            $table->decimal('total_expenses', 15, 2)->default(0);
            $table->decimal('net_profit', 15, 2)->default(0);
            $table->decimal('accounts_receivable', 15, 2)->default(0);
            $table->decimal('accounts_payable', 15, 2)->default(0);
            $table->decimal('cash_on_hand', 15, 2)->default(0);
            $table->integer('runway_days')->nullable();
            $table->json('top_expense_categories')->nullable();
            $table->json('top_income_sources')->nullable();
            $table->json('insights')->nullable();
            $table->timestamps();

            $table->index('qbo_connection_id');
            $table->index(['period_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_snapshots');
        Schema::dropIfExists('qbo_customers');
        Schema::dropIfExists('qbo_invoices');
        Schema::dropIfExists('qbo_transactions');
        Schema::dropIfExists('qbo_accounts');
        Schema::dropIfExists('quickbooks_connections');
    }
};
