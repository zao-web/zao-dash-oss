<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('pending_review')->default(false)->after('reminders_disabled');
            $table->text('pending_review_reason')->nullable()->after('pending_review');
            $table->foreignId('retainer_period_id')->nullable()->after('pending_review_reason')
                ->constrained('retainer_periods')->nullOnDelete();

            $table->index(['pending_review']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['retainer_period_id']);
            $table->dropColumn(['pending_review', 'pending_review_reason', 'retainer_period_id']);
        });
    }
};
