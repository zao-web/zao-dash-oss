<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfp_opportunities', function (Blueprint $table) {
            $table->json('budget_line_items')->nullable()->after('budget_max');
        });
    }

    public function down(): void
    {
        Schema::table('rfp_opportunities', function (Blueprint $table) {
            $table->dropColumn('budget_line_items');
        });
    }
};
