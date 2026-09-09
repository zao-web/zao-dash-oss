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
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(5)->after('playbook');
            $table->unsignedTinyInteger('proprietary_data_count')->default(0)->after('priority');

            $table->index(['status', 'priority', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropIndex(['status', 'priority', 'created_at']);
            $table->dropColumn(['priority', 'proprietary_data_count']);
        });
    }
};
