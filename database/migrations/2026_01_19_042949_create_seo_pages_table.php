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
        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->string('page_url')->unique();
            $table->string('target_keyword');
            $table->string('page_type')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->unsignedBigInteger('wordpress_post_id')->nullable();
            $table->boolean('generated_by_agent')->default(false);
            $table->timestamp('published_at')->nullable();

            // Performance metrics (30-day rolling)
            $table->unsignedInteger('impressions_30d')->default(0);
            $table->unsignedInteger('clicks_30d')->default(0);
            $table->decimal('avg_position_30d', 5, 2)->nullable();
            $table->decimal('ctr_30d', 5, 2)->default(0);

            // Conversion metrics
            $table->unsignedInteger('total_leads')->default(0);
            $table->unsignedInteger('total_projects')->default(0);
            $table->decimal('total_revenue', 10, 2)->default(0);
            $table->decimal('conversion_rate', 5, 2)->default(0);

            // Optimization tracking
            $table->timestamp('last_optimized_at')->nullable();
            $table->unsignedInteger('optimization_count')->default(0);
            $table->string('status')->default('active'); // active, draft, archived

            $table->timestamps();

            // Indexes for performance queries
            $table->index(['status', 'impressions_30d', 'total_revenue']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
    }
};
