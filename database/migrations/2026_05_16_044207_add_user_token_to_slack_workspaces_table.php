<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slack_workspaces', function (Blueprint $table) {
            $table->text('user_access_token')->nullable()->after('access_token');
            $table->string('authed_user_id')->nullable()->after('user_access_token');
        });
    }

    public function down(): void
    {
        Schema::table('slack_workspaces', function (Blueprint $table) {
            $table->dropColumn(['user_access_token', 'authed_user_id']);
        });
    }
};
