<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_repos', function (Blueprint $table) {
            $table->timestamp('issues_synced_at')->nullable()->after('deployment_config');
            $table->timestamp('prs_synced_at')->nullable()->after('issues_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('github_repos', function (Blueprint $table) {
            $table->dropColumn(['issues_synced_at', 'prs_synced_at']);
        });
    }
};
