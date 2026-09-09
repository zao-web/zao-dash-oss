<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'disabled', 'circuit_broken'])->default('active');
            $table->enum('model', ['opus', 'sonnet', 'haiku'])->default('sonnet');
            $table->boolean('requires_approval')->default(true);
            $table->decimal('max_budget_usd', 8, 2)->default(100.00);
            $table->integer('failure_count')->default(0);
            $table->timestamp('circuit_broken_at')->nullable();
            $table->json('allowed_tools')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
