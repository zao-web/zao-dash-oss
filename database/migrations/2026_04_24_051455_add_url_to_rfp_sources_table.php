<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfp_sources', function (Blueprint $table) {
            $table->string('url')->nullable()->after('type');
            $table->string('slug')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rfp_sources', function (Blueprint $table) {
            $table->dropColumn('url');
            $table->string('slug')->nullable(false)->change();
        });
    }
};
