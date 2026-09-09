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
        Schema::table('leads', function (Blueprint $table) {
            $table->string('first_touch_page_url')->nullable()->after('email');
            $table->string('first_touch_keyword')->nullable()->after('first_touch_page_url');
            $table->string('lead_magnet_downloaded')->nullable()->after('first_touch_keyword');
            $table->string('ga4_client_id')->nullable()->after('lead_magnet_downloaded');
            $table->index('first_touch_page_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['first_touch_page_url']);
            $table->dropColumn([
                'first_touch_page_url',
                'first_touch_keyword',
                'lead_magnet_downloaded',
                'ga4_client_id',
            ]);
        });
    }
};
