<?php

use App\Models\Client;
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
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('recurring_invoice_in_advance')
                ->default(false)
                ->after('recurring_invoice_day')
                ->comment('Bill the upcoming month: invoice issued on the billing day is labelled for the next month so payment lands near the 1st.');
        });

        // Windham Mountain Club bills on the 21st specifically to send next
        // month's retainer early (per Casey's request to pay near the 1st).
        Client::query()
            ->where('id', 3)
            ->where('name', 'like', 'Windham%')
            ->update(['recurring_invoice_in_advance' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('recurring_invoice_in_advance');
        });
    }
};
