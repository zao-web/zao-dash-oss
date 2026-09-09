<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-client report configuration
        if (! Schema::hasTable('client_report_settings')) {
            Schema::create('client_report_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_enabled')->default(true);
                $table->enum('frequency', ['monthly', 'quarterly', 'weekly'])->default('monthly');
                $table->integer('send_day')->default(1); // Day of month/week to send
                $table->json('recipients')->nullable(); // Email addresses
                $table->boolean('include_time_breakdown')->default(true);
                $table->boolean('include_github_activity')->default(true);
                $table->boolean('include_tasks_completed')->default(true);
                $table->boolean('include_financials')->default(false);
                $table->boolean('include_upcoming')->default(true);
                $table->json('custom_branding')->nullable(); // logo_url, primary_color, accent_color
                $table->string('template')->default('default'); // Template name
                $table->timestamps();

                $table->unique('client_id');
            });
        }

        // Generated reports
        if (Schema::hasTable('client_reports')) {
            return; // Already exists
        }

        Schema::create('client_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('report_type', ['monthly', 'quarterly', 'weekly', 'custom'])->default('monthly');

            // Aggregated data snapshot (for historical reference)
            $table->json('data_snapshot')->nullable();

            // AI-generated content
            $table->text('executive_summary')->nullable();
            $table->json('highlights')->nullable(); // Key achievements
            $table->json('metrics')->nullable(); // Hero stats

            // Time breakdown
            $table->decimal('total_hours', 8, 2)->default(0);
            $table->json('hours_by_category')->nullable();
            $table->json('hours_by_project')->nullable();

            // Activity
            $table->integer('tasks_completed')->default(0);
            $table->integer('prs_merged')->default(0);
            $table->integer('issues_closed')->default(0);
            $table->integer('meetings_held')->default(0);

            // PDF storage
            $table->string('pdf_path')->nullable();
            $table->string('pdf_disk')->default('local');

            // Delivery tracking
            $table->json('sent_to')->nullable(); // Email addresses sent to
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable(); // Email tracking
            $table->integer('opens_count')->default(0);

            // Generation metadata
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['draft', 'generated', 'sent', 'failed'])->default('draft');
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'period_start']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reports');
        Schema::dropIfExists('client_report_settings');
    }
};
