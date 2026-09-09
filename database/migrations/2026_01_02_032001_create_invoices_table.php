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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->string('number')->unique(); // ZAO-2026-001
            $table->string('subject')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('status')->default('draft'); // draft, sent, viewed, paid, overdue, cancelled, partial
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->date('issue_date');
            $table->date('due_date');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_terms')->nullable(); // Net 30, Due on Receipt, etc.
            $table->string('currency', 3)->default('USD');
            $table->string('po_number')->nullable(); // Client PO reference
            $table->foreignId('project_id')->nullable()->constrained();
            $table->bigInteger('harvest_invoice_id')->nullable()->unique(); // For imported invoices
            $table->string('paypal_invoice_id')->nullable(); // PayPal invoice reference
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
            $table->index('issue_date');
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
