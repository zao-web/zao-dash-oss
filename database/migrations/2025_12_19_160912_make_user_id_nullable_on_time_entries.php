<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make user_id nullable to support Harvest time entries from users
     * not in the local User table. harvest_user_id/harvest_user_name
     * preserve the original Harvest user info.
     */
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // Drop foreign key constraint first
            $table->dropForeign(['user_id']);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            // Make column nullable
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('time_entries', function (Blueprint $table) {
            // Recreate foreign key with nullOnDelete
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Note: This will fail if there are null user_id values
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
