<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: Drop the check constraint and recreate with new value
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY['manual'::text, 'meeting-parser'::text, 'agent'::text, 'video'::text]))");
        } elseif ($driver === 'mysql') {
            // MySQL: Modify the enum column
            DB::statement("ALTER TABLE tasks MODIFY COLUMN source ENUM('manual', 'meeting-parser', 'agent', 'video') DEFAULT 'manual'");
        }
        // SQLite doesn't enforce enums - the column is just TEXT, so no migration needed
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("UPDATE tasks SET source = 'agent' WHERE source = 'video'");
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY['manual'::text, 'meeting-parser'::text, 'agent'::text]))");
        } elseif ($driver === 'mysql') {
            DB::statement("UPDATE tasks SET source = 'agent' WHERE source = 'video'");
            DB::statement("ALTER TABLE tasks MODIFY COLUMN source ENUM('manual', 'meeting-parser', 'agent') DEFAULT 'manual'");
        }
        // SQLite: no-op
    }
};
