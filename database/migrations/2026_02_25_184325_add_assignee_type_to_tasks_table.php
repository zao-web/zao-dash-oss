<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('assignee_type')->nullable()->after('assigned_to');
        });

        // Set existing assignments to 'user' type
        DB::table('tasks')->whereNotNull('assigned_to')->update(['assignee_type' => 'user']);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('assignee_type');
        });
    }
};
