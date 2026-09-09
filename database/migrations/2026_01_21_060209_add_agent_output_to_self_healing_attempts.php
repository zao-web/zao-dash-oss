<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('self_healing_attempts', function (Blueprint $table) {
            $table->longText('agent_output')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('self_healing_attempts', function (Blueprint $table) {
            $table->dropColumn('agent_output');
        });
    }
};
