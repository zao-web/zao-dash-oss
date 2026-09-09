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
            // Add account type to distinguish personal vs company accounts
            $table->string('account_type')->default('personal')->after('username');
            // personal = @JS_Zao style thought leadership
            // company = @zaowebdev style promotional
        });

        // Drop unique constraint on x_user_id if it exists (allow same X account for multiple purposes)
        // The unique constraint should now be on (user_id, x_user_id) composite
        try {
            Schema::table('x_credentials', function (Blueprint $table) {
                $table->dropUnique(['x_user_id']);
            });
        } catch (\Exception $e) {
            // Constraint may not exist
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('x_credentials', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
