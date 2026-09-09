<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY['manual'::text, 'meeting-parser'::text, 'agent'::text, 'video'::text, 'ai'::text, 'self_development'::text, 'clickup'::text, 'github'::text, 'sow_import'::text, 'csv_import'::text, 'excel_import'::text, 'notion'::text, 'slack_modal'::text, 'slack'::text]))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY['manual'::text, 'meeting-parser'::text, 'agent'::text, 'video'::text, 'ai'::text, 'self_development'::text, 'clickup'::text, 'github'::text, 'sow_import'::text, 'csv_import'::text, 'excel_import'::text, 'notion'::text]))");
        }
    }
};
