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
        Schema::create('agent_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->default('general');

            // Default configuration
            $table->string('default_model')->default('sonnet');
            $table->decimal('default_budget_usd', 8, 2)->default(5.00);
            $table->boolean('default_requires_approval')->default(true);
            $table->json('default_tools')->nullable();

            // Template content
            $table->text('system_prompt_template')->nullable();
            $table->json('config_schema')->nullable();

            // Metadata
            $table->boolean('is_public')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('usage_count')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_templates');
    }
};
