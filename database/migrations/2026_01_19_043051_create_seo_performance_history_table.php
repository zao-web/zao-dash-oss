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
        Schema::create('seo_performance_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_page_id')->constrained()->onDelete('cascade');
            $table->foreignId('seo_keyword_id')->nullable()->constrained()->onDelete('set null');
            $table->date('snapshot_date');

            // Search Console metrics
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('avg_position', 5, 2)->nullable();
            $table->decimal('ctr', 5, 2)->default(0);

            // GA4 metrics
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('bounce_rate', 5, 2)->nullable();
            $table->unsignedInteger('avg_session_duration')->nullable(); // in seconds

            $table->timestamps();

            // Ensure one snapshot per page per date
            $table->unique(['seo_page_id', 'snapshot_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_performance_history');
    }
};
