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
        Schema::create('tax_return_workpapers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('tax_year');
            $table->string('status');
            $table->integer('readiness_percent')->default(0);
            $table->integer('mapped_field_count')->default(0);
            $table->integer('total_field_count')->default(0);
            $table->string('packet_hash', 64);
            $table->json('packet');
            $table->foreignId('signoff_document_id')->nullable()->constrained('financial_documents')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tax_year']);
            $table->index(['user_id', 'tax_year', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_return_workpapers');
    }
};
