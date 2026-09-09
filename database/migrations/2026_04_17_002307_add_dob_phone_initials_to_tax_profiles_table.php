<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            // Required by Oregon OR-40 (and other state returns) where age alone isn't enough.
            $table->date('taxpayer_dob')->nullable()->after('age');
            $table->date('spouse_dob')->nullable()->after('spouse_age');
            $table->string('taxpayer_middle_initial', 5)->nullable()->after('taxpayer_first_name');
            $table->string('spouse_middle_initial', 5)->nullable()->after('spouse_name');
            $table->string('taxpayer_phone', 32)->nullable()->after('zip');
        });
    }

    public function down(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'taxpayer_dob',
                'spouse_dob',
                'taxpayer_middle_initial',
                'spouse_middle_initial',
                'taxpayer_phone',
            ]);
        });
    }
};
