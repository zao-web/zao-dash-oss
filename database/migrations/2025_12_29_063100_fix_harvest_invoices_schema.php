<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harvest_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('harvest_invoices', 'client_name')) {
                $table->string('client_name')->nullable()->after('harvest_id');
            }
            if (! Schema::hasColumn('harvest_invoices', 'client_harvest_id')) {
                $table->bigInteger('client_harvest_id')->nullable()->after('client_name');
            }
            if (! Schema::hasColumn('harvest_invoices', 'purchase_order')) {
                $table->string('purchase_order')->nullable()->after('number');
            }
            if (! Schema::hasColumn('harvest_invoices', 'notes')) {
                $table->text('notes')->nullable()->after('subject');
            }
            if (! Schema::hasColumn('harvest_invoices', 'tax')) {
                $table->decimal('tax', 5, 2)->default(0)->after('due_amount');
            }
            if (! Schema::hasColumn('harvest_invoices', 'tax_amount')) {
                $table->decimal('tax_amount', 12, 2)->default(0)->after('tax');
            }
            if (! Schema::hasColumn('harvest_invoices', 'tax2')) {
                $table->decimal('tax2', 5, 2)->default(0)->after('tax_amount');
            }
            if (! Schema::hasColumn('harvest_invoices', 'tax2_amount')) {
                $table->decimal('tax2_amount', 12, 2)->default(0)->after('tax2');
            }
            if (! Schema::hasColumn('harvest_invoices', 'discount')) {
                $table->decimal('discount', 5, 2)->default(0)->after('tax2_amount');
            }
            if (! Schema::hasColumn('harvest_invoices', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('discount');
            }
            if (! Schema::hasColumn('harvest_invoices', 'period_start')) {
                $table->date('period_start')->nullable()->after('currency');
            }
            if (! Schema::hasColumn('harvest_invoices', 'period_end')) {
                $table->date('period_end')->nullable()->after('period_start');
            }
            if (! Schema::hasColumn('harvest_invoices', 'payment_term')) {
                $table->string('payment_term')->nullable()->after('due_date');
            }
            if (! Schema::hasColumn('harvest_invoices', 'paid_date')) {
                $table->date('paid_date')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('harvest_invoices', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('paid_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('harvest_invoices', function (Blueprint $table) {
            $columns = [
                'client_name',
                'client_harvest_id',
                'purchase_order',
                'notes',
                'tax',
                'tax_amount',
                'tax2',
                'tax2_amount',
                'discount',
                'discount_amount',
                'period_start',
                'period_end',
                'payment_term',
                'paid_date',
                'closed_at',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('harvest_invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
