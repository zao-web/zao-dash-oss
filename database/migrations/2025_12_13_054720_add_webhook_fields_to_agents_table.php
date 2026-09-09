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
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('webhook_enabled')->default(false)->after('schedule');
            $table->string('webhook_token', 64)->nullable()->after('webhook_enabled');
            $table->json('webhook_allowed_ips')->nullable()->after('webhook_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['webhook_enabled', 'webhook_token', 'webhook_allowed_ips']);
        });
    }
};
