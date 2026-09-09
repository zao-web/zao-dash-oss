<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->boolean('is_business_expense')->default(false)->after('is_tax_deductible');
        });
    }

    public function down(): void
    {
        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->dropColumn('is_business_expense');
        });
    }
};
