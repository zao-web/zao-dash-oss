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
        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('sync_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
