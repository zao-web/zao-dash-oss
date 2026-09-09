<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->string('revenue_recognition_review_status')->nullable()->after('owner_payment_reviewed_at');
            $table->timestamp('revenue_recognition_reviewed_at')->nullable()->after('revenue_recognition_review_status');
            $table->index('revenue_recognition_review_status');
        });
    }

    public function down(): void
    {
        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->dropIndex(['revenue_recognition_review_status']);
            $table->dropColumn([
                'revenue_recognition_review_status',
                'revenue_recognition_reviewed_at',
            ]);
        });
    }
};
