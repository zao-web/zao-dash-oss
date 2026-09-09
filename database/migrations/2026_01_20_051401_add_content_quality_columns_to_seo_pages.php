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
        Schema::table('seo_pages', function (Blueprint $table) {
            // Content quality tracking (Phase 2)
            $table->unsignedInteger('word_count')->nullable()->after('meta_description');
            $table->unsignedInteger('humanization_score')->nullable()->after('word_count');
            $table->boolean('schema_valid')->default(false)->after('humanization_score');
            $table->unsignedInteger('internal_links_count')->default(0)->after('schema_valid');
            $table->unsignedInteger('external_links_count')->default(0)->after('internal_links_count');

            // Generation timing tracking
            $table->timestamp('generation_started_at')->nullable()->after('published_at');
            $table->timestamp('generation_completed_at')->nullable()->after('generation_started_at');

            // Indexes for content quality queries
            $table->index(['status', 'humanization_score']);
            $table->index('word_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropIndex(['status', 'humanization_score']);
            $table->dropIndex(['word_count']);
            $table->dropColumn([
                'word_count',
                'humanization_score',
                'schema_valid',
                'internal_links_count',
                'external_links_count',
                'generation_started_at',
                'generation_completed_at',
            ]);
        });
    }
};
