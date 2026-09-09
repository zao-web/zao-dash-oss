<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WordPress site connections
        Schema::create('wordpress_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 500);
            $table->string('rest_url', 500)->nullable();
            $table->string('username');
            $table->text('application_password');
            $table->boolean('mcp_enabled')->default(true);
            $table->timestamp('last_connected_at')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique('url');
        });

        // Synced WordPress posts
        Schema::create('wordpress_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('wp_post_id');
            $table->string('title', 500);
            $table->string('slug');
            $table->string('status', 50);
            $table->string('type', 50)->default('post');
            $table->text('excerpt')->nullable();
            $table->text('content_preview')->nullable();
            $table->string('author_name')->nullable();
            $table->json('categories')->nullable();
            $table->json('tags')->nullable();
            $table->string('featured_image_url', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->string('url', 500);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('wordpress_site_id');
            $table->index('status');
            $table->index('type');
            $table->unique(['wordpress_site_id', 'wp_post_id']);
        });

        // AI-generated content suggestions
        Schema::create('content_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_site_id')->constrained()->cascadeOnDelete();
            $table->string('title', 500);
            $table->text('description');
            $table->string('content_type', 50)->default('blog_post');
            $table->string('source_type', 50);
            $table->json('source_data')->nullable();
            $table->text('suggested_outline')->nullable();
            $table->string('status', 50)->default('pending');
            $table->longText('generated_content')->nullable();
            $table->bigInteger('wp_post_id')->nullable();
            $table->string('priority', 20)->default('medium');
            $table->timestamps();

            $table->index('wordpress_site_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_suggestions');
        Schema::dropIfExists('wordpress_posts');
        Schema::dropIfExists('wordpress_sites');
    }
};
