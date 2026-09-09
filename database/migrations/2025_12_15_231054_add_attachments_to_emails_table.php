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
        Schema::table('emails', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('body_html');
            $table->foreignId('contractor_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->boolean('has_invoice')->default(false)->after('is_processed');
            $table->foreignId('detected_invoice_id')->nullable()->after('has_invoice');
            $table->index('contractor_id');
            $table->index('has_invoice');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropForeign(['contractor_id']);
            $table->dropIndex(['contractor_id']);
            $table->dropIndex(['has_invoice']);
            $table->dropColumn(['attachments', 'contractor_id', 'has_invoice', 'detected_invoice_id']);
        });
    }
};
