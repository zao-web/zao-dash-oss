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
        Schema::table('video_views', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('country');
            $table->string('region', 100)->nullable()->after('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_views', function (Blueprint $table) {
            $table->dropColumn(['city', 'region']);
        });
    }
};
