<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_repos', function (Blueprint $table) {
            $table->timestamp('pushed_at')->nullable()->after('default_branch');
            $table->boolean('is_archived')->default(false)->after('is_private');
            $table->index('pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('github_repos', function (Blueprint $table) {
            $table->dropIndex(['pushed_at']);
            $table->dropColumn(['pushed_at', 'is_archived']);
        });
    }
};
