<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('slack_channels')
            ->where('monitoring_enabled', true)
            ->whereNull('client_id')
            ->where('is_shared', false)
            ->where('is_dm', false)
            ->update([
                'monitoring_enabled' => false,
                'is_monitored' => false,
            ]);

        Schema::table('slack_channels', function (Blueprint $table) {
            $table->boolean('monitoring_enabled')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('slack_channels', function (Blueprint $table) {
            $table->boolean('monitoring_enabled')->default(true)->change();
        });
    }
};
