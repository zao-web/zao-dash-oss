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
        Schema::table('x_bookmarks', function (Blueprint $table) {
            $table->json('enriched_content')->nullable()->after('media');
            $table->timestamp('enriched_at')->nullable()->after('enriched_content');
        });

        Schema::table('x_credentials', function (Blueprint $table) {
            $table->boolean('initial_sync_complete')->default(false)->after('last_synced_at');
            $table->string('sync_pagination_token')->nullable()->after('initial_sync_complete');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('x_bookmarks', function (Blueprint $table) {
            $table->dropColumn(['enriched_content', 'enriched_at']);
        });

        Schema::table('x_credentials', function (Blueprint $table) {
            $table->dropColumn(['initial_sync_complete', 'sync_pagination_token']);
        });
    }
};
