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
        Schema::table('slack_channels', function (Blueprint $table) {
            if (! Schema::hasColumn('slack_channels', 'message_count')) {
                $table->integer('message_count')->default(0)->after('member_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slack_channels', function (Blueprint $table) {
            if (Schema::hasColumn('slack_channels', 'message_count')) {
                $table->dropColumn('message_count');
            }
        });
    }
};
