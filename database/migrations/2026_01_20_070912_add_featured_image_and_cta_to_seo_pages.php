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
            // Featured image fields
            $table->unsignedBigInteger('featured_image_wordpress_id')->nullable()->after('wordpress_post_id');
            $table->string('featured_image_url')->nullable()->after('featured_image_wordpress_id');
            $table->string('featured_image_source')->nullable()->after('featured_image_url'); // 'gemini', 'unsplash', 'manual'
            $table->text('featured_image_prompt')->nullable()->after('featured_image_source');
            $table->string('featured_image_alt')->nullable()->after('featured_image_prompt');

            // CTA fields
            $table->string('cta_type')->nullable()->after('featured_image_alt');
            $table->text('cta_text')->nullable()->after('cta_type');
            $table->string('cta_url')->nullable()->after('cta_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropColumn([
                'featured_image_wordpress_id',
                'featured_image_url',
                'featured_image_source',
                'featured_image_prompt',
                'featured_image_alt',
                'cta_type',
                'cta_text',
                'cta_url',
            ]);
        });
    }
};
