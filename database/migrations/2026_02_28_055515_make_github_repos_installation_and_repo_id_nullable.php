<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_repos', function (Blueprint $table) {
            $table->foreignId('installation_id')->nullable()->change();
            $table->bigInteger('repo_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('github_repos', function (Blueprint $table) {
            $table->foreignId('installation_id')->nullable(false)->change();
            $table->bigInteger('repo_id')->nullable(false)->change();
        });
    }
};
