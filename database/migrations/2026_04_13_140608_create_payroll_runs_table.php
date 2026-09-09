<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('tax_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('pay_frequency');
            $table->string('status')->default('planned');
            $table->date('pay_date');
            $table->decimal('gross_pay', 14, 2);
            $table->decimal('federal_withholding', 14, 2)->default(0);
            $table->decimal('oregon_withholding', 14, 2)->default(0);
            $table->decimal('employee_fica', 14, 2)->default(0);
            $table->decimal('employer_fica', 14, 2)->default(0);
            $table->decimal('statewide_transit_tax', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2)->default(0);
            $table->decimal('federal_deposit_amount', 14, 2)->default(0);
            $table->decimal('oregon_deposit_amount', 14, 2)->default(0);
            $table->date('federal_deposit_due')->nullable();
            $table->date('oregon_deposit_due')->nullable();
            $table->string('federal_deposit_status')->default('pending');
            $table->string('oregon_deposit_status')->default('pending');
            $table->text('notes')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('federal_deposit_completed_at')->nullable();
            $table->timestamp('oregon_deposit_completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tax_year', 'period_month', 'pay_frequency']);
            $table->index(['user_id', 'tax_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
