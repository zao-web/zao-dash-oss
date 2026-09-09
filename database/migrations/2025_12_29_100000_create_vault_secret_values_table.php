<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_secret_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_secret_id')->constrained()->cascadeOnDelete();
            $table->string('environment')->nullable();
            $table->text('encrypted_value');
            $table->string('value_fingerprint', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamps();

            $table->unique(['vault_secret_id', 'environment'], 'vault_secret_env_unique');
            $table->index(['environment', 'is_active']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_secret_values');
    }
};
