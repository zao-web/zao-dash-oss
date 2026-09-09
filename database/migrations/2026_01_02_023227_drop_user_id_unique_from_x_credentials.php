<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_credentials', function (Blueprint $table) {
            // Drop the unique constraint on user_id to allow multiple accounts
            $table->dropUnique('x_credentials_user_id_unique');
        });

        Schema::table('x_credentials', function (Blueprint $table) {
            // Add composite unique: one personal + one company per user
            $table->unique(['user_id', 'account_type'], 'x_credentials_user_account_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('x_credentials', function (Blueprint $table) {
            $table->dropUnique('x_credentials_user_account_type_unique');
        });

        Schema::table('x_credentials', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }
};
