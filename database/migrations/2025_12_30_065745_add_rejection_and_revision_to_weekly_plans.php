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
        Schema::table('weekly_plans', function (Blueprint $table) {
            // Rejection tracking
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Revision tracking
            $table->json('revision_history')->nullable(); // Array of previous revisions with feedback
            $table->text('revision_feedback')->nullable(); // Current revision feedback
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_plans', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn([
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'revision_history',
                'revision_feedback',
            ]);
        });
    }
};
