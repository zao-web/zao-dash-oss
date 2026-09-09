<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimated_tax_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('tax_year');
            $table->integer('quarter'); // 1-4
            $table->string('jurisdiction'); // federal, state_or, local_portland, local_multnomah
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method')->nullable(); // eftps, direct_pay, check, state_portal, auto_detected
            $table->string('confirmation_number')->nullable();
            $table->string('status')->default('confirmed'); // confirmed, pending_confirmation, auto_detected
            $table->foreignId('personal_transaction_id')->nullable()->constrained('personal_transactions')->nullOnDelete();
            $table->foreignId('financial_document_id')->nullable()->constrained('financial_documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tax_year', 'quarter']);
            $table->index(['user_id', 'tax_year', 'jurisdiction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimated_tax_payments');
    }
};
