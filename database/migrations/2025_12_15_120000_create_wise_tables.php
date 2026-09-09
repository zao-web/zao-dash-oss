<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wise connections (API access)
        Schema::create('wise_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('profile_id')->unique(); // Wise business profile ID
            $table->string('profile_type')->default('business'); // personal or business
            $table->text('api_token'); // encrypted
            $table->string('webhook_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        // Contractors (payees)
        Schema::create('contractors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // For portal login
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('company_name')->nullable();
            $table->string('country_code', 2)->default('US');
            $table->boolean('is_us_person')->default(true);

            // Tax info (encrypted)
            $table->text('tax_id_encrypted')->nullable();
            $table->string('tax_id_type')->nullable(); // ssn, ein, foreign
            $table->string('tax_id_last_four', 4)->nullable();
            $table->boolean('has_w9_on_file')->default(false);
            $table->timestamp('w9_received_at')->nullable();
            $table->string('w9_file_path')->nullable();

            // Payment settings
            $table->string('payment_type')->default('invoice'); // recurring, invoice
            $table->decimal('recurring_amount', 15, 2)->nullable();
            $table->string('recurring_currency', 3)->default('USD');
            $table->string('recurring_schedule')->nullable(); // monthly, biweekly, weekly

            // Wise recipient
            $table->string('wise_recipient_id')->nullable();
            $table->json('wise_recipient_details')->nullable();

            // Status
            $table->string('status')->default('pending'); // pending, active, suspended, terminated
            $table->string('onboarding_status')->default('invited'); // invited, info_submitted, bank_verified, complete

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('onboarding_status');
            $table->index('payment_type');
        });

        // Contractor invoices
        Schema::create('contractor_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('contractor_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->text('description')->nullable();
            $table->json('line_items')->nullable();
            $table->json('attachments')->nullable(); // file paths

            // Status workflow
            $table->string('status')->default('draft'); // draft, submitted, approved, rejected, paid
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Payment tracking
            $table->foreignId('wise_transfer_id')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index('contractor_id');
            $table->index('status');
            $table->index(['invoice_date', 'status']);
        });

        // Wise transfers (payments)
        Schema::create('wise_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wise_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contractor_invoice_id')->nullable()->constrained()->nullOnDelete();

            // Wise IDs
            $table->string('wise_transfer_id')->nullable()->unique();
            $table->string('wise_quote_id')->nullable();

            // Amounts
            $table->decimal('source_amount', 15, 2);
            $table->string('source_currency', 3)->default('USD');
            $table->decimal('target_amount', 15, 2);
            $table->string('target_currency', 3)->default('USD');
            $table->decimal('exchange_rate', 15, 8)->default(1);
            $table->decimal('fee', 15, 2)->default(0);

            // Recipient
            $table->string('recipient_id');
            $table->string('recipient_name');

            // Details
            $table->string('reference')->nullable(); // Shows on statement
            $table->text('notes')->nullable();
            $table->string('payment_type')->default('invoice'); // recurring, invoice, bonus, reimbursement

            // Status
            $table->string('status')->default('pending'); // pending, processing, funds_converted, completed, failed, cancelled

            // Approval
            $table->foreignId('approval_request_id')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index('wise_connection_id');
            $table->index('contractor_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wise_transfers');
        Schema::dropIfExists('contractor_invoices');
        Schema::dropIfExists('contractors');
        Schema::dropIfExists('wise_connections');
    }
};
