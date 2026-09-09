<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_credentials', function (Blueprint $table) {
            if (! Schema::hasColumn('google_credentials', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('google_credentials', function (Blueprint $table) {
            if (Schema::hasColumn('google_credentials', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
