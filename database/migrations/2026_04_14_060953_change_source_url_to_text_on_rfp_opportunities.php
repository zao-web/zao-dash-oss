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
        Schema::table('rfp_opportunities', function (Blueprint $table) {
            $table->text('source_url')->nullable()->change();
            $table->text('full_document_url')->nullable()->change();
            $table->text('submission_portal_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfp_opportunities', function (Blueprint $table) {
            $table->string('source_url')->nullable()->change();
            $table->string('full_document_url')->nullable()->change();
            $table->string('submission_portal_url')->nullable()->change();
        });
    }
};
