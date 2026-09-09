<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_projects', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('project_type', ['autonomous', 'guided', 'migration', 'redesign']);

            $table->enum('source_type', ['domain', 'brief', 'url', 'github', 'manual'])->nullable();
            $table->text('source_data')->nullable();

            $table->string('domain')->nullable();
            $table->enum('hosting_type', ['wordpress_com', 'self_hosted', 'existing_site'])->nullable();
            $table->enum('environment', ['staging', 'production'])->default('staging');
            $table->foreignId('wordpress_site_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('status', [
                'created', 'analyzing', 'designing', 'building',
                'reviewing', 'deploying', 'complete', 'failed',
            ])->default('created');

            $table->json('phase_progress')->nullable();
            $table->integer('overall_progress')->default(0);

            $table->json('design_config')->nullable();
            $table->json('brand_assets')->nullable();

            $table->json('pages')->nullable();
            $table->json('patterns_selected')->nullable();
            $table->json('custom_blocks')->nullable();

            $table->json('site_analysis')->nullable();
            $table->json('repo_analysis')->nullable();
            $table->json('extracted_content')->nullable();

            $table->json('agent_runs')->nullable();
            $table->json('tool_executions')->nullable();

            $table->string('staging_url')->nullable();
            $table->string('production_url')->nullable();
            $table->string('download_url')->nullable();
            $table->json('client_credentials')->nullable();

            $table->text('last_error')->nullable();
            $table->integer('retry_count')->default(0);

            $table->decimal('budget_allocated', 8, 2)->nullable();
            $table->decimal('cost_incurred', 8, 2)->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('estimated_completion')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['project_type', 'status']);
            $table->index('slug');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_projects');
    }
};
