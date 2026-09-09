<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key')->unique(); // e.g., 'STRIPE_SECRET_KEY'
            $table->text('encrypted_value');
            $table->string('category')->default('general'); // api_key, oauth, credential, other
            $table->text('description')->nullable();

            // Scoping
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            // Access control
            $table->json('allowed_agents')->nullable(); // ['business-strategist', 'dev-agent']
            $table->json('allowed_users')->nullable(); // [1, 2, 3] user IDs
            $table->boolean('is_sensitive')->default(true); // Extra logging for sensitive secrets

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);

            // Lifecycle
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
            $table->index(['project_id', 'is_active']);
            $table->index(['client_id', 'is_active']);
        });

        Schema::create('vault_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_secret_id')->constrained()->cascadeOnDelete();
            $table->string('accessor_type'); // 'user', 'agent', 'system'
            $table->unsignedBigInteger('accessor_id')->nullable();
            $table->string('accessor_name')->nullable();
            $table->string('action'); // 'read', 'write', 'delete', 'rotate'
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->boolean('was_successful')->default(true);
            $table->text('failure_reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['vault_secret_id', 'created_at']);
            $table->index(['accessor_type', 'accessor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_access_logs');
        Schema::dropIfExists('vault_secrets');
    }
};
