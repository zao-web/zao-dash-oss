<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GitHub App installations (org or user level)
        Schema::create('github_installations', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('installation_id')->unique();
            $table->string('account_type'); // org, user
            $table->string('account_login');
            $table->bigInteger('account_id');
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('permissions')->nullable();
            $table->string('repos_access')->default('all'); // all, selected
            $table->timestamp('connected_at');
            $table->timestamps();

            $table->index('account_login');
        });

        // GitHub repos being tracked
        Schema::create('github_repos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained('github_installations')->cascadeOnDelete();
            $table->bigInteger('repo_id')->unique();
            $table->string('owner');
            $table->string('name');
            $table->string('full_name');
            $table->boolean('is_private')->default(false);
            $table->string('default_branch')->default('main');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('monitoring_enabled')->default(true);
            $table->string('deployment_type')->nullable(); // sftp, vercel, netlify, none
            $table->json('deployment_config')->nullable();
            $table->timestamps();

            $table->index('full_name');
            $table->index('client_id');
            $table->index('monitoring_enabled');
        });

        // GitHub issues synced with tasks
        Schema::create('github_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repo_id')->constrained('github_repos')->cascadeOnDelete();
            $table->integer('issue_number');
            $table->bigInteger('issue_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('state')->default('open'); // open, closed
            $table->json('labels')->nullable();
            $table->json('assignees')->nullable();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['repo_id', 'issue_number']);
            $table->index('state');
            $table->index('task_id');
        });

        // GitHub pull requests
        Schema::create('github_pull_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repo_id')->constrained('github_repos')->cascadeOnDelete();
            $table->integer('pr_number');
            $table->bigInteger('pr_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('state')->default('open'); // open, closed, merged
            $table->string('base_branch');
            $table->string('head_branch');
            $table->string('author');
            $table->json('reviewers')->nullable();
            $table->string('approval_status')->default('pending'); // pending, approved, rejected
            $table->foreignId('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('qa_agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->boolean('checks_passed')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();

            $table->unique(['repo_id', 'pr_number']);
            $table->index('state');
            $table->index('approval_status');
        });

        // Deployment configurations for client repos
        Schema::create('deployment_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repo_id')->constrained('github_repos')->cascadeOnDelete();
            $table->string('deployment_type'); // sftp, vercel, netlify, wordpress
            $table->string('hosting_provider')->nullable();
            $table->string('staging_url')->nullable();
            $table->string('production_url')->nullable();
            $table->string('build_command')->nullable();
            $table->string('build_output_dir')->nullable();
            $table->json('secrets')->nullable(); // references to vault, not actual values
            $table->string('workflow_file_path')->nullable();
            $table->boolean('onboarding_completed')->default(false);
            $table->foreignId('onboarding_agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'repo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_configs');
        Schema::dropIfExists('github_pull_requests');
        Schema::dropIfExists('github_issues');
        Schema::dropIfExists('github_repos');
        Schema::dropIfExists('github_installations');
    }
};
