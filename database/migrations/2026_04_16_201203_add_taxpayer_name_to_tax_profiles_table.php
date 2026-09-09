<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            // Legal taxpayer name as it appears on the IRS return — separate from
            // the User->name display field, which may be a preferred/nickname.
            $table->string('taxpayer_first_name')->nullable()->after('filing_status');
            $table->string('taxpayer_last_name')->nullable()->after('taxpayer_first_name');
        });
    }

    public function down(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->dropColumn(['taxpayer_first_name', 'taxpayer_last_name']);
        });
    }
};
