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
        Schema::create('tax_agency_account_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_agency_connection_id')->constrained('tax_agency_connections')->cascadeOnDelete();
            $table->string('agency_code');
            $table->string('record_type');
            $table->string('record_key');
            $table->integer('tax_year')->nullable();
            $table->string('label');
            $table->string('status')->default('informational');
            $table->decimal('amount', 12, 2)->nullable();
            $table->date('effective_date')->nullable();
            $table->date('due_date')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['tax_agency_connection_id', 'record_type', 'record_key']);
            $table->index(['user_id', 'agency_code', 'record_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_agency_account_states');
    }
};
