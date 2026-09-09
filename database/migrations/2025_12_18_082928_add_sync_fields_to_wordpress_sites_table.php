<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check which columns need to be added
        $columnsToAdd = [];

        if (! Schema::hasColumn('wordpress_sites', 'categories')) {
            $columnsToAdd[] = 'categories';
        }
        if (! Schema::hasColumn('wordpress_sites', 'tags')) {
            $columnsToAdd[] = 'tags';
        }
        if (! Schema::hasColumn('wordpress_sites', 'sync_status')) {
            $columnsToAdd[] = 'sync_status';
        }
        if (! Schema::hasColumn('wordpress_sites', 'sync_started_at')) {
            $columnsToAdd[] = 'sync_started_at';
        }
        if (! Schema::hasColumn('wordpress_sites', 'sync_completed_at')) {
            $columnsToAdd[] = 'sync_completed_at';
        }
        if (! Schema::hasColumn('wordpress_sites', 'sync_error')) {
            $columnsToAdd[] = 'sync_error';
        }
        if (! Schema::hasColumn('wordpress_sites', 'sync_progress')) {
            $columnsToAdd[] = 'sync_progress';
        }
        if (! Schema::hasColumn('wordpress_sites', 'last_synced_at')) {
            $columnsToAdd[] = 'last_synced_at';
        }

        if (empty($columnsToAdd)) {
            return; // All columns already exist
        }

        Schema::table('wordpress_sites', function (Blueprint $table) use ($columnsToAdd) {
            if (in_array('categories', $columnsToAdd)) {
                $table->json('categories')->nullable();
            }
            if (in_array('tags', $columnsToAdd)) {
                $table->json('tags')->nullable();
            }
            if (in_array('sync_status', $columnsToAdd)) {
                $table->string('sync_status', 20)->nullable();
            }
            if (in_array('sync_started_at', $columnsToAdd)) {
                $table->timestamp('sync_started_at')->nullable();
            }
            if (in_array('sync_completed_at', $columnsToAdd)) {
                $table->timestamp('sync_completed_at')->nullable();
            }
            if (in_array('sync_error', $columnsToAdd)) {
                $table->text('sync_error')->nullable();
            }
            if (in_array('sync_progress', $columnsToAdd)) {
                $table->integer('sync_progress')->default(0);
            }
            if (in_array('last_synced_at', $columnsToAdd)) {
                $table->timestamp('last_synced_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table) {
            $table->dropColumn([
                'categories',
                'tags',
                'sync_status',
                'sync_started_at',
                'sync_completed_at',
                'sync_error',
                'sync_progress',
                'last_synced_at',
            ]);
        });
    }
};
