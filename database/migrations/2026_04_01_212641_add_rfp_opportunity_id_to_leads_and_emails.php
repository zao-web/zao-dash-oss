<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('rfp_opportunity_id')->nullable()->after('seo_page_id')->constrained()->nullOnDelete();
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->foreignId('rfp_opportunity_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rfp_opportunity_id');
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rfp_opportunity_id');
        });
    }
};
