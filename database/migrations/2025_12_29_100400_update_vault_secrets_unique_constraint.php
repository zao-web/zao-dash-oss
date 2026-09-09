<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vault_secrets', function (Blueprint $table) {
            // Drop the simple unique constraint on key
            $table->dropUnique(['key']);

            // Add composite unique constraint allowing same key with different scopes
            // This enables: project-level > client-level > global secret precedence
            $table->unique(['key', 'project_id', 'client_id'], 'vault_secrets_key_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vault_secrets', function (Blueprint $table) {
            $table->dropUnique('vault_secrets_key_scope_unique');
            $table->unique('key');
        });
    }
};
