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
        Schema::table('agents', function (Blueprint $table) {
            // Path to SKILL.md file (e.g., "bookkeeping/SKILL.md")
            // When set, getSystemPrompt() loads from this file instead of system_prompt column
            $table->string('skill_file')->nullable()->after('system_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('skill_file');
        });
    }
};
