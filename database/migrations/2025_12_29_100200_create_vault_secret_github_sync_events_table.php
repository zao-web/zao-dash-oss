<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_secret_github_sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_repo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vault_secret_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vault_secret_github_target_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->string('github_secret_name')->nullable();
            $table->string('environment')->nullable();
            $table->string('github_environment_name')->nullable();
            $table->string('triggered_by_type')->nullable();
            $table->unsignedBigInteger('triggered_by_id')->nullable();
            $table->string('triggered_by_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['github_repo_id', 'created_at']);
            $table->index(['vault_secret_id', 'action']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_secret_github_sync_events');
    }
};
