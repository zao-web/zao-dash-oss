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
            $table->string('slack_notification_ts', 50)->nullable()->after('reviewed_at');
            $table->string('slack_channel_id', 50)->nullable()->after('slack_notification_ts');
        });
    }

    public function down(): void
    {
        Schema::table('rfp_proposals', function (Blueprint $table) {
            $table->dropColumn(['slack_notification_ts', 'slack_channel_id']);
        });
    }
};
