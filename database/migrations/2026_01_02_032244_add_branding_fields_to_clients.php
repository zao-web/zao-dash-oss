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
        Schema::table('clients', function (Blueprint $table) {
            // Branding fields for personalized invoices
            $table->string('brand_color', 7)->nullable(); // Hex color e.g. #FF5733
            $table->string('logo_url')->nullable(); // URL to client logo
            $table->text('invoice_footer')->nullable(); // Custom footer message on invoices
            $table->text('invoice_notes')->nullable(); // Default notes for invoices
            $table->decimal('default_tax_rate', 5, 2)->default(0); // Default tax rate for this client
            $table->string('payment_terms')->nullable(); // Default payment terms (Net 30, etc.)
            $table->string('billing_email')->nullable(); // Separate billing contact email
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'brand_color',
                'logo_url',
                'invoice_footer',
                'invoice_notes',
                'default_tax_rate',
                'payment_terms',
                'billing_email',
            ]);
        });
    }
};
