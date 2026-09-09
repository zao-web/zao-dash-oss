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
        Schema::table('x_credentials', function (Blueprint $table) {
            $table->timestamp('rate_limit_reset_at')->nullable()->after('last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('x_credentials', function (Blueprint $table) {
            $table->dropColumn('rate_limit_reset_at');
        });
    }
};
