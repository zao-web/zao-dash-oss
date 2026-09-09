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
        Schema::create('sinking_funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category'); // home_improvement, furniture, vehicle, appliance, medical, education, other
            $table->decimal('target_amount', 10, 2);
            $table->decimal('current_amount', 10, 2)->default(0);
            $table->decimal('monthly_contribution', 10, 2)->default(0);
            $table->date('target_date')->nullable();
            $table->string('priority')->default('medium'); // low, medium, high, critical
            $table->string('status')->default('planning'); // planning, saving, ready, purchased, completed
            $table->text('urgency_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sinking_fund_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sinking_fund_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('contribution_date');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sinking_fund_contributions');
        Schema::dropIfExists('sinking_funds');
    }
};
