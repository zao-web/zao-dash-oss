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
        Schema::table('cash_waterfall_allocations', function (Blueprint $table) {
            $table->decimal('sinking_funds', 14, 2)->default(0)->after('debt_payments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_waterfall_allocations', function (Blueprint $table) {
            $table->dropColumn('sinking_funds');
        });
    }
};
