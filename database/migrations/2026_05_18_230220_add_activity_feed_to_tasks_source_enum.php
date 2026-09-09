<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Build the whitelist by unioning every distinct source value already
        // in the tasks table with the values this codebase emits. Earlier
        // deploy attempts hard-coded a stale list and broke on rows whose
        // source was 'email' / 'slack_modal' / 'slack' / 'excel_import' —
        // values added by other migrations or code paths but not surfaced
        // when I copied the prior constraint definition. Introspecting prevents
        // that whole class of bug.
        $codebaseSources = [
            'manual', 'meeting-parser', 'agent', 'video', 'ai',
            'self_development', 'clickup', 'github', 'sow_import',
            'csv_import', 'excel_import', 'notion', 'slack_modal',
            'slack', 'email', 'activity-feed',
        ];

        $existingSources = collect(DB::select('SELECT DISTINCT source FROM tasks WHERE source IS NOT NULL'))
            ->pluck('source')
            ->all();

        $allowed = array_values(array_unique(array_merge($codebaseSources, $existingSources)));
        sort($allowed);

        $literals = implode(', ', array_map(fn ($s) => "'".str_replace("'", "''", $s)."'::text", $allowed));

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY[{$literals}]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_check');
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source::text = ANY (ARRAY['manual'::text, 'meeting-parser'::text, 'agent'::text, 'video'::text, 'ai'::text, 'self_development'::text, 'clickup'::text, 'github'::text, 'sow_import'::text, 'csv_import'::text, 'excel_import'::text, 'notion'::text, 'slack_modal'::text, 'slack'::text, 'email'::text]))");
        }
    }
};
