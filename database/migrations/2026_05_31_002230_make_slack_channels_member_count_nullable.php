<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slack's conversations.list omits num_members for some conversations (notably
 * group DMs and certain shared channels). SyncSlackJob stores that as null on
 * purpose — null means "Slack didn't tell us", distinct from "0 members" — but
 * the column was NOT NULL DEFAULT 0, so the insert threw a constraint violation
 * that aborted the entire workspace sync (and silently froze last_synced_at).
 * Allow null to match the sync code's documented intent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slack_channels', function (Blueprint $table) {
            $table->integer('member_count')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('slack_channels', function (Blueprint $table) {
            $table->integer('member_count')->default(0)->change();
        });
    }
};
