<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('qbo_invoice_id')->nullable()->after('paypal_invoice_id');
            $table->timestamp('qbo_synced_at')->nullable()->after('qbo_invoice_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('qbo_payment_id')->nullable()->after('metadata');
            $table->timestamp('qbo_synced_at')->nullable()->after('qbo_payment_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('qbo_customer_id')->nullable()->after('default_tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['qbo_invoice_id', 'qbo_synced_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['qbo_payment_id', 'qbo_synced_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('qbo_customer_id');
        });
    }
};
