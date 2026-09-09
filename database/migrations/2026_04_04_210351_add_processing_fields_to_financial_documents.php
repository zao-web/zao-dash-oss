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
        Schema::table('financial_documents', function (Blueprint $table) {
            $table->string('processing_status')->default('pending')->after('mime_type');
            $table->text('processing_notes')->nullable()->after('processing_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('financial_documents', function (Blueprint $table) {
            $table->dropColumn(['processing_status', 'processing_notes']);
        });
    }
};
