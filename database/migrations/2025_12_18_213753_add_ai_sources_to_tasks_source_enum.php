<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'ai', 'self_development', 'clickup', 'github' sources to tasks table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: Drop and recreate check constraint with new values
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY['manual'::text, 'meeting-parser'::text, 'agent'::text, 'video'::text, 'ai'::text, 'self_development'::text, 'clickup'::text, 'github'::text]))");
        } elseif ($driver === 'mysql') {
            // MySQL: Modify the enum column
            DB::statement("ALTER TABLE tasks MODIFY COLUMN source ENUM('manual', 'meeting-parser', 'agent', 'video', 'ai', 'self_development', 'clickup', 'github') DEFAULT 'manual'");
        }
        // SQLite: no-op (TEXT column, no constraint)
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Migrate any new values back to 'agent' before removing
            DB::statement("UPDATE tasks SET source = 'agent' WHERE source IN ('ai', 'self_development', 'clickup', 'github')");
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY['manual'::text, 'meeting-parser'::text, 'agent'::text, 'video'::text]))");
        } elseif ($driver === 'mysql') {
            DB::statement("UPDATE tasks SET source = 'agent' WHERE source IN ('ai', 'self_development', 'clickup', 'github')");
            DB::statement("ALTER TABLE tasks MODIFY COLUMN source ENUM('manual', 'meeting-parser', 'agent', 'video') DEFAULT 'manual'");
        }
    }
};
