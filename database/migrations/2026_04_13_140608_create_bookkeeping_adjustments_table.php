<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookkeeping_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->foreignId('suggested_category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->integer('tax_year');
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->string('adjustment_type');
            $table->string('source');
            $table->string('status')->default('suggested');
            $table->decimal('confidence', 4, 2)->nullable();
            $table->text('rationale')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tax_year', 'status']);
            $table->index(['personal_transaction_id', 'adjustment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeping_adjustments');
    }
};
