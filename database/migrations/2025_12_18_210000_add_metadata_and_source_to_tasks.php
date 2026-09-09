<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add metadata column and expand source enum for tasks table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add metadata column if missing
        if (! Schema::hasColumn('tasks', 'metadata')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('source_session_id');
            });
        }

        // PostgreSQL: Convert source to varchar to support more values
        // The enum constraint is too restrictive for our use cases
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks ALTER COLUMN source TYPE varchar(50)');
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
