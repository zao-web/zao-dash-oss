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
        Schema::create('pm_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform'); // 'clickup', 'notion', 'asana', etc.
            $table->string('workspace_id')->nullable();
            $table->string('workspace_name')->nullable();
            $table->text('access_token'); // Encrypted
            $table->text('refresh_token')->nullable(); // Encrypted (for OAuth platforms)
            $table->timestamp('token_expires_at')->nullable();
            $table->json('settings')->nullable(); // Platform-specific config
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'workspace_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pm_connections');
    }
};
