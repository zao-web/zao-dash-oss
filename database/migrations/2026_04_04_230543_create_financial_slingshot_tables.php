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
        Schema::create('financial_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('alert_type');
            $table->string('severity');
            $table->string('title');
            $table->text('description');
            $table->text('action_text')->nullable();
            $table->dateTime('deadline')->nullable();
            $table->decimal('dollar_impact', 14, 2)->nullable();
            $table->decimal('dollar_cost_of_inaction', 14, 2)->nullable();
            $table->string('related_model_type')->nullable();
            $table->unsignedBigInteger('related_model_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('open');
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('slack_message_ts')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'severity']);
            $table->index(['alert_type', 'status']);
        });

        Schema::create('cash_waterfall_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('trigger_type');
            $table->string('trigger_description')->nullable();
            $table->decimal('income_amount', 14, 2);
            $table->decimal('operating_reserve', 14, 2)->default(0);
            $table->decimal('tax_reserve', 14, 2)->default(0);
            $table->decimal('irs_installment', 14, 2)->default(0);
            $table->decimal('debt_payments', 14, 2)->default(0);
            $table->decimal('owner_draw', 14, 2)->default(0);
            $table->decimal('investment', 14, 2)->default(0);
            $table->json('allocation_details')->nullable();
            $table->boolean('is_simulation')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_waterfall_allocations');
        Schema::dropIfExists('financial_alerts');
    }
};
