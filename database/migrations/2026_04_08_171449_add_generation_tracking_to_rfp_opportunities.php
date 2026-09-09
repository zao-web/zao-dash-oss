<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfp_opportunities', function (Blueprint $table) {
            $table->string('generation_stage')->nullable()->after('status');
            $table->text('generation_error')->nullable()->after('generation_stage');
            $table->timestamp('generation_started_at')->nullable()->after('generation_error');
        });
    }

    public function down(): void
    {
        Schema::table('rfp_opportunities', function (Blueprint $table) {
            $table->dropColumn(['generation_stage', 'generation_error', 'generation_started_at']);
        });
    }
};
