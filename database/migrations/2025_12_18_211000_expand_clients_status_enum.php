<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expand clients status to include 'archived' and 'churned'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: Convert enum to varchar to allow more values
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_status_check');
            DB::statement('ALTER TABLE clients ALTER COLUMN status TYPE varchar(20)');
            DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('active', 'inactive', 'prospect', 'archived', 'churned'))");
        }
    }

    public function down(): void
    {
        // Revert to original constraint (would fail if archived/churned records exist)
    }
};
