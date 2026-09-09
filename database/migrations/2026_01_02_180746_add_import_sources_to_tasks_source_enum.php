<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY['manual'::text, 'meeting-parser'::text, 'agent'::text, 'video'::text, 'ai'::text, 'self_development'::text, 'clickup'::text, 'github'::text, 'sow_import'::text, 'csv_import'::text, 'notion'::text]))");
        } elseif (DB::getDriverName() === 'sqlite') {
            // SQLite: Recreate the table without the restrictive CHECK constraint
            // since the original enum created a CHECK constraint
            DB::statement('PRAGMA writable_schema = ON');
            DB::statement("UPDATE sqlite_master SET sql = REPLACE(sql, 'check (\"source\" in (''manual'', ''meeting-parser'', ''agent''))', '') WHERE type = 'table' AND name = 'tasks'");
            DB::statement('PRAGMA writable_schema = OFF');
            DB::statement('PRAGMA integrity_check');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY['manual'::text, 'meeting-parser'::text, 'agent'::text, 'video'::text, 'ai'::text, 'self_development'::text, 'clickup'::text, 'github'::text]))");
        }
    }
};
