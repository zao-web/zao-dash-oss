<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->decimal('oregon_kicker_credit', 12, 2)->nullable()->after('prior_year_oregon_overpayment_applied');
        });
    }

    public function down(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table): void {
            $table->dropColumn('oregon_kicker_credit');
        });
    }
};
