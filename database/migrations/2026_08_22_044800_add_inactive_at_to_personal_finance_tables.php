<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_accounts', function (Blueprint $table) {
            $table->timestamp('inactive_at')->nullable()->after('is_closed');
        });

        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->timestamp('inactive_at')->nullable()->after('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_accounts', function (Blueprint $table) {
            $table->dropColumn('inactive_at');
        });

        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->dropColumn('inactive_at');
        });
    }
};
