<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make harvest_client_id nullable to fix sync errors.
     * Original schema had NOT NULL, but sync writes to client_harvest_id instead.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite doesn't support ALTER COLUMN, recreate table via Laravel
            Schema::table('harvest_invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('harvest_client_id')->nullable()->change();
            });
        } else {
            // PostgreSQL/MySQL
            DB::statement('ALTER TABLE harvest_invoices ALTER COLUMN harvest_client_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        DB::statement('UPDATE harvest_invoices SET harvest_client_id = 0 WHERE harvest_client_id IS NULL');

        if ($driver === 'sqlite') {
            Schema::table('harvest_invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('harvest_client_id')->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE harvest_invoices ALTER COLUMN harvest_client_id SET NOT NULL');
        }
    }
};
