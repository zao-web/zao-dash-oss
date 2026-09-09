<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookkeeping_period_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tax_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('period_key', 7);
            $table->date('period_start_date');
            $table->date('period_end_date');
            $table->string('status')->default('closed');
            $table->string('ledger_source_code')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tax_year', 'period_month'], 'bookkeeping_period_close_unique');
            $table->index(['user_id', 'tax_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeping_period_closes');
    }
};
