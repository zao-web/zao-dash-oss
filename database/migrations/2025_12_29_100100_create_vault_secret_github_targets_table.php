<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_secret_github_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_repo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vault_secret_id')->constrained()->cascadeOnDelete();
            $table->string('environment')->nullable();
            $table->string('github_secret_name');
            $table->string('github_environment_name')->nullable();
            $table->boolean('is_managed')->default(true);
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_pushed_fingerprint', 64)->nullable();
            $table->timestamp('last_seen_github_updated_at')->nullable();
            $table->string('drift_status')->default('unknown');
            $table->timestamp('drift_detected_at')->nullable();
            $table->foreignId('last_pushed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_pushed_by_agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['github_repo_id', 'vault_secret_id', 'environment', 'github_environment_name'],
                'vault_github_target_unique'
            );
            $table->index(['github_repo_id', 'drift_status']);
            $table->index(['vault_secret_id', 'is_managed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_secret_github_targets');
    }
};
