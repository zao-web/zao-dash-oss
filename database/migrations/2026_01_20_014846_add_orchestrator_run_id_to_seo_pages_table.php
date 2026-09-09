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
            $table->unsignedBigInteger('orchestrator_run_id')->nullable()->after('wordpress_post_id');
            $table->foreign('orchestrator_run_id')->references('id')->on('agent_runs')->onDelete('set null');
            $table->index('orchestrator_run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropForeign(['orchestrator_run_id']);
            $table->dropIndex(['orchestrator_run_id']);
            $table->dropColumn('orchestrator_run_id');
        });
    }
};
