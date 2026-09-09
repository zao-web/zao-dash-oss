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
        Schema::table('client_reports', function (Blueprint $table) {
            // Add all potentially missing columns
            if (! Schema::hasColumn('client_reports', 'total_hours')) {
                $table->decimal('total_hours', 8, 2)->default(0)->after('metrics');
            }
            if (! Schema::hasColumn('client_reports', 'pdf_path')) {
                $table->string('pdf_path')->nullable()->after('meetings_held');
            }
            if (! Schema::hasColumn('client_reports', 'pdf_disk')) {
                $table->string('pdf_disk')->default('local')->after('pdf_path');
            }
            if (! Schema::hasColumn('client_reports', 'sent_to')) {
                $table->json('sent_to')->nullable()->after('pdf_disk');
            }
            if (! Schema::hasColumn('client_reports', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('sent_to');
            }
            if (! Schema::hasColumn('client_reports', 'opened_at')) {
                $table->timestamp('opened_at')->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('client_reports', 'opens_count')) {
                $table->integer('opens_count')->default(0)->after('opened_at');
            }
            if (! Schema::hasColumn('client_reports', 'generated_by')) {
                $table->foreignId('generated_by')->nullable()->after('opens_count');
            }
            if (! Schema::hasColumn('client_reports', 'error_message')) {
                $table->text('error_message')->nullable()->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop columns on rollback
    }
};
