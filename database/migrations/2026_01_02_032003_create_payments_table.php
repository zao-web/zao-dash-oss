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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method'); // paypal, ach, check, wire, credit_card, other
            $table->string('transaction_id')->nullable(); // PayPal/Stripe transaction ID
            $table->string('reference')->nullable(); // Check number, wire reference, etc.
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->string('status')->default('completed'); // pending, completed, failed, refunded
            $table->json('metadata')->nullable(); // Store raw PayPal/Stripe response
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('transaction_id');
            $table->index('payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
