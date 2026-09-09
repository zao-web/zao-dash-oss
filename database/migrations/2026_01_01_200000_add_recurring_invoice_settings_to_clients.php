<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Recurring invoice settings for retainer clients
            $table->boolean('recurring_invoice_enabled')->default(false)->after('billing_email');
            $table->decimal('recurring_invoice_amount', 12, 2)->nullable()->after('recurring_invoice_enabled');
            $table->unsignedTinyInteger('recurring_invoice_day')->default(1)->after('recurring_invoice_amount'); // 1-28
            $table->boolean('recurring_invoice_auto_send')->default(false)->after('recurring_invoice_day');
            $table->string('recurring_invoice_description')->nullable()->after('recurring_invoice_auto_send');
            $table->foreignId('recurring_invoice_project_id')->nullable()->after('recurring_invoice_description')
                ->constrained('projects')->nullOnDelete();
            $table->date('recurring_invoice_last_generated')->nullable()->after('recurring_invoice_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['recurring_invoice_project_id']);
            $table->dropColumn([
                'recurring_invoice_enabled',
                'recurring_invoice_amount',
                'recurring_invoice_day',
                'recurring_invoice_auto_send',
                'recurring_invoice_description',
                'recurring_invoice_project_id',
                'recurring_invoice_last_generated',
            ]);
        });
    }
};
