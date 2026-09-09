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
        Schema::table('rfp_proposals', function (Blueprint $table) {
            $table->json('critique_findings')->nullable()->after('review_notes');
            $table->string('critique_summary')->nullable()->after('critique_findings');
            $table->integer('critique_blocker_count')->default(0)->after('critique_summary');
            $table->boolean('critique_revised')->default(false)->after('critique_blocker_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfp_proposals', function (Blueprint $table) {
            $table->dropColumn(['critique_findings', 'critique_summary', 'critique_blocker_count', 'critique_revised']);
        });
    }
};
