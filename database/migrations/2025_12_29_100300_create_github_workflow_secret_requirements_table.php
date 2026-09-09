<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_workflow_secret_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_repo_id')->constrained()->cascadeOnDelete();
            $table->string('workflow_path');
            $table->string('workflow_name')->nullable();
            $table->string('secret_name');
            $table->boolean('is_required')->default(true);
            $table->string('job_name')->nullable();
            $table->string('job_environment_name')->nullable();
            $table->string('source')->default('parsed');
            $table->timestamp('detected_at');
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('vault_secret_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_status')->default('unmatched');
            $table->timestamps();

            $table->unique(
                ['github_repo_id', 'workflow_path', 'secret_name', 'job_environment_name'],
                'workflow_secret_req_unique'
            );
            $table->index(['github_repo_id', 'match_status']);
            $table->index(['secret_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_workflow_secret_requirements');
    }
};
