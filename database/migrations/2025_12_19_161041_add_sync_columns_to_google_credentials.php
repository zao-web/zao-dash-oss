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
        Schema::table('google_credentials', function (Blueprint $table) {
            if (! Schema::hasColumn('google_credentials', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable();
            }
            if (! Schema::hasColumn('google_credentials', 'sync_status')) {
                $table->string('sync_status')->nullable();
            }
            if (! Schema::hasColumn('google_credentials', 'sync_error')) {
                $table->text('sync_error')->nullable();
            }
            if (! Schema::hasColumn('google_credentials', 'sync_started_at')) {
                $table->timestamp('sync_started_at')->nullable();
            }
            if (! Schema::hasColumn('google_credentials', 'sync_completed_at')) {
                $table->timestamp('sync_completed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('google_credentials', function (Blueprint $table) {
            $cols = ['last_synced_at', 'sync_status', 'sync_error', 'sync_started_at', 'sync_completed_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('google_credentials', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
