<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harvest_invoices', function (Blueprint $table) {
            $table->json('line_items')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('harvest_invoices', function (Blueprint $table) {
            $table->dropColumn('line_items');
        });
    }
};
