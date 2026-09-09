<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->string('owner_payment_classification')->nullable()->after('notes');
            $table->timestamp('owner_payment_reviewed_at')->nullable()->after('owner_payment_classification');

            $table->index('owner_payment_classification');
        });
    }

    public function down(): void
    {
        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->dropIndex(['owner_payment_classification']);
            $table->dropColumn([
                'owner_payment_classification',
                'owner_payment_reviewed_at',
            ]);
        });
    }
};
