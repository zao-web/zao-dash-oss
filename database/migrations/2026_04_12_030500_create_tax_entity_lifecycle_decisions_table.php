<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_entity_lifecycle_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tax_year');
            $table->string('entity_name');
            $table->string('decision');
            $table->boolean('requires_final_return')->default(false);
            $table->boolean('requires_dissolution')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tax_year', 'entity_name'], 'tax_entity_lifecycle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_entity_lifecycle_decisions');
    }
};
