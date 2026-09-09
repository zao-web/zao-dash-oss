<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('harvest_credentials', 'is_active')) {
            Schema::table('harvest_credentials', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('account_name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('harvest_credentials', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
