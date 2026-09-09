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
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('task_id')
                ->constrained()->nullOnDelete();
            $table->string('effort_type')->nullable()->after('client_id');

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropIndex(['client_id']);
            $table->dropColumn(['client_id', 'effort_type']);
        });
    }
};
