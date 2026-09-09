<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('execution_mode')->default('auto')->after('model');
            // auto = SDK for agents with tools, CLI for simple generation
            // sdk = always use API-based execution
            // cli = always use CLI execution
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('execution_mode');
        });
    }
};
