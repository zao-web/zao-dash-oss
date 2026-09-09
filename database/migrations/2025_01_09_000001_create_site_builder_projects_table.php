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
        Schema::create('site_builder_projects', function (Blueprint $table) {
            $table->id();

            // Project identification
            $table->string('domain');
            $table->string('project_name')->nullable();
            $table->text('brief');

            // Project metadata
            $table->enum('company_type', ['active', 'defunct', 'startup', 'enterprise']);
            $table->enum('status', [
                'created', 'research', 'wordpress_setup', 'content_generation',
                'content_sync', 'qa', 'complete', 'failed',
            ])->default('created');
            $table->enum('environment', ['staging', 'production'])->default('staging');
            $table->enum('target_hosting', ['wordpress_com', 'self_hosted', 'existing_site']);

            // Associated resources
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('wordpress_site_id')->nullable();
            $table->json('agent_runs')->nullable(); // Track which agents were used

            // Progress tracking
            $table->json('progress_data')->nullable(); // Detailed progress information
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('estimated_completion')->nullable();

            // URLs and access
            $table->string('staging_url')->nullable();
            $table->string('production_url')->nullable();
            $table->json('client_credentials')->nullable(); // WordPress login info

            // Research results
            $table->json('research_data')->nullable();

            // Error handling
            $table->text('last_error')->nullable();
            $table->integer('retry_count')->default(0);

            // Cost tracking
            $table->decimal('budget_allocated', 8, 2)->nullable();
            $table->decimal('cost_incurred', 8, 2)->default(0);

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index('domain');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_builder_projects');
    }
};
