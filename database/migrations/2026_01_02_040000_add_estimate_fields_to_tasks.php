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
        Schema::table('tasks', function (Blueprint $table) {
            // Time estimation fields
            $table->decimal('estimated_hours', 8, 2)->nullable()->after('due_date');
            $table->decimal('actual_hours', 8, 2)->nullable()->after('estimated_hours');
            $table->timestamp('estimate_approved_at')->nullable()->after('actual_hours');
            $table->string('estimate_source')->nullable()->after('estimate_approved_at'); // 'manual', 'agent', 'auto'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_hours',
                'actual_hours',
                'estimate_approved_at',
                'estimate_source',
            ]);
        });
    }
};
