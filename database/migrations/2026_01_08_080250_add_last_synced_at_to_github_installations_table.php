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
        Schema::table('github_installations', function (Blueprint $table) {
            if (! Schema::hasColumn('github_installations', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('sync_progress');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('github_installations', function (Blueprint $table) {
            if (Schema::hasColumn('github_installations', 'last_synced_at')) {
                $table->dropColumn('last_synced_at');
            }
        });
    }
};
