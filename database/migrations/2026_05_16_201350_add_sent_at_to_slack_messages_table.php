<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slack_messages', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('thread_ts');
            $table->index('sent_at');
        });

        // Backfill from message_ts (Unix timestamp as string). Postgres
        // path; SQLite test path uses a different expression.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('UPDATE slack_messages SET sent_at = to_timestamp(cast(message_ts AS numeric)) WHERE message_ts IS NOT NULL AND sent_at IS NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement("UPDATE slack_messages SET sent_at = datetime(cast(message_ts AS REAL), 'unixepoch') WHERE message_ts IS NOT NULL AND sent_at IS NULL");
        }
    }

    public function down(): void
    {
        Schema::table('slack_messages', function (Blueprint $table) {
            $table->dropIndex(['sent_at']);
            $table->dropColumn('sent_at');
        });
    }
};
