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
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('time'); // time, fixed, expense, discount, tax
            $table->string('description');
            $table->text('details')->nullable(); // Additional line details
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit')->nullable(); // hours, items, etc.
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->boolean('taxable')->default(true);
            $table->foreignId('time_entry_id')->nullable()->constrained();
            $table->foreignId('project_id')->nullable()->constrained();
            $table->date('service_date')->nullable(); // Date service was performed
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
